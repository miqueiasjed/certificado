<?php

namespace App\Http\Controllers;

use App\Exceptions\CaVencidoException;
use App\Exceptions\EntregaDeEpiEstornadaException;
use App\Http\Requests\PpeDeliveryRequest;
use App\Models\PersonalProtectiveEquipment;
use App\Models\PpeDelivery;
use App\Models\Technician;
use App\Services\Ppe\FichaDeEpiService;
use App\Services\Ppe\PpeDeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ficha de entrega de EPI ao técnico (Plano 28, Task 28.4).
 *
 * Controller fino: nenhuma regra mora aqui. Copiar o CA no ato da entrega,
 * calcular a troca programada, recusar EPI inativo ou com CA vencido, gravar a
 * assinatura em disco e recusar operação sobre entrega já estornada são todos
 * assunto do `PpeDeliveryService` (Task 28.2). **Nenhuma escrita em
 * `ppe_deliveries` acontece fora dele.**
 *
 * Não existe rota de exclusão, e não pode passar a existir
 * -------------------------------------------------------
 * A entrega é documento oponível, com arquivamento recomendado de 20 anos, e
 * também não se edita. A correção de erro é `estornar()`, com motivo
 * obrigatório, na convenção do movimento de ajuste do razão de estoque do
 * Plano 17: a linha continua no banco e na extração por período, marcada como
 * estornada, e sai de todo cálculo de situação e de todo alerta. Apagar o
 * registro apagaria o rastro do próprio erro — que é justamente o que a norma
 * não permite.
 *
 * Por isso `epi-estornar` é permissão separada de `epi-entregar`: quem registra
 * a entrega no almoxarifado não é necessariamente quem pode declarar errada uma
 * linha já assinada pelo técnico.
 *
 * Isolamento entre empresas
 * -------------------------
 * `{technician}` e `{delivery}` chegam por route-model binding, e o escopo
 * global dos dois models faz o binding não encontrar registro de outra empresa —
 * 404, que é a resposta certa quando revelar a existência já vaza informação.
 *
 * `technician_id` e `personal_protective_equipment_id` chegam pelo **corpo** da
 * requisição, e ali o escopo global não alcança: quem separa as empresas é o
 * `PpeDeliveryRequest`, que compõe `Rule::exists(...)` com
 * `company_id = TenantAtual::exigirId()`. `exists:` cru aceitaria id de outra
 * empresa e ainda responderia se aquele id existe em algum tenant — o defeito
 * corrigido no commit `d9a3a9c`. O Service ainda reconsulta os dois pelo model
 * escopado antes de gravar; a regra do request adianta o erro para o formulário.
 *
 * Exceções de domínio viram resposta em português, nunca 500
 * ---------------------------------------------------------
 * `CaVencidoException` e `EntregaDeEpiEstornadaException` carregam a mensagem
 * pronta, com o caminho de correção. Deixá-las subir daria erro de servidor sem
 * explicação, que foi o defeito 2 corrigido no `d9a3a9c`. Mesmo critério do
 * `StockController` para saldo insuficiente e lote vencido.
 *
 * As duas são recusa de regra sobre dado bem formado, então saem como **422**
 * com a mensagem no campo que o usuário consegue corrigir: `CaVencidoException`
 * no campo do EPI (é lá que a tela precisa apontar), e
 * `EntregaDeEpiEstornadaException` no campo `entrega`, porque não há campo do
 * formulário a consertar — a saída é registrar uma entrega nova.
 */
class PpeDeliveryController extends Controller
{
    public function __construct(
        private readonly PpeDeliveryService $entregas,
    ) {}

    // -----------------------------------------------------------------
    // Leitura
    // -----------------------------------------------------------------

    /**
     * Relação de entregas da empresa, com filtros.
     *
     * Filtros aceitos: `technician_id`, `personal_protective_equipment_id`,
     * `de`, `ate` (dia da entrega) e `incluir_estornadas` (falso por padrão).
     *
     * Entrega estornada fica **fora** por padrão e volta com
     * `incluir_estornadas`: ela não conta como EPI em uso, e é por isso que a
     * lista de trabalho do dia a dia não a mostra. Ela nunca some do banco nem
     * da extração por período (Task 28.5), onde sai marcada como estornada —
     * omitir o estorno de um documento entregue à fiscalização é adulteração de
     * registro.
     *
     * Resposta sempre JSON: a listagem por técnico tem tela própria (`ficha`), e
     * esta aqui é a consulta transversal, usada pelos filtros da extração.
     *
     * O CA exibido é o **da entrega**, copiado no ato: as colunas `numero_ca` e
     * `validade_ca` são da própria linha, nunca lidas pela relação
     * `personalProtectiveEquipment`.
     */
    public function index(Request $request): JsonResponse
    {
        $consulta = PpeDelivery::query()
            ->with(['technician:id,name,is_active', 'personalProtectiveEquipment:id,nome,tipo,fabricante'])
            ->orderByDesc('entregue_em')
            ->orderByDesc('id');

        if ($request->filled('technician_id')) {
            $consulta->where('technician_id', $request->integer('technician_id'));
        }

        if ($request->filled('personal_protective_equipment_id')) {
            $consulta->where(
                'personal_protective_equipment_id',
                $request->integer('personal_protective_equipment_id')
            );
        }

        if ($request->filled('de')) {
            $consulta->whereDate('entregue_em', '>=', $request->string('de')->toString());
        }

        if ($request->filled('ate')) {
            $consulta->whereDate('entregue_em', '<=', $request->string('ate')->toString());
        }

        if (! $request->boolean('incluir_estornadas')) {
            $consulta->whereNull('estornada_em');
        }

        return response()->json([
            'entregas' => $consulta->get(),
            'filtros' => [
                'technician_id' => $request->filled('technician_id') ? $request->integer('technician_id') : null,
                'personal_protective_equipment_id' => $request->filled('personal_protective_equipment_id')
                    ? $request->integer('personal_protective_equipment_id')
                    : null,
                'de' => $request->string('de')->toString() ?: null,
                'ate' => $request->string('ate')->toString() ?: null,
                'incluir_estornadas' => $request->boolean('incluir_estornadas'),
            ],
        ]);
    }

    /**
     * Ficha de um técnico: o histórico completo de entregas, estornos incluídos.
     *
     * Técnico inativo continua tendo ficha, e é deliberado: o desligamento não
     * apaga o registro do que foi fornecido enquanto ele trabalhou, e o
     * arquivamento recomendado é de 20 anos.
     *
     * A entrega estornada aparece na ficha, marcada — é a sequência "a errada,
     * estornada com motivo, e a certa" que prova que a correção foi correção, e
     * não reescrita.
     *
     * Vem junto a lista dos EPIs **ativos** do cadastro, que é o que o modal de
     * entrega oferece para escolha (Task 28.6). `validade_ca` acompanha cada um
     * para a tela mostrar o CA que **será gravado** na ficha e desabilitar o que
     * está com o certificado vencido — melhor que deixar escolher e receber a
     * recusa só no envio.
     */
    public function ficha(Request $request, Technician $technician): Response|JsonResponse
    {
        $entregas = PpeDelivery::query()
            ->where('technician_id', $technician->getKey())
            ->with(['personalProtectiveEquipment:id,nome,tipo,fabricante', 'user:id,name'])
            ->orderByDesc('entregue_em')
            ->orderByDesc('id')
            ->get();

        $dados = [
            'tecnico' => $technician->only(['id', 'name', 'email', 'registration_number', 'is_active']),
            'entregas' => $entregas,
            'episDisponiveis' => PersonalProtectiveEquipment::query()
                ->where('ativo', true)
                ->orderBy('nome')
                ->get(['id', 'nome', 'tipo', 'fabricante', 'numero_ca', 'validade_ca', 'vida_util_dias']),
            'motivos' => PpeDelivery::MOTIVOS,
        ];

        if ($request->expectsJson()) {
            return response()->json($dados);
        }

        return Inertia::render('Epi/Ficha', $dados);
    }

    // -----------------------------------------------------------------
    // Escrita: sempre pelo PpeDeliveryService
    // -----------------------------------------------------------------

    /**
     * Registra a entrega de um EPI a um técnico.
     *
     * `user_id` é quem registrou, e vai no array de dados: o Service nunca chama
     * `Auth::user()` por dentro, porque também roda em comando artisan e em
     * teste, onde não há usuário autenticado.
     */
    public function store(PpeDeliveryRequest $request): RedirectResponse|JsonResponse
    {
        $dados = $request->validated();
        $dados['user_id'] = Auth::id();

        try {
            $entrega = $this->entregas->registrar($dados);
        } catch (CaVencidoException $recusa) {
            return $this->recusar($request, $recusa->getMessage(), 'personal_protective_equipment_id');
        }

        if ($request->expectsJson()) {
            return response()->json([
                'mensagem' => 'Entrega de EPI registrada.',
                'entrega' => $entrega,
            ], 201);
        }

        return redirect()
            ->route('epi.tecnicos.ficha', $entrega->technician_id)
            ->with('success', 'Entrega de EPI registrada.');
    }

    /**
     * Grava a assinatura de recebimento do técnico.
     *
     * É a prova exigida pela NR-6: entrega sem ela é pendência, não ficha
     * válida. Reassinar corrige a mesma linha, nunca cria uma segunda entrega —
     * duplicar faria a ficha mentir sobre a quantidade entregue.
     */
    public function assinar(PpeDeliveryRequest $request, PpeDelivery $delivery): RedirectResponse|JsonResponse
    {
        try {
            $this->entregas->assinar($delivery, (string) $request->validated()['assinatura']);
        } catch (EntregaDeEpiEstornadaException $recusa) {
            return $this->recusar($request, $recusa->getMessage());
        }

        return $this->confirmar($request, 'Assinatura de recebimento registrada.', $delivery->refresh());
    }

    /**
     * Registra a devolução do item pelo técnico.
     *
     * Devolução não é estorno: o estorno diz "esta entrega está errada", a
     * devolução diz "esta entrega aconteceu e terminou". O item devolvido para
     * de contar como EPI em uso e continua na ficha.
     */
    public function devolver(PpeDeliveryRequest $request, PpeDelivery $delivery): RedirectResponse|JsonResponse
    {
        $dados = $request->validated();

        try {
            $this->entregas->devolver(
                $delivery,
                (string) $dados['motivo_devolucao'],
                $dados['devolvido_em'] ?? null
            );
        } catch (EntregaDeEpiEstornadaException $recusa) {
            return $this->recusar($request, $recusa->getMessage());
        }

        return $this->confirmar($request, 'Devolução registrada.', $delivery->refresh());
    }

    /**
     * Estorna a entrega, com motivo obrigatório.
     *
     * Única correção possível de uma entrega errada, e a razão de
     * `epi-estornar` ser permissão separada: altera prova documental.
     */
    public function estornar(PpeDeliveryRequest $request, PpeDelivery $delivery): RedirectResponse|JsonResponse
    {
        try {
            $this->entregas->estornar($delivery, (string) $request->validated()['motivo_estorno']);
        } catch (EntregaDeEpiEstornadaException $recusa) {
            return $this->recusar($request, $recusa->getMessage());
        }

        return $this->confirmar(
            $request,
            'Entrega estornada. Ela permanece na ficha como histórico, marcada com o motivo.',
            $delivery->refresh()
        );
    }

    // -----------------------------------------------------------------
    // Documentos (Task 28.5)
    // -----------------------------------------------------------------

    /**
     * Ficha de EPI do técnico em PDF.
     *
     * Documento de valor perante fiscalização: sai com o histórico completo,
     * inclusive as entregas estornadas, marcadas como tal. Estorno omitido do
     * documento seria adulteração de registro.
     *
     * Técnico inativo continua tendo ficha — o desligamento não apaga o que foi
     * entregue, e o arquivamento recomendado é de 20 anos.
     */
    public function fichaPdf(Technician $technician, FichaDeEpiService $fichas): HttpResponse
    {
        return $fichas->gerarFichaEmPdf($technician)
            ->stream($fichas->nomeDoArquivoDaFicha($technician));
    }

    /**
     * Relação de entregas do período, em CSV.
     *
     * É o que o item 6.5.1.1 da NR-6 exige: o registro eletrônico só é aceito
     * se permitir a extração de relatórios. É o arquivo que se entrega ao
     * fiscal, e por isso é entregável do plano, não enfeite.
     */
    public function extracao(PpeDeliveryRequest $request, FichaDeEpiService $fichas): HttpResponse
    {
        $inicio = $request->validated('inicio');
        $fim = $request->validated('fim');

        return response(
            $fichas->montarCsvDeEntregas($inicio, $fim),
            200,
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="'
                    .$fichas->nomeDoArquivoDaExtracao($inicio, $fim).'"',
            ]
        );
    }

    // -----------------------------------------------------------------
    // Respostas
    // -----------------------------------------------------------------

    /**
     * Recusa de regra de negócio sobre dado bem formado: 422 com a mensagem
     * pronta da exceção para quem chama por fetch, e volta ao formulário com o
     * erro no campo para quem chega pela navegação Inertia.
     *
     * `$campo` é onde a tela deve pousar o erro. O padrão é `entrega`, para a
     * recusa que não tem campo de formulário a consertar (entrega já estornada:
     * a saída é registrar uma entrega nova, não corrigir um campo).
     */
    private function recusar(Request $request, string $mensagem, string $campo = 'entrega'): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'mensagem' => $mensagem,
                'errors' => [$campo => [$mensagem]],
            ], 422);
        }

        return back()->withInput()->withErrors([$campo => $mensagem]);
    }

    private function confirmar(Request $request, string $mensagem, PpeDelivery $entrega): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'mensagem' => $mensagem,
                'entrega' => $entrega,
            ]);
        }

        return back()->with('success', $mensagem);
    }
}
