<?php

namespace App\Http\Controllers;

use App\Exceptions\IaIndisponivelException;
use App\Exceptions\IaLimiteDeTaxaException;
use App\Exceptions\IaRecusouException;
use App\Exceptions\RascunhoJaExisteException;
use App\Exceptions\TetoDeIaAtingidoException;
use App\Http\Requests\GerarParecerRequest;
use App\Http\Requests\RevisarRascunhoRequest;
use App\Http\Requests\SugerirPrecoRequest;
use App\Models\AiDraft;
use App\Models\Budget;
use App\Models\Company;
use App\Models\MonitoringReport;
use App\Models\WorkOrder;
use App\Services\Ai\MedicaoDeUsoService;
use App\Services\Ai\ParecerService;
use App\Services\Ai\SugestaoDePrecoService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * Geração, revisão e descarte de rascunhos assistidos por IA (Plano 25,
 * Task 25.5).
 *
 * Respostas em JSON: estes endpoints são chamados pelo editor de parecer e
 * pelo formulário de orçamento sem troca de página. Controller fino — toda
 * decisão está em `ParecerService`, `SugestaoDePrecoService` e
 * `MedicaoDeUsoService`.
 *
 * ## Duas permissões, não uma
 *
 * `ia-gerar` produz rascunho; `ia-revisar` aprova o texto. Quem aprova assume
 * a responsabilidade profissional pelo parecer, e é por isso que a segunda
 * fica com administrador e responsável técnico, não com quem gera. As duas
 * ficam sob o módulo `laudo_ia`, que nasce desligado para todo mundo.
 *
 * ## Teto
 *
 * A verificação de teto acontece **apenas** nos dois caminhos de geração.
 * Nenhum outro endpoint do sistema consulta o teto: limite de um recurso
 * opcional nunca pode derrubar OS, certificado ou financeiro.
 *
 * ## Id vindo do corpo
 *
 * `origem_id` e `budget_id` chegam pelo corpo da requisição, não pela rota, e
 * por isso são resolvidos por consulta ao model (`findOrFail`) antes de
 * qualquer uso. É a consulta, com o escopo global de empresa ativo, que
 * garante o isolamento: registro de outra empresa não é encontrado e vira 404.
 */
class AiDraftController extends Controller
{
    public function __construct(
        private readonly ParecerService $pareceres,
        private readonly SugestaoDePrecoService $precos,
        private readonly MedicaoDeUsoService $medicao,
    ) {}

    /**
     * Gera o rascunho de parecer (OS) ou de resumo do período (relatório de
     * monitoramento).
     */
    public function store(GerarParecerRequest $request): JsonResponse
    {
        $dados = $request->validated();
        $empresa = Company::current();

        try {
            $this->medicao->garantirDentroDoTeto($empresa);

            $origem = $this->resolverOrigem($dados['tipo'], (int) $dados['origem_id']);

            $rascunho = $dados['tipo'] === AiDraft::TIPO_PARECER_OS
                ? $this->pareceres->gerarParaOs($origem, Auth::user())
                : $this->pareceres->resumoDoPeriodo($origem, Auth::user());
        } catch (RascunhoJaExisteException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => ['rascunho' => $this->paraArray($e->existente)],
            ], 409);
        } catch (TetoDeIaAtingidoException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 429);
        } catch (IaLimiteDeTaxaException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 429);
        } catch (IaIndisponivelException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 503);
        } catch (IaRecusouException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Rascunho gerado. Ele precisa ser revisado antes da emissão.',
            'data' => ['rascunho' => $this->paraArray($rascunho)],
        ], 201);
    }

    /**
     * Rascunho para leitura e edição.
     *
     * O binding traz só rascunho da própria empresa: `AiDraft` carrega o
     * escopo global, então id de outra empresa responde 404 sem revelar que o
     * registro existe.
     */
    public function show(AiDraft $aiDraft): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ['rascunho' => $this->paraArray($aiDraft)],
        ]);
    }

    /**
     * Grava o texto revisado e libera a emissão.
     */
    public function revisar(RevisarRascunhoRequest $request, AiDraft $aiDraft): JsonResponse
    {
        try {
            $rascunho = $this->pareceres->revisar(
                $aiDraft,
                $request->validated()['conteudo_revisado'],
                Auth::user()
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Parecer revisado e liberado para emissão.',
            'data' => ['rascunho' => $this->paraArray($rascunho)],
        ]);
    }

    /**
     * Descarta o rascunho e libera a origem para uma nova geração.
     */
    public function descartar(AiDraft $aiDraft): JsonResponse
    {
        $rascunho = $this->pareceres->descartar($aiDraft, Auth::user());

        return response()->json([
            'success' => true,
            'message' => 'Rascunho descartado. É possível gerar outro para este registro.',
            'data' => ['rascunho' => $this->paraArray($rascunho)],
        ]);
    }

    /**
     * Faixa de preço sugerida a partir do histórico da própria empresa.
     *
     * A justificativa em texto (a única parte que passa pelo modelo) só é
     * pedida quando há orçamento informado e amostra suficiente. O número
     * nunca passa pelo modelo, e a resposta nunca altera o orçamento: é
     * referência ao lado do campo, e quem digita o valor é a pessoa.
     */
    public function sugerirPreco(SugerirPrecoRequest $request): JsonResponse
    {
        $dados = $request->validated();
        $empresa = Company::current();

        $sugestao = $this->precos->sugerir($dados, $empresa);

        $justificativa = null;
        $orcamento = filled($dados['budget_id'] ?? null)
            ? Budget::query()->findOrFail((int) $dados['budget_id'])
            : null;

        if ($orcamento !== null && $sugestao['suficiente'] === true) {
            try {
                $this->medicao->garantirDentroDoTeto($empresa);
                $justificativa = $this->precos->justificar($sugestao, $orcamento, Auth::user());
            } catch (TetoDeIaAtingidoException) {
                // Teto atingido não derruba a sugestão: o número é o que
                // importa, e ele foi calculado sem custo nenhum.
                $justificativa = null;
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'sugestao' => $sugestao,
                'justificativa' => $justificativa === null ? null : $this->paraArray($justificativa),
            ],
        ]);
    }

    /**
     * Registro de origem do rascunho, escopado pela empresa corrente.
     */
    private function resolverOrigem(string $tipo, int $id): Model
    {
        return match ($tipo) {
            AiDraft::TIPO_PARECER_OS => WorkOrder::query()->findOrFail($id),
            AiDraft::TIPO_RESUMO_MONITORAMENTO => MonitoringReport::query()->findOrFail($id),
        };
    }

    /**
     * Rascunho no formato que a tela consome.
     *
     * `conteudo_gerado` viaja junto com o revisado de propósito: é a tela que
     * mostra ao responsável técnico o que o modelo escreveu antes da revisão,
     * e sem os dois lado a lado não há como conferir o que mudou.
     *
     * @return array<string, mixed>
     */
    private function paraArray(AiDraft $rascunho): array
    {
        return [
            'id' => $rascunho->id,
            'tipo' => $rascunho->tipo,
            'origem_tipo' => $rascunho->origem_tipo,
            'origem_id' => $rascunho->origem_id,
            'conteudo_gerado' => $rascunho->conteudo_gerado,
            'conteudo_revisado' => $rascunho->conteudo_revisado,
            'situacao' => $rascunho->situacao,
            'revisado' => $rascunho->revisado,
            'modelo' => $rascunho->modelo,
            'gerado_por' => $rascunho->gerado_por,
            'revisado_por' => $rascunho->revisado_por,
            'revisado_em' => $rascunho->revisado_em?->toIso8601String(),
            'created_at' => $rascunho->created_at?->toIso8601String(),
        ];
    }
}
