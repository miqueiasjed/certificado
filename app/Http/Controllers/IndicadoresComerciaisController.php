<?php

namespace App\Http\Controllers;

use App\Services\Commercial\IndicadoresComerciaisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Painel comercial (Plano 23, Task 23.6): orçamento enviado, taxa de
 * conversão, ticket médio e tempo até o fechamento, geral, por pessoa e por
 * mês. Controller à parte de `CommissionController`, porque o assunto é
 * orçamento e funil de venda, não comissão nem meta - mesmo critério de
 * responsabilidade única que já separa `ContractVisitController` de
 * `ContractController`.
 *
 * Gate por `orcamento-ver`: o painel é lido inteiramente a partir de
 * `Budget` (ver o docblock de `IndicadoresComerciaisService`), e não existe
 * permissão própria de "indicadores" no catálogo desta task. Reaproveitar
 * `orcamento-ver` mantém o papel `comercial` (que já recebe todo o prefixo
 * `orcamento-`) enxergando o próprio funil sem exigir uma permissão nova.
 */
class IndicadoresComerciaisController extends Controller
{
    public function __construct(private readonly IndicadoresComerciaisService $indicadores) {}

    public function index(Request $request): Response|JsonResponse
    {
        $dados = $this->indicadores->indicadores([
            'de' => $request->query('de'),
            'ate' => $request->query('ate'),
            'user_id' => $request->query('user_id'),
        ]);

        if ($request->expectsJson()) {
            return response()->json($dados);
        }

        return Inertia::render('Comercial/Indicadores', $dados);
    }
}
