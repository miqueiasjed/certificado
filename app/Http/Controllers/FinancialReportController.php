<?php

namespace App\Http\Controllers;

use App\Services\Financial\AgingService;
use App\Services\Financial\CashForecastService;
use App\Support\BusinessDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * As duas leituras financeiras que nasceram na Task 18.6 e ganham rota aqui
 * (Plano 18, Task 18.7): a inadimplência por faixa de atraso e a previsão de
 * caixa dos próximos meses.
 *
 * Controller de leitura pura: nenhum dos dois endpoints escreve nada, e todo
 * o cálculo vive nos Services (`AgingService` e `CashForecastService`), que
 * já somam em centavos e já decidem faixa e competência por dia no fuso do
 * negócio. Aqui só chegam o filtro normalizado e a resposta.
 *
 * Permissão `financeiro-ver`, a mesma do painel financeiro e do fluxo de
 * caixa: são telas de consulta do mesmo dinheiro, e exigir a permissão de
 * título (`financeiro-titulos`) para *olhar* a inadimplência deixaria de fora
 * quem hoje já vê o painel.
 */
class FinancialReportController extends Controller
{
    /**
     * Horizontes aceitos pela previsão, os mesmos três da tela (Task 18.9).
     * Número fora da lista cai no padrão, em vez de devolver erro: a tela tem
     * um seletor fechado, e um valor estranho só chega aqui por URL editada à
     * mão.
     *
     * @var array<int, int>
     */
    private const HORIZONTES = [3, 6, 12];

    private const HORIZONTE_PADRAO = 6;

    public function __construct(
        private readonly AgingService $aging,
        private readonly CashForecastService $previsao,
    ) {}

    /**
     * Inadimplência por cliente, nas cinco faixas de atraso.
     */
    public function inadimplencia(Request $request): Response|JsonResponse
    {
        $clienteId = $request->integer('client_id') ?: null;

        $dados = [
            'filtros' => ['client_id' => $clienteId],
            'aging' => $this->aging->porCliente($clienteId === null ? null : ['client_id' => $clienteId]),
            'referencia' => BusinessDate::hoje()->toDateString(),
        ];

        if ($request->expectsJson()) {
            return response()->json($dados);
        }

        return Inertia::render('Financeiro/Inadimplencia', $dados);
    }

    /**
     * Previsão de caixa dos próximos meses, com o saldo realizado de hoje
     * separado do previsto (ver o cabeçalho de `CashForecastService`).
     */
    public function previsao(Request $request): Response|JsonResponse
    {
        $meses = (int) $request->integer('meses');
        $meses = in_array($meses, self::HORIZONTES, true) ? $meses : self::HORIZONTE_PADRAO;

        $dados = [
            'filtros' => ['meses' => $meses],
            'horizontes' => self::HORIZONTES,
            'previsao' => $this->previsao->previsao($meses),
        ];

        if ($request->expectsJson()) {
            return response()->json($dados);
        }

        return Inertia::render('Financeiro/Previsao', $dados);
    }
}
