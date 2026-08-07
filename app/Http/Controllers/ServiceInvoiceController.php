<?php

namespace App\Http\Controllers;

use App\Exceptions\FalhaFiscalException;
use App\Http\Requests\BatchReprocessServiceInvoiceRequest;
use App\Http\Requests\CancelServiceInvoiceRequest;
use App\Http\Requests\ListServiceInvoiceRequest;
use App\Http\Requests\StoreServiceInvoiceRequest;
use App\Http\Requests\SubstituteServiceInvoiceRequest;
use App\Models\Receivable;
use App\Models\ServiceInvoice;
use App\Models\WorkOrder;
use App\Services\Fiscal\CancelamentoDeNotaService;
use App\Services\FiscalPanelService;
use App\Services\ServiceInvoiceService;
use App\Support\Fiscal\MensagemFiscalPublica;
use App\Support\Fiscal\NotaFiscalPublica;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class ServiceInvoiceController extends Controller
{
    public function __construct(
        private readonly FiscalPanelService $painel,
        private readonly ServiceInvoiceService $notas,
        private readonly CancelamentoDeNotaService $cancelamentos,
    ) {}

    public function index(ListServiceInvoiceRequest $request): Response|JsonResponse
    {
        $dados = [
            'filtros' => $request->validated(),
            'notas' => $this->painel->listar($request->validated()),
            'clientes' => $this->painel->clientesParaFiltro(),
            'ambiente' => $this->painel->ambienteAtual(),
        ];

        return $request->expectsJson()
            ? response()->json($dados)
            : Inertia::render('Fiscal/Notas', $dados);
    }

    public function store(StoreServiceInvoiceRequest $request): JsonResponse|RedirectResponse
    {
        $dados = $request->validated();

        try {
            $nota = isset($dados['work_order_id'])
                ? $this->notas->emitirDaOs(WorkOrder::query()->findOrFail((int) $dados['work_order_id']))
                : $this->notas->emitirDoTitulo(Receivable::query()->findOrFail((int) $dados['receivable_id']));
        } catch (FalhaFiscalException $falha) {
            throw ValidationException::withMessages(['nota' => $falha->getMessage()]);
        } catch (ValidationException|AuthorizationException|ModelNotFoundException $falha) {
            throw $falha;
        } catch (Throwable $falha) {
            throw $this->falhaInterna($falha, 'emitir');
        }

        $criada = $nota->wasRecentlyCreated;
        $resultadoFiscal = $this->painel->resultadoFiscal($nota);
        $mensagem = match ($resultadoFiscal) {
            'erro' => MensagemFiscalPublica::deTextoPersistido($nota->erro_mensagem)
                ?: 'A nota ficou com pendência fiscal e precisa de correção.',
            'pendente' => $criada
                ? 'Nota fiscal encaminhada. A prefeitura ainda está processando a solicitação.'
                : 'A nota fiscal desta prestação já existe e continua em processamento.',
            default => $criada
                ? 'Nota fiscal emitida.'
                : 'A nota fiscal desta prestação já existe e foi reutilizada.',
        };

        return $this->confirmar(
            $request,
            $mensagem,
            $nota,
            $criada ? 201 : 200,
            ['resultado' => $criada ? 'criada' : 'reutilizada'],
        );
    }

    public function cancelar(CancelServiceInvoiceRequest $request, ServiceInvoice $nota): JsonResponse|RedirectResponse
    {
        try {
            $cancelada = $this->cancelamentos->cancelar($nota, (string) $request->validated()['motivo']);
        } catch (FalhaFiscalException $falha) {
            throw ValidationException::withMessages(['nota' => $falha->getMessage()]);
        } catch (ValidationException|AuthorizationException|ModelNotFoundException $falha) {
            throw $falha;
        } catch (Throwable $falha) {
            throw $this->falhaInterna($falha, 'cancelar', $nota);
        }

        $pendente = $cancelada->situacao === 'cancelamento_pendente';

        return $this->confirmar(
            $request,
            $pendente
                ? 'Cancelamento fiscal solicitado e aguardando autorização.'
                : 'Cancelamento fiscal concluído.',
            $cancelada,
            $pendente ? 202 : 200,
            ['resultado' => $pendente ? 'pendente' : 'concluido'],
        );
    }

    public function substituir(SubstituteServiceInvoiceRequest $request, ServiceInvoice $nota): JsonResponse|RedirectResponse
    {
        try {
            $substituta = $this->cancelamentos->substituir($nota, $request->validated());
        } catch (FalhaFiscalException $falha) {
            throw ValidationException::withMessages(['nota' => $falha->getMessage()]);
        } catch (ValidationException|AuthorizationException|ModelNotFoundException $falha) {
            throw $falha;
        } catch (Throwable $falha) {
            throw $this->falhaInterna($falha, 'substituir', $nota);
        }

        $criada = $substituta->wasRecentlyCreated;

        return $this->confirmar(
            $request,
            $criada ? 'Substituição fiscal encaminhada.' : 'A substituição fiscal existente foi reutilizada.',
            $substituta,
            $criada ? 201 : 200,
            ['resultado' => $criada ? 'criada' : 'reutilizada'],
        );
    }

    public function reprocessar(Request $request, ServiceInvoice $nota): JsonResponse|RedirectResponse
    {
        try {
            $nova = $this->painel->reprocessar($nota);
        } catch (FalhaFiscalException $falha) {
            throw ValidationException::withMessages(['nota' => $falha->getMessage()]);
        } catch (ValidationException|AuthorizationException|ModelNotFoundException $falha) {
            throw $falha;
        } catch (Throwable $falha) {
            throw $this->falhaInterna($falha, 'reprocessar', $nota);
        }

        $resultadoFiscal = $this->painel->resultadoFiscal($nova);
        $mensagem = match ($resultadoFiscal) {
            'erro' => MensagemFiscalPublica::deTextoPersistido($nova->erro_mensagem)
                ?: 'A nova tentativa continua com pendência fiscal.',
            'pendente' => 'Pendência reprocessada. A prefeitura ainda está processando a nova nota.',
            default => 'Pendência reprocessada e concluída.',
        };

        return $this->confirmar($request, $mensagem, $nova);
    }

    public function pendencias(Request $request): Response|JsonResponse
    {
        $dados = [
            'grupos' => $this->painel->pendencias(),
            'ambiente' => $this->painel->ambienteAtual(),
        ];

        return $request->expectsJson()
            ? response()->json($dados)
            : Inertia::render('Fiscal/Pendencias', $dados);
    }

    public function reprocessarPendencias(BatchReprocessServiceInvoiceRequest $request): JsonResponse
    {
        $dados = $request->validated();
        $resultado = $this->painel->reprocessarEmLote(
            array_map('intval', $dados['nota_ids'] ?? []),
            $dados['motivo'] ?? null,
        );

        return response()->json([
            'message' => count($resultado['falhas']) === 0
                ? 'Pendências fiscais reprocessadas.'
                : 'O lote terminou com pendências que ainda precisam de correção.',
            ...$resultado,
        ]);
    }

    public function pdf(ServiceInvoice $nota): BinaryFileResponse
    {
        return $this->download($nota, 'pdf');
    }

    public function xml(ServiceInvoice $nota): BinaryFileResponse
    {
        return $this->download($nota, 'xml');
    }

    private function download(ServiceInvoice $nota, string $tipo): BinaryFileResponse
    {
        try {
            $arquivo = $this->painel->arquivo($nota, $tipo);
        } catch (FalhaFiscalException $falha) {
            abort(409, $falha->getMessage());
        } catch (Throwable $falha) {
            abort(500, MensagemFiscalPublica::deFalha($falha, [
                'service_invoice_id' => $nota->id,
                'operacao' => "endpoint_download_{$tipo}",
            ]));
        }

        return response()->download(
            $arquivo['caminho'],
            $arquivo['nome'],
            ['Content-Type' => $arquivo['mime']],
        );
    }

    private function confirmar(
        Request $request,
        string $mensagem,
        ServiceInvoice $nota,
        int $status = 200,
        array $dadosAdicionais = [],
    ): JsonResponse|RedirectResponse {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $mensagem,
                'resultado_fiscal' => $this->painel->resultadoFiscal($nota),
                'nota' => NotaFiscalPublica::de($nota->refresh()),
                ...$dadosAdicionais,
            ], $status);
        }

        $tipo = $this->painel->resultadoFiscal($nota) === 'erro' ? 'error' : 'success';

        return back()->with($tipo, $mensagem);
    }

    private function falhaInterna(
        Throwable $falha,
        string $operacao,
        ?ServiceInvoice $nota = null,
    ): ValidationException {
        return ValidationException::withMessages([
            'nota' => MensagemFiscalPublica::deFalha($falha, array_filter([
                'service_invoice_id' => $nota?->id,
                'operacao' => "endpoint_{$operacao}",
            ], static fn (mixed $valor): bool => $valor !== null)),
        ]);
    }
}
