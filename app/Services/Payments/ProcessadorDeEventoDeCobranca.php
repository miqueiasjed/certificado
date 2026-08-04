<?php

namespace App\Services\Payments;

use App\Models\Charge;
use App\Models\GatewayEvent;
use App\Models\PaymentGatewayConfig;
use App\Models\ReceivableInstallment;
use App\Models\User;
use App\Services\SettlementService;
use App\Support\BusinessDate;
use App\Support\Gateway\EventoDeCobranca;
use App\Support\TenantAtual;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Registro e processamento dos eventos que o gateway de cobrança de um tenant
 * manda por webhook (Plano 19, Task 19.4): a confirmação de que o cliente
 * final pagou o boleto ou o Pix emitido a partir de uma parcela a receber.
 *
 * Irmão direto de `App\Services\GatewayEventService` (Plano 7), com a mesma
 * disciplina de idempotência e o mesmo "nunca lança" — e uma diferença que é
 * o motivo de existir separado: aqui a `Charge` só pode ser localizada
 * **dentro do tenant do token da URL**. Um webhook forjado (ou um
 * `gateway_charge_id` que por acaso colide entre dois tenants no mesmo
 * gateway) não pode baixar o título de uma empresa que não é a dona do
 * token. Essa garantia não é uma checagem manual de `company_id`: é o escopo
 * global de `Charge` (`BelongsToCompany`), aplicado porque `processar()`
 * executa inteiro dentro de `TenantAtual::comTenant($configuracao->company_id, ...)`.
 * Enquanto esse escopo estiver ligado, toda consulta a `Charge`/`ReceivableInstallment`/
 * `User` deste arquivo só enxerga o tenant do token — inclusive se alguém
 * esquecer um `where('company_id', ...)` explícito, o que é exatamente o
 * ponto: a defesa não depende de lembrança.
 *
 * ## Idempotência: as mesmas três barreiras de `GatewayEventService`
 *
 * 1. **`gateway_events.evento_id` é unique**, e `registrar()` grava com
 *    `firstOrCreate`.
 * 2. **`processado_em`**, conferido por quem chama antes de pedir o
 *    processamento (`CobrancaWebhookController`).
 * 3. **A saída antecipada de cada método de domínio**: `marcarPaga()` não
 *    baixa de novo uma cobrança já paga, `marcarCancelada()`/`marcarVencida()`
 *    só agem sobre cobrança ainda ativa, `estornar()` não estorna de novo uma
 *    cobrança já estornada. É esta terceira que segura duas entregas
 *    simultâneas do mesmo evento (webhook reenviado antes de a primeira
 *    responder), que passariam juntas pela barreira 2 antes de qualquer uma
 *    gravar `processado_em`.
 *
 * ## Quem assina o lançamento de caixa da baixa automática
 *
 * `SettlementService::baixar()`/`::estornar()` exigem um `User` real:
 * `financial_entries.created_by` é `NOT NULL`, e `estornar()` também confere
 * a permissão `financeiro-estornar`. Um webhook não tem usuário nenhum. A
 * solução adotada é atribuir o lançamento ao primeiro administrador ativo do
 * tenant (`usuarioResponsavelPelaBaixaAutomatica()`): é uma pessoa real da
 * própria empresa, que já tem a permissão de estorno pelo papel
 * `administrador` (recebe o catálogo inteiro, `SyncPermissions`), e a
 * atribuição fica registrada no lançamento como qualquer outra — nada é
 * criado "sem dono". Alterar `financial_entries.created_by` para aceitar
 * `null` resolveria isso de outro jeito, mas é tabela com dado em produção
 * (Plano 3), e o CLAUDE.md pede staging em etapas para esse tipo de mudança;
 * não há motivo para abrir essa frente aqui.
 *
 * ## Erro de processamento não é erro de requisição
 *
 * Nenhuma exceção sai deste Service. Falha em qualquer parte grava a mensagem
 * em `GatewayEvent.erro`, incrementa `tentativas` e para por aí, para que o
 * controller devolva 200 ao provedor — mesmo raciocínio de
 * `GatewayEventService`: 500 faria o gateway reenviar o mesmo evento em laço,
 * e o payload inteiro já está gravado, pronto para reprocessar depois que a
 * causa for corrigida (a Task 19.6 expõe isso na tela de conciliação).
 */
class ProcessadorDeEventoDeCobranca
{
    private const SITUACAO_PENDENTE = 'pendente';

    private const SITUACAO_EMITIDA = 'emitida';

    private const SITUACAO_PAGA = 'paga';

    private const SITUACAO_VENCIDA = 'vencida';

    private const SITUACAO_CANCELADA = 'cancelada';

    private const SITUACAO_ESTORNADA = 'estornada';

    /**
     * Situações de `Charge` a partir das quais cancelar ainda faz sentido.
     * `paga`, `cancelada` e `estornada` ficam de fora: cancelamento tardio
     * não pode apagar baixa nem reabrir cobrança já encerrada.
     *
     * @var array<int, string>
     */
    private const SITUACOES_CANCELAVEIS = [self::SITUACAO_PENDENTE, self::SITUACAO_EMITIDA, self::SITUACAO_VENCIDA];

    public function __construct(private readonly SettlementService $settlementService) {}

    /**
     * Grava o evento recebido, ou devolve o que já estava gravado.
     *
     * Primeira barreira da idempotência (ver o cabeçalho da classe). Grava o
     * payload **cru** que o provedor enviou, nunca o `EventoDeCobranca`
     * interpretado, pelo mesmo motivo de `GatewayEventService::registrar()`:
     * o cru permite reprocessar depois de corrigir um bug de interpretação.
     *
     * `company_id` vem de `$configuracao`, nunca de dentro do payload: é o
     * tenant do token da URL que decide de quem é o evento, não nada que o
     * corpo da requisição diga.
     *
     * @param  array<string, mixed>  $payload
     */
    public function registrar(PaymentGatewayConfig $configuracao, EventoDeCobranca $eventoDeCobranca, array $payload): GatewayEvent
    {
        return GatewayEvent::firstOrCreate(
            ['evento_id' => $eventoDeCobranca->eventoId],
            [
                'company_id' => $configuracao->company_id,
                'gateway' => $configuracao->gateway,
                'tipo' => $eventoDeCobranca->tipo,
                'payload' => $payload,
            ]
        );
    }

    /**
     * Aplica no domínio o que o evento comunica e marca o evento como
     * processado.
     *
     * Tudo dentro de `TenantAtual::comTenant($configuracao->company_id, ...)`:
     * é o que faz `cobrancaDoEvento()` e `usuarioResponsavelPelaBaixaAutomatica()`
     * enxergarem só o tenant do token (ver o cabeçalho da classe). O efeito
     * de domínio e a gravação de `processado_em` ficam na mesma transação de
     * banco: evento marcado como processado cuja baixa não foi gravada seria
     * uma confirmação de pagamento perdida sem deixar rastro.
     *
     * Não lança. Ver o cabeçalho da classe.
     */
    public function processar(GatewayEvent $evento, EventoDeCobranca $eventoDeCobranca, PaymentGatewayConfig $configuracao): void
    {
        try {
            TenantAtual::comTenant((int) $configuracao->company_id, function () use ($evento, $eventoDeCobranca): void {
                DB::transaction(function () use ($evento, $eventoDeCobranca): void {
                    $this->aplicarNoDominio($evento, $eventoDeCobranca);

                    $evento->update([
                        'processado_em' => now(),
                        'erro' => null,
                    ]);
                });
            });
        } catch (Throwable $erro) {
            $this->registrarFalha($evento, $erro);
        }
    }

    // -----------------------------------------------------------------
    // Efeito de cada tipo de evento
    // -----------------------------------------------------------------

    /**
     * @throws RuntimeException Quando a cobrança não é localizada, ou o tipo não tem tratamento.
     */
    private function aplicarNoDominio(GatewayEvent $evento, EventoDeCobranca $eventoDeCobranca): void
    {
        match ($eventoDeCobranca->tipo) {
            EventoDeCobranca::TIPO_PAGA => $this->marcarPaga($evento, $eventoDeCobranca),
            EventoDeCobranca::TIPO_VENCIDA => $this->marcarVencida($eventoDeCobranca),
            EventoDeCobranca::TIPO_CANCELADA => $this->marcarCancelada($eventoDeCobranca),
            EventoDeCobranca::TIPO_ESTORNADA => $this->estornar($evento, $eventoDeCobranca),
            EventoDeCobranca::TIPO_DESCONHECIDO => null,
            default => throw new RuntimeException(sprintf(
                'Evento de tipo "%s" não tem tratamento em ProcessadorDeEventoDeCobranca. '
                .'Acrescente o ramo correspondente antes de o provedor mandar o próximo.',
                $eventoDeCobranca->tipo
            )),
        };
    }

    /**
     * Pagamento confirmado: marca a `Charge` como paga e dá baixa na parcela,
     * com a data e o valor informados pelo gateway.
     *
     * Saída antecipada silenciosa quando a cobrança já está paga (terceira
     * barreira da idempotência, ver o cabeçalho da classe): nem chama
     * `SettlementService::baixar()`, que recusaria uma parcela sem saldo
     * devedor com `ValidationException` — recusa correta para um valor que
     * não bate, mas não para o reenvio esperado do mesmo evento de sucesso.
     */
    private function marcarPaga(GatewayEvent $evento, EventoDeCobranca $eventoDeCobranca): void
    {
        $cobranca = $this->cobrancaDoEvento($eventoDeCobranca);

        if ($cobranca->situacao === self::SITUACAO_PAGA) {
            return;
        }

        $parcela = $this->parcelaDaCobranca($cobranca);
        $usuario = $this->usuarioResponsavelPelaBaixaAutomatica();

        $valor = $eventoDeCobranca->valorPago ?? (string) $cobranca->valor;
        $dia = $eventoDeCobranca->pagoEm?->toDateString() ?? BusinessDate::hoje()->toDateString();

        $this->settlementService->baixar($parcela, [
            'valor' => $valor,
            'data' => $dia,
            'usuario' => $usuario,
            'forma_pagamento' => $cobranca->tipo,
            'observacao' => sprintf('Baixa automática via webhook do gateway (evento #%s).', $evento->evento_id),
        ]);

        $cobranca->update([
            'situacao' => self::SITUACAO_PAGA,
            'pago_em' => $dia,
            'valor_pago' => $valor,
        ]);
    }

    /**
     * Cobrança vencida: só rebaixa uma cobrança que ainda está `emitida`.
     * Nunca sobrescreve `paga`, `cancelada` nem `estornada` — um aviso de
     * vencimento tardio não pode apagar o que já aconteceu depois dele.
     */
    private function marcarVencida(EventoDeCobranca $eventoDeCobranca): void
    {
        $cobranca = $this->cobrancaDoEvento($eventoDeCobranca);

        if ($cobranca->situacao !== self::SITUACAO_EMITIDA) {
            return;
        }

        $cobranca->update(['situacao' => self::SITUACAO_VENCIDA]);
    }

    /**
     * Cobrança cancelada: só cancela uma cobrança ainda ativa
     * (`SITUACOES_CANCELAVEIS`). Mesma regra já provada no webhook da
     * plataforma (Plano 7, `GatewayWebhookTest`): "um evento de cancelamento
     * tardio apagou a baixa de uma fatura já paga" é exatamente o bug que
     * esta saída antecipada evita.
     */
    private function marcarCancelada(EventoDeCobranca $eventoDeCobranca): void
    {
        $cobranca = $this->cobrancaDoEvento($eventoDeCobranca);

        if (! in_array($cobranca->situacao, self::SITUACOES_CANCELAVEIS, true)) {
            return;
        }

        $cobranca->update(['situacao' => self::SITUACAO_CANCELADA]);
    }

    /**
     * Estorno: reverte a baixa da parcela com motivo automático e marca a
     * `Charge` como estornada.
     *
     * Saída antecipada silenciosa quando a cobrança já está estornada (mesmo
     * raciocínio de `marcarPaga()`). Quando a cobrança nunca foi marcada como
     * paga por aqui, `SettlementService::estornar()` recusa com
     * `ValidationException` ("não há o que estornar") — e isso vira `erro`
     * reprocessável de propósito: é uma divergência real entre o que o
     * gateway diz e o que o sistema tem registrado, não um reenvio
     * inofensivo, e merece ficar visível para conciliação manual.
     */
    private function estornar(GatewayEvent $evento, EventoDeCobranca $eventoDeCobranca): void
    {
        $cobranca = $this->cobrancaDoEvento($eventoDeCobranca);

        if ($cobranca->situacao === self::SITUACAO_ESTORNADA) {
            return;
        }

        $parcela = $this->parcelaDaCobranca($cobranca);
        $usuario = $this->usuarioResponsavelPelaBaixaAutomatica();

        $motivo = sprintf(
            'Estorno automático via webhook do gateway (evento #%s): o provedor informou a devolução do valor ao cliente.',
            $evento->evento_id
        );

        $this->settlementService->estornar($parcela, $motivo, $usuario);

        $cobranca->update(['situacao' => self::SITUACAO_ESTORNADA]);
    }

    // -----------------------------------------------------------------
    // Localização dos recursos
    // -----------------------------------------------------------------

    /**
     * Cobrança a que o evento se refere, **dentro do tenant corrente**
     * (`TenantAtual::comTenant()`, aplicado por `processar()`).
     *
     * Sem filtro explícito de `company_id` aqui de propósito: `Charge` usa
     * `BelongsToCompany`, e o escopo global já filtra pelo tenant do token
     * enquanto esta consulta roda dentro de `processar()`. É essa dependência
     * do contexto, e não uma condição `where` que alguém poderia esquecer de
     * copiar num método novo, que garante que um `gateway_charge_id`
     * coincidente em outro tenant nunca aparece aqui.
     *
     * @throws RuntimeException Quando o evento não traz o identificador, ou nenhuma cobrança deste tenant tem esse identificador.
     */
    private function cobrancaDoEvento(EventoDeCobranca $eventoDeCobranca): Charge
    {
        $idNoGateway = $eventoDeCobranca->chargeIdNoGateway;

        if (blank($idNoGateway)) {
            throw new RuntimeException(sprintf(
                'O evento "%s" chegou sem o identificador da cobrança no provedor: não há o que localizar.',
                $eventoDeCobranca->tipo
            ));
        }

        $cobranca = Charge::query()
            ->where('gateway_charge_id', (string) $idNoGateway)
            ->first();

        if (! $cobranca instanceof Charge) {
            throw new RuntimeException(sprintf(
                'Nenhuma cobrança com gateway_charge_id "%s" para esta empresa. O evento ficou gravado com o '
                .'payload inteiro: concilie a cobrança com o provedor antes de reprocessar.',
                $idNoGateway
            ));
        }

        return $cobranca;
    }

    /**
     * @throws RuntimeException Cobrança sem parcela vinculada.
     */
    private function parcelaDaCobranca(Charge $cobranca): ReceivableInstallment
    {
        $parcela = $cobranca->receivableInstallment;

        if (! $parcela instanceof ReceivableInstallment) {
            throw new RuntimeException(
                "A cobrança #{$cobranca->getKey()} não está vinculada a uma parcela a receber."
            );
        }

        return $parcela;
    }

    /**
     * Usuário a quem o lançamento de caixa da baixa/estorno automática é
     * atribuído. Ver o cabeçalho da classe sobre por que é o administrador do
     * tenant, e não um "usuário do sistema" dedicado.
     *
     * `daEmpresaAtual()` (não o escopo global, que `User` desliga de
     * propósito — ver `App\Models\User::aplicaEscopoDeEmpresaNaLeitura()`)
     * filtra pelo `TenantAtual::id()` corrente, que é o tenant do token
     * enquanto este método roda dentro de `processar()`.
     *
     * @throws RuntimeException Empresa sem administrador ativo.
     */
    private function usuarioResponsavelPelaBaixaAutomatica(): User
    {
        $usuario = User::query()
            ->role('administrador')
            ->daEmpresaAtual()
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if (! $usuario instanceof User) {
            throw new RuntimeException(
                'Nenhum administrador ativo encontrado para esta empresa: a baixa automática do webhook '
                .'precisa de um usuário responsável pelo lançamento no caixa.'
            );
        }

        return $usuario;
    }

    // -----------------------------------------------------------------
    // Falha
    // -----------------------------------------------------------------

    /**
     * Guarda a falha no próprio evento, fora da transação que já foi
     * desfeita. Mesmo raciocínio de `GatewayEventService::registrarFalha()`,
     * inclusive o `refresh()` antes de gravar: o rollback desfez a escrita no
     * banco, mas não os atributos que o `update()` da transação já tinha
     * aplicado nesta instância em memória.
     */
    private function registrarFalha(GatewayEvent $evento, Throwable $erro): void
    {
        $evento->refresh();

        $evento->update([
            'erro' => $erro->getMessage(),
            'tentativas' => (int) $evento->tentativas + 1,
        ]);

        Log::error('gateway_event.cobranca_processamento_falhou', [
            'gateway_event_id' => $evento->getKey(),
            'company_id' => $evento->company_id,
            'gateway' => $evento->gateway,
            'evento_id' => $evento->evento_id,
            'tipo' => $evento->tipo,
            'tentativas' => $evento->tentativas,
            'erro' => $erro->getMessage(),
        ]);
    }
}
