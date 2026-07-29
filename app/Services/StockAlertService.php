<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Leituras que sustentam os alertas de estoque mínimo e de validade (Plano 17,
 * Task 17.6): "o que avisar", nunca "avisar". Quem enfileira o aviso pela
 * central de notificações do Plano 14, decide o marco e a cadência de reenvio
 * é `App\Console\Commands\VerificarEstoque`; este Service só devolve os dados.
 *
 * Saldo é sempre o total da empresa
 * ----------------------------------
 * `abaixoDoMinimo()` soma o saldo de todos os locais, inclusive o que está na
 * van do técnico. Comparar por local geraria um alerta falso toda vez que um
 * técnico saísse com produto para a rota do dia, mesmo com o depósito cheio.
 *
 * As consultas de validade vêm prontas da Task 17.3
 * ---------------------------------------------------
 * `lotesVencendo()` não reimplementa nenhuma regra de vencimento: ela chama
 * `BatchSelectionService::vencendoEm()` com as três janelas do plano (60, 30 e
 * 7 dias) e `vencidos()`, que já respeitam o escopo por empresa, comparam por
 * dia no fuso do negócio e mostram o lote vencido enquanto ele tiver saldo,
 * exatamente como a tela de estoque enxerga.
 *
 * Empresa
 * -------
 * Sem consulta própria de tenant aqui: o escopo global dos models
 * (`Product`, e o que `BatchSelectionService` consulta por baixo) resolve
 * pelo tenant corrente. Quem roda para todas as empresas (a rotina da Task
 * 17.6) itera com `TenantAtual::comTenant()`, no mesmo padrão do resto do
 * projeto.
 */
class StockAlertService
{
    /**
     * Janelas de vencimento avisadas, em dias, na ordem em que aparecem no
     * plano: da mais distante para a mais urgente.
     *
     * @var array<int, int>
     */
    private const JANELAS_DE_VENCIMENTO = [60, 30, 7];

    public function __construct(
        private readonly StockService $estoque,
        private readonly BatchSelectionService $lotes,
    ) {}

    /**
     * Produtos com controle de estoque cujo saldo somado da empresa está
     * abaixo do mínimo configurado.
     *
     * Produto com `estoque_minimo` nulo é ignorado de propósito: nulo
     * significa "sem ponto de reposição definido", diferente de zero, e
     * tratar os dois do mesmo jeito alertaria a base inteira que ainda não
     * configurou o mínimo.
     *
     * @return Collection<int, array{produto: Product, saldo: float, minimo: float}>
     */
    public function abaixoDoMinimo(): Collection
    {
        return Product::query()
            ->where('controla_estoque', true)
            ->whereNotNull('estoque_minimo')
            ->orderBy('id')
            ->get()
            ->map(fn (Product $produto): array => [
                'produto' => $produto,
                'saldo' => $this->estoque->saldo($produto),
                'minimo' => (float) $produto->estoque_minimo,
            ])
            ->filter(fn (array $linha): bool => $linha['saldo'] < $linha['minimo'])
            ->values();
    }

    /**
     * Lotes com saldo que pedem atenção de validade: os que vencem em 60, 30
     * e 7 dias, agrupados por janela, mais os já vencidos.
     *
     * As três janelas são cumulativas (um lote a 5 dias do vencimento aparece
     * nas três) de propósito: `BatchSelectionService::vencendoEm()` não tem
     * como devolver "só o que cruza exatamente esta fronteira hoje", porque
     * não guarda em que dia o alerta anterior rodou. É por isso que o marco
     * de idempotência de cada disparo é a janela chamada (60, 30 ou 7), e não
     * o `dias_para_vencer` do lote: assim o mesmo lote pode gerar até três
     * avisos distintos ao longo do tempo, um por marco atingido, sem duplicar
     * quando a rotina roda de novo dentro da mesma janela.
     *
     * @return array{
     *     vencendo: array<int, array{dias: int, lotes: Collection<int, array{lote: \App\Models\ProductBatch, validade: string, dias_para_vencer: int, saldo: float, locais: array<int, array{stock_location_id: int, nome: string, quantidade: float}>}>}>,
     *     vencidos: Collection<int, array{lote: \App\Models\ProductBatch, validade: string, dias_para_vencer: int, saldo: float, locais: array<int, array{stock_location_id: int, nome: string, quantidade: float}>}>
     * }
     */
    public function lotesVencendo(): array
    {
        return [
            'vencendo' => array_map(
                fn (int $dias): array => ['dias' => $dias, 'lotes' => $this->lotes->vencendoEm($dias)],
                self::JANELAS_DE_VENCIMENTO
            ),
            'vencidos' => $this->lotes->vencidos(),
        ];
    }
}
