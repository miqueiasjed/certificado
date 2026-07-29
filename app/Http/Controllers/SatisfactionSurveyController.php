<?php

namespace App\Http\Controllers;

use App\Models\SatisfactionSurvey;
use App\Services\SatisfactionSurveyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Painel de satisfação (Plano 16, Task 16.7): indicadores apurados por
 * `SatisfactionSurveyService::indicadores()` e a fila de notas baixas com
 * pendência de contato aberta.
 *
 * Reaproveita as permissões da Task 16.4 (`agendamento-ver`/
 * `agendamento-responder`) em vez de abrir uma família nova só para isto: as
 * duas telas (pedidos de horário e satisfação) nascem do mesmo módulo de
 * agendamento online, e o catálogo de permissões já cobre "ver a tela" e
 * "agir sobre um item dela".
 */
class SatisfactionSurveyController extends Controller
{
    public function __construct(
        private readonly SatisfactionSurveyService $pesquisas,
    ) {}

    /**
     * GET /satisfacao
     */
    public function index(Request $request): Response
    {
        $de = $request->query('de');
        $ate = $request->query('ate');

        $filtros = array_filter([
            'de' => is_string($de) ? $de : null,
            'ate' => is_string($ate) ? $ate : null,
        ]);

        return Inertia::render('Satisfacao/Index', [
            'indicadores' => $this->pesquisas->indicadores($filtros),
            'pendenciasDeContato' => $this->pesquisas->pendenciasDeContato(),
            'filtro' => ['de' => $de, 'ate' => $ate],
        ]);
    }

    /**
     * POST /satisfacao/{pesquisa}/contato-feito
     */
    public function marcarContatoFeito(SatisfactionSurvey $pesquisa): RedirectResponse
    {
        try {
            $this->pesquisas->marcarContatoFeito($pesquisa);
        } catch (InvalidArgumentException $erro) {
            return back()->with('error', $erro->getMessage());
        }

        return back()->with('success', 'Contato registrado.');
    }
}
