<?php

namespace App\Services\Financial;

use App\Models\ReceivableInstallment;
use App\Support\BusinessDate;
use App\Support\Dinheiro;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Aging de inadimplência por cliente (Plano 18, Task 18.6): quanto cada
 * cliente tem em aberto, separado por faixa de atraso.
 *
 * Cinco faixas, sempre nesta ordem: "a vencer", 1 a 30, 31 a 60, 61 a 90 e
 * acima de 90 dias. A faixa é decidida pelo vencimento contra o dia de hoje
 * **no fuso do negócio** (`App\Support\BusinessDate`), nunca por instante em
 * UTC: uma parcela que vence hoje entra em "a vencer", nunca em atraso, mesmo
 * às 21h de Brasília. É a mesma disciplina de `BusinessDate::estaVencido()`,
 * reaproveitada aqui porque o aging também precisa da contagem de dias, não
 * só do booleano de vencido.
 *
 * O valor de cada faixa é o **saldo em aberto** da parcela
 * (`valor - valor_pago`), não o valor cheio: uma parcela paga parcialmente só
 * deve aparecer pelo que ainda falta cobrar. Parcela com saldo zerado (baixa
 * total registrada, mas a rotina de status ainda não marcou `paga`) é
 * ignorada, pelo mesmo motivo.
 *
 * Toda soma acontece em centavos (inteiro), via `App\Support\Dinheiro`, pelo
 * mesmo motivo do resto do módulo financeiro: somar `decimal(10,2)` como
 * float é o defeito que só aparece na conciliação meses depois.
 *
 * Isolamento por empresa vem do escopo global de `ReceivableInstallment`
 * (`BelongsToCompany`), não deste service: nenhuma consulta aqui usa
 * `DB::table()` cru, e o `join` com `receivables`/`clients` só serve para
 * agrupar e trazer o nome do cliente, sem abrir mão do filtro.
 *
 * Performance: a consulta usa o índice `[situacao, vencimento]` criado na
 * Task 18.1 e só percorre parcelas **em aberto** (nunca as pagas/canceladas
 * de anos anteriores), que é o que mantém a resposta rápida mesmo com anos de
 * histórico de título quitado.
 */
class AgingService
{
    /**
     * Situações de parcela que ainda representam saldo a cobrar. `paga` e
     * `cancelada` ficam fora: não há mais nada a receber delas.
     *
     * @var array<int, string>
     */
    private const SITUACOES_EM_ABERTO = ['aberta', 'parcial', 'vencida'];

    /**
     * Chaves das cinco faixas, na ordem em que aparecem no retorno.
     *
     * @var array<int, string>
     */
    private const FAIXAS = ['a_vencer', 'de_1_a_30', 'de_31_a_60', 'de_61_a_90', 'acima_de_90'];

    /**
     * Aging de contas a receber, agrupado por cliente.
     *
     * `$filtros['client_id']` (um id ou uma lista de ids) restringe o aging a
     * um cliente ou a um grupo; sem o filtro, cobre todos os clientes com
     * saldo em aberto na empresa corrente.
     *
     * @param  array{client_id?: int|array<int, int>}|null  $filtros
     * @return array{
     *     por_cliente: list<array{
     *         client_id: int,
     *         cliente: string,
     *         telefone: string|null,
     *         aceita_whatsapp: bool,
     *         faixas: array<string, array{valor: string, quantidade: int}>,
     *         total: array{valor: string, quantidade: int}
     *     }>,
     *     total_geral: array{
     *         faixas: array<string, array{valor: string, quantidade: int}>,
     *         total: array{valor: string, quantidade: int}
     *     }
     * }
     */
    public function porCliente(?array $filtros = null): array
    {
        $hoje = BusinessDate::hoje();

        $consulta = ReceivableInstallment::query()
            ->whereIn('receivable_installments.situacao', self::SITUACOES_EM_ABERTO)
            ->join('receivables', 'receivables.id', '=', 'receivable_installments.receivable_id')
            ->join('clients', 'clients.id', '=', 'receivables.client_id')
            ->select([
                'receivables.client_id as aging_client_id',
                'clients.name as aging_cliente_nome',
                // Só para a Task 18.8 montar o link de cobrança por WhatsApp na
                // tela de inadimplência: nenhuma soma nem faixa depende destas
                // duas colunas, e o mesmo tratamento de preferência recusada
                // (`aceita_whatsapp`) de `Client` é reaplicado no frontend, nunca
                // decidido aqui.
                'clients.phone as aging_cliente_telefone',
                'clients.aceita_whatsapp as aging_cliente_aceita_whatsapp',
                'receivable_installments.valor',
                'receivable_installments.valor_pago',
                'receivable_installments.vencimento',
            ])
            ->orderBy('receivables.client_id');

        $this->aplicarFiltroDeCliente($consulta, $filtros);

        $porCliente = [];

        foreach ($consulta->cursor() as $linha) {
            $saldoCentavos = Dinheiro::centavos($linha->valor) - Dinheiro::centavos($linha->valor_pago);

            if ($saldoCentavos <= 0) {
                continue;
            }

            $clienteId = (int) $linha->aging_client_id;
            $faixa = $this->faixaDe($linha->vencimento, $hoje);

            $porCliente[$clienteId] ??= [
                'client_id' => $clienteId,
                'cliente' => (string) $linha->aging_cliente_nome,
                'telefone' => $linha->aging_cliente_telefone === null ? null : (string) $linha->aging_cliente_telefone,
                'aceita_whatsapp' => (bool) $linha->aging_cliente_aceita_whatsapp,
                'faixas' => $this->faixasVazias(),
            ];

            $porCliente[$clienteId]['faixas'][$faixa]['valor_centavos'] += $saldoCentavos;
            $porCliente[$clienteId]['faixas'][$faixa]['quantidade']++;
        }

        return $this->formatarResultado($porCliente);
    }

    /**
     * Restringe a consulta a um cliente ou a uma lista de clientes, quando
     * `$filtros['client_id']` vier preenchido.
     *
     * @param  array{client_id?: int|array<int, int>}|null  $filtros
     */
    private function aplicarFiltroDeCliente(Builder $consulta, ?array $filtros): void
    {
        $clienteId = $filtros['client_id'] ?? null;

        if ($clienteId === null) {
            return;
        }

        $consulta->whereIn('receivables.client_id', array_map('intval', (array) $clienteId));
    }

    /**
     * Faixa do vencimento informado, contra o dia de hoje no fuso do negócio.
     *
     * Vencimento inválido ou ausente (não deveria acontecer, a coluna é
     * obrigatória) cai em "a vencer": a faixa de atraso não pode ser inventada
     * sem uma data para comparar.
     */
    private function faixaDe(mixed $vencimento, CarbonImmutable $hoje): string
    {
        $dia = BusinessDate::paraFusoNegocio($vencimento)?->startOfDay();

        if ($dia === null || ! $dia->lessThan($hoje)) {
            return 'a_vencer';
        }

        $diasDeAtraso = $dia->diffInDays($hoje);

        return match (true) {
            $diasDeAtraso <= 30 => 'de_1_a_30',
            $diasDeAtraso <= 60 => 'de_31_a_60',
            $diasDeAtraso <= 90 => 'de_61_a_90',
            default => 'acima_de_90',
        };
    }

    /**
     * As cinco faixas zeradas, para todo cliente nascer com a mesma forma no
     * retorno, mesmo sem nenhuma parcela em uma faixa.
     *
     * @return array<string, array{valor_centavos: int, quantidade: int}>
     */
    private function faixasVazias(): array
    {
        $vazias = [];

        foreach (self::FAIXAS as $faixa) {
            $vazias[$faixa] = ['valor_centavos' => 0, 'quantidade' => 0];
        }

        return $vazias;
    }

    /**
     * Monta o retorno final: cada cliente com suas faixas formatadas e o
     * total dele, mais o total geral somando todos os clientes.
     *
     * @param  array<int, array{client_id: int, cliente: string, telefone: string|null, aceita_whatsapp: bool, faixas: array<string, array{valor_centavos: int, quantidade: int}>}>  $porCliente
     * @return array{
     *     por_cliente: list<array{client_id: int, cliente: string, telefone: string|null, aceita_whatsapp: bool, faixas: array<string, array{valor: string, quantidade: int}>, total: array{valor: string, quantidade: int}}>,
     *     total_geral: array{faixas: array<string, array{valor: string, quantidade: int}>, total: array{valor: string, quantidade: int}}
     * }
     */
    private function formatarResultado(array $porCliente): array
    {
        ksort($porCliente);

        $faixasDoTotalGeral = $this->faixasVazias();
        $totalGeral = ['valor_centavos' => 0, 'quantidade' => 0];

        $resultado = [];

        foreach ($porCliente as $cliente) {
            $totalCliente = ['valor_centavos' => 0, 'quantidade' => 0];

            foreach (self::FAIXAS as $faixa) {
                $totalCliente['valor_centavos'] += $cliente['faixas'][$faixa]['valor_centavos'];
                $totalCliente['quantidade'] += $cliente['faixas'][$faixa]['quantidade'];

                $faixasDoTotalGeral[$faixa]['valor_centavos'] += $cliente['faixas'][$faixa]['valor_centavos'];
                $faixasDoTotalGeral[$faixa]['quantidade'] += $cliente['faixas'][$faixa]['quantidade'];
            }

            $totalGeral['valor_centavos'] += $totalCliente['valor_centavos'];
            $totalGeral['quantidade'] += $totalCliente['quantidade'];

            $resultado[] = [
                'client_id' => $cliente['client_id'],
                'cliente' => $cliente['cliente'],
                'telefone' => $cliente['telefone'],
                'aceita_whatsapp' => $cliente['aceita_whatsapp'],
                'faixas' => $this->formatarFaixas($cliente['faixas']),
                'total' => [
                    'valor' => Dinheiro::paraDecimal($totalCliente['valor_centavos']),
                    'quantidade' => $totalCliente['quantidade'],
                ],
            ];
        }

        return [
            'por_cliente' => $resultado,
            'total_geral' => [
                'faixas' => $this->formatarFaixas($faixasDoTotalGeral),
                'total' => [
                    'valor' => Dinheiro::paraDecimal($totalGeral['valor_centavos']),
                    'quantidade' => $totalGeral['quantidade'],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, array{valor_centavos: int, quantidade: int}>  $faixas
     * @return array<string, array{valor: string, quantidade: int}>
     */
    private function formatarFaixas(array $faixas): array
    {
        $formatadas = [];

        foreach (self::FAIXAS as $faixa) {
            $formatadas[$faixa] = [
                'valor' => Dinheiro::paraDecimal($faixas[$faixa]['valor_centavos']),
                'quantidade' => $faixas[$faixa]['quantidade'],
            ];
        }

        return $formatadas;
    }
}
