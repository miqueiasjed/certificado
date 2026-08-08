<?php

namespace App\Http\Controllers;

use App\Exceptions\EpiComEntregaException;
use App\Http\Requests\PersonalProtectiveEquipmentRequest;
use App\Models\PersonalProtectiveEquipment;
use App\Services\Ppe\PpeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cadastro dos modelos de EPI que a empresa compra (Plano 28, Task 28.4).
 *
 * É o cadastro, não a ficha: a entrega ao técnico é `PpeDeliveryController`.
 *
 * Controller fino: nenhuma regra de EPI mora aqui. Normalizar o cadastro,
 * recusar tipo inválido, distinguir vida útil ausente de zero e recusar a
 * exclusão de um modelo já entregue são todos assunto do `PpeService`
 * (Task 28.2).
 *
 * Isolamento entre empresas
 * -------------------------
 * `{epi}` chega por route-model binding, e o escopo global de
 * `PersonalProtectiveEquipment` faz o binding não encontrar cadastro de outra
 * empresa — 404, que é a resposta certa quando revelar a existência do registro
 * já vaza informação. Nenhum id de outro registro chega pelo corpo da requisição
 * neste controller, então não há o que escopar em `PersonalProtectiveEquipmentRequest`
 * (a rota irmã, de entrega, é onde esse cuidado existe).
 *
 * Inativar, nunca excluir
 * -----------------------
 * `destroy` só funciona para o cadastro que nunca foi entregue — erro de
 * digitação no dia em que foi criado. Com entrega registrada, o `PpeService`
 * recusa com `EpiComEntregaException`, capturada aqui e devolvida como 422 em
 * português: a ficha antiga aponta para este cadastro, e é dele que saem nome,
 * tipo e fabricante do que o técnico recebeu. Deixar a exceção subir daria um
 * 500 sem explicação, que foi o defeito 2 corrigido no commit `d9a3a9c`.
 */
class PersonalProtectiveEquipmentController extends Controller
{
    public function __construct(
        private readonly PpeService $epis,
    ) {}

    /**
     * Lista os modelos de EPI da empresa.
     *
     * Filtros aceitos: `situacao` (`ativos`, `inativos`; qualquer outro valor
     * traz todos), `tipo` e `busca` (nome, fabricante ou número do CA).
     *
     * A contagem de entregas vem junto porque é ela que a tela usa para decidir
     * entre oferecer "excluir" ou "inativar" — perguntar ao usuário e só então
     * descobrir que a exclusão é impossível é a pior ordem das duas.
     *
     * A situação do CA (vencido, a vencer, em dia, não informado) **não** é
     * calculada aqui: a tela a deriva de `validade_ca`, e CA em branco é estado
     * neutro, nunca vermelho.
     */
    public function index(Request $request): Response|JsonResponse
    {
        $consulta = PersonalProtectiveEquipment::query()
            ->withCount('ppeDeliveries')
            ->orderBy('nome');

        $situacao = $request->string('situacao')->toString();

        if ($situacao === 'ativos') {
            $consulta->where('ativo', true);
        } elseif ($situacao === 'inativos') {
            $consulta->where('ativo', false);
        }

        if ($request->filled('tipo')) {
            $consulta->where('tipo', $request->string('tipo')->toString());
        }

        if ($request->filled('busca')) {
            $busca = trim($request->string('busca')->toString());

            $consulta->where(function ($consultaDeBusca) use ($busca): void {
                $consultaDeBusca->where('nome', 'like', "%{$busca}%")
                    ->orWhere('fabricante', 'like', "%{$busca}%")
                    ->orWhere('numero_ca', 'like', "%{$busca}%");
            });
        }

        $dados = [
            'epis' => $consulta->get(),
            'tipos' => PersonalProtectiveEquipment::TIPOS,
            'filtros' => [
                'situacao' => $situacao ?: null,
                'tipo' => $request->string('tipo')->toString() ?: null,
                'busca' => $request->string('busca')->toString() ?: null,
            ],
        ];

        if ($request->expectsJson()) {
            return response()->json($dados);
        }

        return Inertia::render('Epi/Index', $dados);
    }

    /**
     * Um modelo de EPI, com a contagem de entregas.
     *
     * Resposta sempre JSON, de propósito: não existe página `Epi/Show` — o
     * cadastro é editado em modal sobre a listagem (Task 28.6), e este endpoint
     * é o que carrega o registro para o modal.
     */
    public function show(PersonalProtectiveEquipment $epi): JsonResponse
    {
        return response()->json([
            'epi' => $epi->loadCount('ppeDeliveries'),
        ]);
    }

    public function store(PersonalProtectiveEquipmentRequest $request): RedirectResponse|JsonResponse
    {
        $epi = $this->epis->criar($request->validated());

        if ($request->expectsJson()) {
            return response()->json([
                'mensagem' => 'EPI cadastrado.',
                'epi' => $epi,
            ], 201);
        }

        return redirect()->route('epi.index')->with('success', 'EPI cadastrado.');
    }

    /**
     * Atualiza o cadastro, renovação do CA incluída.
     *
     * Renovar `numero_ca` e `validade_ca` aqui é operação normal e **não** mexe
     * em nenhuma entrega já registrada: a ficha guarda a própria cópia do CA,
     * feita no ato da entrega. É a decisão que torna esta operação inofensiva
     * sobre o histórico.
     */
    public function update(
        PersonalProtectiveEquipmentRequest $request,
        PersonalProtectiveEquipment $epi
    ): RedirectResponse|JsonResponse {
        $atualizado = $this->epis->atualizar($epi, $request->validated());

        if ($request->expectsJson()) {
            return response()->json([
                'mensagem' => 'EPI atualizado.',
                'epi' => $atualizado,
            ]);
        }

        return redirect()->route('epi.index')->with('success', 'EPI atualizado.');
    }

    /**
     * Tira o modelo da escolha de novas entregas, preservando as fichas já
     * emitidas. É o substituto da exclusão neste cadastro.
     */
    public function inativar(Request $request, PersonalProtectiveEquipment $epi): RedirectResponse|JsonResponse
    {
        $inativado = $this->epis->inativar($epi);

        return $this->confirmar($request, 'EPI inativado. As fichas já emitidas continuam válidas.', $inativado);
    }

    /**
     * Devolve o modelo à escolha de novas entregas.
     */
    public function reativar(Request $request, PersonalProtectiveEquipment $epi): RedirectResponse|JsonResponse
    {
        $reativado = $this->epis->reativar($epi);

        return $this->confirmar($request, 'EPI reativado.', $reativado);
    }

    /**
     * Exclui o cadastro, e só quando ele nunca foi entregue.
     *
     * Com entrega registrada, o Service recusa e a recusa chega ao usuário em
     * português, com o caminho certo (inativar), em vez de subir como erro de
     * integridade do MySQL.
     */
    public function destroy(Request $request, PersonalProtectiveEquipment $epi): RedirectResponse|JsonResponse
    {
        try {
            $this->epis->excluir($epi);
        } catch (EpiComEntregaException $recusa) {
            return $this->recusar($request, $recusa->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json(['mensagem' => 'EPI excluído.']);
        }

        return redirect()->route('epi.index')->with('success', 'EPI excluído.');
    }

    /**
     * Recusa de negócio: 422 com a mensagem pronta da exceção para quem chama
     * por fetch, e volta ao formulário com o erro no campo para quem chega pela
     * navegação Inertia. Mesmo critério do `StockController`.
     */
    private function recusar(Request $request, string $mensagem): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'mensagem' => $mensagem,
                'errors' => ['epi' => [$mensagem]],
            ], 422);
        }

        return back()->withInput()->withErrors(['epi' => $mensagem]);
    }

    private function confirmar(
        Request $request,
        string $mensagem,
        PersonalProtectiveEquipment $epi
    ): RedirectResponse|JsonResponse {
        if ($request->expectsJson()) {
            return response()->json([
                'mensagem' => $mensagem,
                'epi' => $epi,
            ]);
        }

        return back()->with('success', $mensagem);
    }
}
