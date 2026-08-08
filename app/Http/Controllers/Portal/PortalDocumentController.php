<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\ClientUser;
use App\Models\Contract;
use App\Models\PaymentDetail;
use App\Models\WorkOrder;
use App\Services\CertificateService;
use App\Services\ContractService;
use App\Services\PortalService;
use App\Services\SignatureRequestService;
use App\Services\WorkOrderService;
use Barryvdh\DomPDF\Facade\Pdf as FacadePdf;
use Barryvdh\DomPDF\PDF;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Download dos documentos do cliente (Task 15.4): visita (OS), certificado,
 * contrato e fatura (recibo).
 *
 * Sempre validados por `PortalService::modeloDoDocumento()` antes de
 * qualquer PDF ser montado: documento de outro cliente, de outra empresa, ou
 * tipo desconhecido, nunca chega a gerar nada, e a `ModelNotFoundException`
 * lançada pelo Service vira 404 sozinha, sem tratamento nenhum aqui (ver o
 * docblock de `PortalService`).
 *
 * Cada tipo reaproveita o mesmo `preparePdfData()`/`prepareReceiptData()` e o
 * mesmo template Blade que o sistema interno já usa para o mesmo documento
 * (`CertificateService`, `WorkOrderService`, `ContractService`): o cliente
 * recebe exatamente o PDF que a empresa também emitiria, não uma segunda
 * versão para manter.
 *
 * Nome do arquivo sempre "Tipo-AAAA-MM-DD.pdf", sem id nem hash: o cliente
 * arquiva esse PDF localmente e precisa reconhecer o conteúdo pelo nome meses
 * depois, e o id interno do banco não ajuda nisso.
 */
class PortalDocumentController extends Controller
{
    private readonly PortalService $portal;

    public function __construct(
        private readonly CertificateService $certificados,
        private readonly WorkOrderService $ordensDeServico,
        private readonly ContractService $contratos,
    ) {
        $this->portal = new PortalService($this->clienteAutenticado());
    }

    public function download(string $tipo, int $id): Response
    {
        $modelo = $this->portal->modeloDoDocumento($tipo, $id);

        [$pdf, $nomeArquivo] = match (true) {
            $modelo instanceof WorkOrder => $this->pdfDaVisita($modelo),
            $modelo instanceof Certificate => $this->pdfDoCertificado($modelo),
            $modelo instanceof Contract => $this->pdfDoContrato($modelo),
            $modelo instanceof PaymentDetail => $this->pdfDaFatura($modelo),
        };

        $this->registrarDownloadNaAuditoria($tipo, $modelo);

        // `->download()`, nunca `->stream()`: é o método do pacote que grava
        // `Content-Disposition: attachment` (stream() grava `inline`, para
        // abrir no navegador em vez de baixar).
        return $pdf->download($nomeArquivo);
    }

    /**
     * Via assinada do contrato (Plano 26, Task 26.4).
     *
     * Método próprio, e não mais um tipo de `download()`, porque a natureza
     * do arquivo é outra: aqui nada é gerado. O PDF assinado foi produzido
     * pelo provedor, baixado no ato da conclusão e arquivado em disco privado
     * (`SignatureRequestService`), e é esse byte a byte que o cliente precisa
     * receber — é ele que tem a assinatura e a trilha de auditoria. Gerar o
     * contrato de novo aqui, como faz `pdfDoContrato()`, devolveria um PDF sem
     * assinatura nenhuma com cara de documento assinado.
     *
     * A regra de dono é a de sempre: `PortalService` resolve o pedido a partir
     * do contrato do cliente autenticado, e contrato de outro cliente ou sem
     * via assinada vira 404 pela `ModelNotFoundException`.
     */
    public function contratoAssinado(int $id): Response
    {
        $pedido = $this->portal->pedidoDeAssinaturaAssinado($id);
        $caminho = (string) $pedido->arquivo_assinado_path;

        // O caminho é conferido contra o formato que o Service grava antes de
        // qualquer leitura: caminho vindo de coluna nunca é usado cru para
        // abrir arquivo. Mesmo critério de
        // `DriverDeEmail::contratoAssinadoDoContexto()`.
        $esperado = "contratos/assinatura/{$pedido->company_id}/{$pedido->id}-assinado.pdf";

        if (! hash_equals($esperado, $caminho) || ! Storage::disk(SignatureRequestService::DISCO)->exists($caminho)) {
            abort(404);
        }

        $this->registrarDownloadNaAuditoria('contrato_assinado', $pedido->contract);

        return response(
            Storage::disk(SignatureRequestService::DISCO)->get($caminho),
            200,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="Contrato-'
                    .($pedido->contract?->contract_number ?: $pedido->contract_id).'-assinado.pdf"',
            ]
        );
    }

    /**
     * @return array{0: PDF, 1: string}
     */
    private function pdfDaVisita(WorkOrder $visita): array
    {
        $dados = $this->ordensDeServico->preparePdfData($visita);
        $pdf = FacadePdf::loadView('pdf.work-order', $dados)->setPaper('a4', 'portrait');

        return [$pdf, 'Visita-'.$visita->scheduled_date?->toDateString().'.pdf'];
    }

    /**
     * @return array{0: PDF, 1: string}
     */
    private function pdfDoCertificado(Certificate $certificado): array
    {
        $dados = $this->certificados->preparePdfData($certificado);
        $pdf = FacadePdf::loadView('pdf.certificate', $dados)->setPaper('a4', 'landscape');

        return [$pdf, 'Certificado-'.$certificado->execution_date?->toDateString().'.pdf'];
    }

    /**
     * @return array{0: PDF, 1: string}
     */
    private function pdfDoContrato(Contract $contrato): array
    {
        $dados = $this->contratos->preparePdfData($contrato);

        // `preparePdfData()` devolve só o contrato (e a empresa); endereço e
        // cliente são o mesmo complemento que `ContractController::generatePDF()`
        // já faz manualmente antes de renderizar o template, e o template
        // `pdf.contract` espera as duas chaves.
        $dados['address'] = $contrato->address;
        $dados['client'] = $contrato->address?->client;

        $pdf = FacadePdf::loadView('pdf.contract', $dados)->setPaper('a4', 'portrait');

        return [$pdf, 'Contrato-'.$contrato->start_date?->toDateString().'.pdf'];
    }

    /**
     * `pdf.receipt` é o recibo do que já foi pago, não a cobrança em aberto:
     * sem `payment_date` gravada nesta parcela, não existe recibo ainda para
     * baixar. A resposta diz exatamente isso (409), sem fingir que o
     * documento não existe - o 404 fica reservado para "não é seu" (ver
     * `PortalService`), nunca para "ainda não está pronto".
     *
     * @return array{0: PDF, 1: string}
     */
    private function pdfDaFatura(PaymentDetail $fatura): array
    {
        if ($fatura->payment_date === null) {
            abort(409, 'Esta fatura ainda não está paga, então o recibo ainda não está disponível. '
                .'Assim que o pagamento for confirmado, o recibo pode ser baixado aqui.');
        }

        $dados = $this->ordensDeServico->prepareReceiptData($fatura->workOrder);
        $pdf = FacadePdf::loadView('pdf.receipt', $dados)->setPaper('a4', 'landscape');

        return [$pdf, 'Recibo-'.$fatura->payment_date->toDateString().'.pdf'];
    }

    /**
     * Registra quem baixou e quando (Task 15.4: "cliente que alega não ter
     * recebido documento é discussão comum").
     *
     * `user_id` fica nulo de propósito: a coluna tem chave estrangeira para
     * `users` (tabela dos funcionários), e o ator aqui é um `ClientUser`,
     * tabela separada por desenho (ver o docblock do model). Gravar o id de
     * um `ClientUser` ali arriscaria estourar a constraint (quando o id não
     * existir em `users`) ou, pior, referenciar por acidente o funcionário
     * errado caso os ids coincidam. A identificação de quem baixou vai
     * inteira, em texto, em `autor_nome`.
     *
     * `auditable_type`/`auditable_id` apontam para o documento em si (mesmo
     * model já auditado por criação/alteração/exclusão via `Auditavel`), e
     * não para o download em si: este evento entra no mesmo histórico que
     * `$model->auditLogs()` já expõe, só com uma ação nova.
     *
     * Nunca derruba o download por falha de auditoria - mesmo padrão de
     * `Auditavel::auditarEvento()` e de `AppSyncService`: o essencial (o
     * cliente recebeu o arquivo) não pode depender do acessório.
     */
    private function registrarDownloadNaAuditoria(
        string $tipo,
        WorkOrder|Certificate|Contract|PaymentDetail $modelo
    ): void {
        try {
            $cliente = $this->clienteAutenticado();
            $userAgent = request()->userAgent();

            AuditLog::create([
                'auditable_type' => $modelo::class,
                'auditable_id' => $modelo->getKey(),
                'acao' => 'download_portal',
                'user_id' => null,
                'autor_nome' => sprintf('Cliente do portal: %s (%s)', $cliente->nome, $cliente->email),
                'valores_antes' => null,
                'valores_depois' => ['tipo' => $tipo],
                'ip' => request()->ip(),
                'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 255),
            ]);
        } catch (Throwable $erro) {
            Log::error('Falha ao registrar em auditoria o download de documento do portal.', [
                'tipo' => $tipo,
                'modelo' => $modelo::class,
                'id' => $modelo->getKey(),
                'erro' => $erro->getMessage(),
            ]);
        }
    }

    private function clienteAutenticado(): ClientUser
    {
        /** @var ClientUser $clientUser */
        $clientUser = Auth::guard('cliente')->user();

        return $clientUser;
    }
}
