<?php

namespace App\Services\Payments;

use App\Models\Charge;
use App\Models\GatewayEvent;
use App\Models\PaymentGatewayConfig;
use App\Support\BusinessDate;
use App\Support\Dinheiro;
use App\Support\Gateway\EventoDeCobranca;
use App\Support\TenantAtual;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Conciliação entre o que o gateway de pagamento confirmou (eventos de
 * webhook, Plano 19 Task 19.4) e o que o sistema baixou (parcelas quitadas,
 * Plano 18) - Plano 19, Task 19.6.
 *
 * ## As três divergências, e por que uma delas não é falha
 *
 * - **Evento sem baixa**: o gateway avisou que pagou, mas a `Charge`
 *   correspondente não está `paga`. É falha de verdade - webhook que chegou
 *   e não completou o processamento (rede caiu no meio, bug, cobrança que o
 *   evento referencia não existe mais) - e por isso entra com a ação de
 *   reprocessar.
 * - **Baixa sem evento**: a parcela foi quitada e a `Charge` está `paga`,
 *   sem nenhum evento do gateway por trás. **Não é erro**: é o caso comum de
 *   recebimento em dinheiro ou baixa manual pela tela de contas a receber,
 *   sem boleto nem Pix envolvido. Aparece no relatório como informação, para
 *   quem concilia enxergar de onde veio cada centavo, nunca como algo a
 *   corrigir.
 * - **Valor divergente**: o gateway e o sistema concordam que a cobrança foi
 *   paga, mas por um valor diferente - sinal de arredondamento, de
 *   pagamento parcial aceito pelo provedor, ou de erro de configuração.
 *
 * ## Correlação entre evento e baixa
 *
 * `GatewayEvent` grava só o `payload` cru do webhook (Task 19.4); o
 * identificador da cobrança no provedor (`chargeIdNoGateway`) e o valor pago
 * só existem depois de `GatewayDeCobranca::interpretarWebhook()` traduzir
 * esse payload. Esta classe refaz essa tradução aqui (é pura, sem chamada de
 * rede - só interpreta o JSON já recebido), e casa o resultado com
 * `Charge.gateway_charge_id`, o mesmo campo que
 * `App\Services\Payments\ProcessadorDeEventoDeCobranca::cobrancaDoEvento()`
 * usa para aplicar o evento no domínio.
 *
 * A implementação de `GatewayDeCobranca` usada para interpretar cada evento
 * é resolvida pelo `gateway` gravado no próprio `GatewayEvent`, com uma
 * `PaymentGatewayConfig` transitória (nunca persistida) - não pela
 * configuração ativa hoje do tenant, que pode ter sido trocada depois de o
 * evento ter chegado.
 *
 * ## Escopo por empresa
 *
 * `Charge` usa `BelongsToCompany` e por isso já filtra pelo tenant corrente
 * sozinha. `GatewayEvent` **não** usa (ver o cabeçalho do model: é a mesma
 * tabela do evento de assinatura da plataforma, sem tenant), e por isso todo
 * acesso a ela aqui carrega `where('company_id', ...)` explícito, com o
 * tenant resolvido de `App\Support\TenantAtual::id()` - este Service só roda
 * dentro de uma requisição autenticada do tenant, nunca em rotina que
 * percorre várias empresas.
 */
class ConciliacaoService
{
    public function __construct(
        private readonly ResolvedorDeGateway $resolvedorDeGateway,
    ) {}

    /**
     * @return array{
     *     periodo: array{de: string, ate: string},
     *     eventos_sem_baixa: array<int, array<string, mixed>>,
     *     baixas_sem_evento: array<int, array<string, mixed>>,
     *     valores_divergentes: array<int, array<string, mixed>>
     * }
     */
    public function relatorio(string $de, string $ate): array
    {
        $empresaId = TenantAtual::id();

        if ($empresaId === null) {
            throw new RuntimeException(
                'ConciliacaoService::relatorio() precisa de uma empresa corrente resolvida (TenantAtual::id()).'
            );
        }

        $inicio = BusinessDate::diaDe($de);
        $fim = BusinessDate::diaDe($ate);

        if ($inicio === null || $fim === null) {
            throw new RuntimeException('Período de conciliação inválido: informe "de" e "ate" como datas válidas.');
        }

        // Todos os eventos de pagamento confirmado já registrados para esta
        // empresa, sem recorte de período nesta consulta: uma baixa cujo
        // pagamento caiu dentro do período pode ter sido confirmada por um
        // evento de outro dia (webhook atrasado), e restringir esta consulta
        // ao período perderia esse casamento na checagem de "baixa sem
        // evento". O recorte por período entra depois, por linha, usando a
        // data que cada divergência representa de fato (o dia do pagamento).
        $eventosPagos = $this->eventosPagosTraduzidos($empresaId);
        $eventosPorChargeId = $eventosPagos->filter(fn (array $linha) => $linha['charge_id_no_gateway'] !== null)
            ->keyBy('charge_id_no_gateway');

        $todasAsCobrancas = Charge::query()->get()->keyBy('gateway_charge_id');

        return [
            'periodo' => ['de' => $inicio, 'ate' => $fim],
            'eventos_sem_baixa' => $this->eventosSemBaixa($eventosPagos, $todasAsCobrancas, $inicio, $fim),
            'baixas_sem_evento' => $this->baixasSemEvento($todasAsCobrancas, $eventosPorChargeId, $inicio, $fim),
            'valores_divergentes' => $this->valoresDivergentes($eventosPorChargeId, $todasAsCobrancas, $inicio, $fim),
        ];
    }

    // -----------------------------------------------------------------
    // Eventos de pagamento, já traduzidos
    // -----------------------------------------------------------------

    /**
     * @return Collection<int, array{gateway_event_id: int, evento_id: string, gateway: string, charge_id_no_gateway: string|null, valor_pago: string|null, pago_em: string|null, processado_em: string|null, erro: string|null, tentativas: int}>
     */
    private function eventosPagosTraduzidos(int $empresaId): Collection
    {
        return GatewayEvent::query()
            ->where('company_id', $empresaId)
            ->where('tipo', EventoDeCobranca::TIPO_PAGA)
            ->orderBy('created_at')
            ->get()
            ->map(function (GatewayEvent $evento): ?array {
                $eventoDeCobranca = $this->interpretar($evento);

                if ($eventoDeCobranca === null || $eventoDeCobranca->tipo !== EventoDeCobranca::TIPO_PAGA) {
                    return null;
                }

                return [
                    'gateway_event_id' => (int) $evento->getKey(),
                    'evento_id' => $evento->evento_id,
                    'gateway' => $evento->gateway,
                    'charge_id_no_gateway' => $eventoDeCobranca->chargeIdNoGateway,
                    'valor_pago' => $eventoDeCobranca->valorPago,
                    'pago_em' => $eventoDeCobranca->pagoEm?->toDateString() ?? BusinessDate::diaDe($evento->created_at),
                    'processado_em' => $evento->processado_em?->toIso8601String(),
                    'erro' => $evento->erro,
                    'tentativas' => (int) $evento->tentativas,
                    'pode_reprocessar' => $evento->processado_em === null,
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * Traduz o payload cru para o vocabulário do domínio, com a implementação
     * do gateway gravado no próprio evento. Nunca lança: payload que não dá
     * para interpretar (gateway sem implementação registrada, por exemplo)
     * vira `null`, e o evento simplesmente não entra no relatório em vez de
     * derrubar a tela inteira.
     */
    private function interpretar(GatewayEvent $evento): ?EventoDeCobranca
    {
        try {
            $configuracaoTransitoria = new PaymentGatewayConfig(['gateway' => $evento->gateway]);

            return $this->resolvedorDeGateway->paraConfiguracao($configuracaoTransitoria)
                ->interpretarWebhook((array) $evento->payload);
        } catch (RuntimeException) {
            return null;
        }
    }

    // -----------------------------------------------------------------
    // As três divergências
    // -----------------------------------------------------------------

    /**
     * @param  Collection<int, array<string, mixed>>  $eventosPagos
     * @param  Collection<string, Charge>  $todasAsCobrancas
     * @return array<int, array<string, mixed>>
     */
    private function eventosSemBaixa(Collection $eventosPagos, Collection $todasAsCobrancas, string $inicio, string $fim): array
    {
        return $eventosPagos
            ->filter(fn (array $linha) => $this->dentroDoPeriodo($linha['pago_em'], $inicio, $fim))
            ->filter(function (array $linha) use ($todasAsCobrancas) {
                $cobranca = $linha['charge_id_no_gateway'] !== null ? $todasAsCobrancas->get($linha['charge_id_no_gateway']) : null;

                return ! $cobranca instanceof Charge || $cobranca->situacao !== 'paga';
            })
            ->map(function (array $linha) use ($todasAsCobrancas) {
                $cobranca = $linha['charge_id_no_gateway'] !== null ? $todasAsCobrancas->get($linha['charge_id_no_gateway']) : null;

                return [
                    'gateway_event_id' => $linha['gateway_event_id'],
                    'evento_id' => $linha['evento_id'],
                    'gateway' => $linha['gateway'],
                    'charge_id_no_gateway' => $linha['charge_id_no_gateway'],
                    'charge_id' => $cobranca?->getKey(),
                    'valor_pago_no_gateway' => $linha['valor_pago'],
                    'pago_em' => $linha['pago_em'],
                    'processado_em' => $linha['processado_em'],
                    'erro' => $linha['erro'],
                    'tentativas' => $linha['tentativas'],
                    'pode_reprocessar' => $linha['pode_reprocessar'],
                    'motivo' => $cobranca instanceof Charge
                        ? sprintf('A cobrança #%d está com situação "%s", não "paga".', $cobranca->getKey(), $cobranca->situacao)
                        : 'Nenhuma cobrança desta empresa tem este identificador do gateway.',
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<string, Charge>  $todasAsCobrancas
     * @param  Collection<string, array<string, mixed>>  $eventosPorChargeId
     * @return array<int, array<string, mixed>>
     */
    private function baixasSemEvento(Collection $todasAsCobrancas, Collection $eventosPorChargeId, string $inicio, string $fim): array
    {
        return $todasAsCobrancas
            ->filter(fn (Charge $cobranca) => $cobranca->situacao === 'paga')
            ->filter(fn (Charge $cobranca) => $this->dentroDoPeriodo(BusinessDate::diaDe($cobranca->pago_em), $inicio, $fim))
            ->reject(fn (Charge $cobranca) => $eventosPorChargeId->has((string) $cobranca->gateway_charge_id))
            ->map(fn (Charge $cobranca) => [
                'charge_id' => (int) $cobranca->getKey(),
                'receivable_installment_id' => $cobranca->receivable_installment_id,
                'tipo' => $cobranca->tipo,
                'valor_pago' => $cobranca->valor_pago,
                'pago_em' => BusinessDate::diaDe($cobranca->pago_em),
                'observacao' => 'Baixa sem evento do gateway: recebimento manual (dinheiro, transferência) ou '
                    .'baixa direta na tela de contas a receber. Não é uma falha.',
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $eventosPorChargeId
     * @param  Collection<string, Charge>  $todasAsCobrancas
     * @return array<int, array<string, mixed>>
     */
    private function valoresDivergentes(Collection $eventosPorChargeId, Collection $todasAsCobrancas, string $inicio, string $fim): array
    {
        return $todasAsCobrancas
            ->filter(fn (Charge $cobranca) => $cobranca->situacao === 'paga')
            ->filter(fn (Charge $cobranca) => $this->dentroDoPeriodo(BusinessDate::diaDe($cobranca->pago_em), $inicio, $fim))
            ->map(function (Charge $cobranca) use ($eventosPorChargeId) {
                $evento = $eventosPorChargeId->get((string) $cobranca->gateway_charge_id);

                if ($evento === null || $evento['valor_pago'] === null) {
                    return null;
                }

                $valorNoGateway = Dinheiro::centavos($evento['valor_pago']);
                $valorNoSistema = Dinheiro::centavos($cobranca->valor_pago);

                if ($valorNoGateway === $valorNoSistema) {
                    return null;
                }

                return [
                    'charge_id' => (int) $cobranca->getKey(),
                    'gateway_charge_id' => $cobranca->gateway_charge_id,
                    'valor_pago_no_gateway' => Dinheiro::paraDecimal($valorNoGateway),
                    'valor_baixado_no_sistema' => Dinheiro::paraDecimal($valorNoSistema),
                    'diferenca' => Dinheiro::paraDecimal($valorNoGateway - $valorNoSistema),
                    'pago_em' => BusinessDate::diaDe($cobranca->pago_em),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function dentroDoPeriodo(?string $dia, string $inicio, string $fim): bool
    {
        return $dia !== null && $dia >= $inicio && $dia <= $fim;
    }
}
