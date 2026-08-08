<?php

namespace App\Http\Controllers;

use App\Exceptions\AssinaturaEletronicaIndisponivelException;
use App\Exceptions\AssinaturaEletronicaRecusouException;
use App\Exceptions\ContratoEmAssinaturaException;
use App\Exceptions\ProvedorDeAssinaturaNaoConfiguradoException;
use App\Http\Requests\EnviarContratoParaAssinaturaRequest;
use App\Models\Contract;
use App\Models\SignatureProviderConfig;
use App\Models\SignatureRequest;
use App\Models\SignatureSigner;
use App\Services\SignatureRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Envio, acompanhamento, reenvio e cancelamento do pedido de assinatura
 * eletrônica de um contrato (Plano 26, Task 26.4).
 *
 * Controller fino: toda a regra está em `SignatureRequestService`. O que
 * acontece aqui é a tradução das exceções de domínio em código HTTP, e é uma
 * tradução com critério, não um `try/catch` genérico:
 *
 * - **422** para o que o usuário pode corrigir agora, na tela: contrato já em
 *   assinatura, pedido já encerrado, provedor não configurado, e-mail de
 *   signatário recusado pelo provedor.
 * - **503** para indisponibilidade do provedor: não é erro de quem clicou, e
 *   é o único caso em que "tente de novo" é um conselho honesto.
 *
 * `{contract}` e `{assinatura}` chegam por route-model binding, e o escopo
 * global por empresa (`BelongsToCompany`) já garante que contrato ou pedido de
 * outra empresa devolva 404 — a defesa entre tenants não depende de nenhuma
 * checagem escrita aqui. O que **não** vem de graça é o vínculo entre os dois:
 * `assinaturaDoContrato()` confere que o pedido é daquele contrato antes de
 * qualquer ação, para que um id de pedido de outro contrato da mesma empresa
 * não seja cancelado por engano.
 */
class SignatureRequestController extends Controller
{
    public function __construct(
        private readonly SignatureRequestService $pedidos,
    ) {}

    /**
     * Situação detalhada do pedido atual do contrato, signatário a
     * signatário.
     */
    public function show(Contract $contract): JsonResponse
    {
        $pedido = $contract->signatureRequests()->with('signers')->first();

        return response()->json([
            'contrato' => [
                'id' => $contract->id,
                'contract_number' => $contract->contract_number,
                'situacao_assinatura' => $contract->situacao_assinatura,
                'assinado_em' => $contract->assinado_em?->toIso8601String(),
            ],
            'pedido' => $pedido === null ? null : $this->pedidoEmArray($pedido),
            // A tela precisa avisar, em destaque, que o ambiente é de teste:
            // documento assinado em sandbox não tem validade jurídica.
            'ambiente' => $this->ambienteConfigurado(),
        ]);
    }

    /**
     * Envia o contrato para assinatura.
     */
    public function store(EnviarContratoParaAssinaturaRequest $request, Contract $contract): JsonResponse
    {
        if ($contract->situacao_assinatura === 'assinado') {
            return $this->recusar(
                'Este contrato já foi assinado por todas as partes e não pode ser enviado de novo. '
                .'Para um contrato novo, gere uma renovação.'
            );
        }

        try {
            $pedido = $this->pedidos->enviar(
                $contract,
                $request->validated('signatarios'),
                (int) ($request->validated('dias_para_expirar') ?? SignatureRequestService::DIAS_PARA_EXPIRAR_PADRAO),
                Auth::user(),
                $request->validated('mensagem'),
            );
        } catch (ContratoEmAssinaturaException|ProvedorDeAssinaturaNaoConfiguradoException|AssinaturaEletronicaRecusouException $erro) {
            return $this->recusar($erro->getMessage());
        } catch (AssinaturaEletronicaIndisponivelException $erro) {
            return $this->recusar($erro->getMessage(), 503);
        }

        return response()->json([
            'message' => 'Contrato enviado para assinatura. Os signatários vão receber o convite por e-mail.',
            'pedido' => $this->pedidoEmArray($pedido->load('signers')),
        ], 201);
    }

    /**
     * Manda o provedor notificar de novo quem ainda não assinou. **Não cria
     * pedido novo** — ver `SignatureRequestService::reenviar()`.
     */
    public function reenviar(Contract $contract, SignatureRequest $assinatura): JsonResponse
    {
        $assinatura = $this->assinaturaDoContrato($contract, $assinatura);

        try {
            $pedido = $this->pedidos->reenviar($assinatura);
        } catch (ContratoEmAssinaturaException|AssinaturaEletronicaRecusouException $erro) {
            return $this->recusar($erro->getMessage());
        } catch (AssinaturaEletronicaIndisponivelException $erro) {
            return $this->recusar($erro->getMessage(), 503);
        }

        return response()->json([
            'message' => 'Aviso reenviado a quem ainda não assinou.',
            'pedido' => $this->pedidoEmArray($pedido->load('signers')),
        ]);
    }

    /**
     * Cancela o pedido no provedor e devolve o contrato ao estado editável.
     */
    public function cancelar(Request $request, Contract $contract, SignatureRequest $assinatura): JsonResponse
    {
        $assinatura = $this->assinaturaDoContrato($contract, $assinatura);

        $motivo = $request->input('motivo');
        $motivo = is_string($motivo) && trim($motivo) !== '' ? mb_substr(trim($motivo), 0, 500) : null;

        try {
            $pedido = $this->pedidos->cancelar($assinatura, $motivo);
        } catch (ContratoEmAssinaturaException|AssinaturaEletronicaRecusouException $erro) {
            return $this->recusar($erro->getMessage());
        } catch (AssinaturaEletronicaIndisponivelException $erro) {
            return $this->recusar($erro->getMessage(), 503);
        }

        return response()->json([
            'message' => 'Pedido de assinatura cancelado. O contrato voltou a aceitar alteração.',
            'pedido' => $this->pedidoEmArray($pedido->load('signers')),
        ]);
    }

    /**
     * Baixa o documento do pedido: o assinado quando já existe, o original
     * enquanto a assinatura não terminou.
     *
     * Serve o arquivo arquivado, nunca gera nada e nunca redireciona para o
     * provedor: o link dele expira em minutos, e a via que vale é a que está
     * em disco. O caminho gravado é conferido contra o formato esperado antes
     * de qualquer leitura — caminho vindo de coluna nunca é usado cru para
     * abrir arquivo.
     */
    public function documento(Contract $contract, SignatureRequest $assinatura): StreamedResponse
    {
        $assinatura = $this->assinaturaDoContrato($contract, $assinatura);

        $assinado = filled($assinatura->arquivo_assinado_path);
        $caminho = (string) ($assinado ? $assinatura->arquivo_assinado_path : $assinatura->arquivo_original_path);
        $sufixo = $assinado ? 'assinado' : 'original';

        $esperado = "contratos/assinatura/{$assinatura->company_id}/{$assinatura->id}-{$sufixo}.pdf";

        abort_unless(
            $caminho !== '' && hash_equals($esperado, $caminho)
                && Storage::disk(SignatureRequestService::DISCO)->exists($caminho),
            404
        );

        return Storage::disk(SignatureRequestService::DISCO)->download(
            $caminho,
            'Contrato-'.($contract->contract_number ?: $contract->id).'-'.$sufixo.'.pdf'
        );
    }

    /**
     * Confere que o pedido pertence ao contrato da rota.
     *
     * O escopo global por empresa já barra pedido de outro tenant (404 no
     * binding). Esta checagem cobre o que ele não cobre: um id de pedido de
     * **outro contrato da mesma empresa**, que passaria pelo binding sem
     * problema e cancelaria a assinatura errada.
     */
    private function assinaturaDoContrato(Contract $contract, SignatureRequest $assinatura): SignatureRequest
    {
        abort_unless((int) $assinatura->contract_id === (int) $contract->getKey(), 404);

        return $assinatura;
    }

    /**
     * @return array<string, mixed>
     */
    private function pedidoEmArray(SignatureRequest $pedido): array
    {
        return [
            'id' => $pedido->id,
            'situacao' => $pedido->situacao,
            'provedor' => $pedido->provedor,
            'enviado_em' => $pedido->enviado_em?->toIso8601String(),
            'expira_em' => $pedido->expira_em?->toDateString(),
            'concluido_em' => $pedido->concluido_em?->toIso8601String(),
            'motivo_recusa' => $pedido->motivo_recusa,
            'tem_arquivo_assinado' => filled($pedido->arquivo_assinado_path),
            // `provedor_documento_id` fica de fora: é identificador de
            // sistema externo, não ajuda quem olha a tela, e expô-lo só
            // aumentaria a superfície de quem tentar adivinhar documento.
            'signatarios' => $pedido->signers->map(fn (SignatureSigner $signatario): array => [
                'id' => $signatario->id,
                'nome' => $signatario->nome,
                'email' => $signatario->email,
                'papel' => $signatario->papel,
                'ordem' => $signatario->ordem,
                'situacao' => $signatario->situacao,
                // A trilha de auditoria é o que dá valor jurídico à
                // assinatura a distância, e por isso aparece na tela.
                'assinado_em' => $signatario->assinado_em?->toIso8601String(),
                'ip' => $signatario->ip,
                'user_agent' => $signatario->user_agent,
            ])->all(),
        ];
    }

    /**
     * Ambiente do provedor configurado, ou `null` quando não há configuração
     * ativa. Nunca devolve nada da credencial.
     */
    private function ambienteConfigurado(): ?string
    {
        $configuracao = SignatureProviderConfig::query()->where('ativo', true)->first();

        return $configuracao?->ambiente;
    }

    private function recusar(string $mensagem, int $status = 422): JsonResponse
    {
        return response()->json(['message' => $mensagem], $status);
    }
}
