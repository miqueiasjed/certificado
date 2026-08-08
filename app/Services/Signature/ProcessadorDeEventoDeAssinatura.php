<?php

namespace App\Services\Signature;

use App\Models\SignatureEvent;
use App\Models\SignatureProviderConfig;
use App\Models\SignatureRequest;
use App\Services\SignatureRequestService;
use App\Support\TenantAtual;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Registro e processamento dos eventos que o provedor de assinatura manda por
 * webhook (Plano 26, Task 26.3).
 *
 * Irmão direto de `App\Services\Payments\ProcessadorDeEventoDeCobranca`
 * (Plano 19, Task 19.4), com a mesma disciplina de idempotência, o mesmo
 * "nunca lança" e a mesma defesa de tenant. Há uma diferença que vale
 * entender, e ela é a razão de este processador ser tão curto: aqui o corpo do
 * webhook **não** é interpretado. Dele sai só o identificador do documento; o
 * que aconteceu com o documento vem de uma consulta autenticada ao provedor,
 * feita por `SignatureRequestService::sincronizar()`. Um POST forjado com o
 * token certo não consegue marcar contrato como assinado, porque nada do que
 * ele diz é gravado.
 *
 * ## O tenant vem do token da URL, nunca do corpo
 *
 * `processar()` executa inteiro dentro de
 * `TenantAtual::comTenant($configuracao->company_id, ...)`. Enquanto esse
 * escopo estiver ligado, toda consulta a `SignatureRequest`/`SignatureSigner`/
 * `Contract` deste arquivo só enxerga o tenant do token — inclusive se alguém
 * esquecer um `where('company_id', ...)` explícito, o que é exatamente o
 * ponto: a defesa não depende de lembrança. Um webhook cujo `token` de
 * documento pertence a outra empresa simplesmente não encontra pedido nenhum.
 *
 * ## Idempotência: as mesmas três barreiras do Plano 19
 *
 * 1. **`signature_events.[company_id, evento_id]` é unique**, e `registrar()`
 *    grava com `firstOrCreate`.
 * 2. **`processado_em`**, conferido por quem chama antes de pedir o
 *    processamento (`AssinaturaWebhookController`).
 * 3. **A convergência de `aplicarDocumento()`**: aplicar o mesmo documento
 *    duas vezes leva ao mesmo estado, e o download do arquivo assinado só
 *    acontece uma vez, porque a segunda passada encontra
 *    `arquivo_assinado_path` já preenchido. É esta terceira que segura duas
 *    entregas simultâneas do mesmo evento, que passariam juntas pela barreira
 *    2 antes de qualquer uma gravar `processado_em`.
 *
 * ## Erro de processamento não é erro de requisição
 *
 * Nenhuma exceção sai deste Service. Falha em qualquer parte grava a mensagem
 * em `SignatureEvent.erro`, incrementa `tentativas` e para por aí, para que o
 * controller devolva 200 ao provedor: 500 faria o provedor reenviar o mesmo
 * evento em laço, e o payload inteiro já está gravado, pronto para
 * reprocessar. A rede de segurança que fecha o caso é a rotina
 * `assinaturas:sincronizar`, que percorre os pedidos em aberto de novo.
 */
class ProcessadorDeEventoDeAssinatura
{
    public function __construct(
        private readonly SignatureRequestService $signatureRequestService,
    ) {}

    /**
     * Grava o evento recebido, ou devolve o que já estava gravado.
     *
     * Primeira barreira da idempotência. Grava o payload **cru**, nunca algo
     * interpretado: o cru permite reprocessar depois de corrigir um bug de
     * leitura.
     *
     * `company_id` vem de `$configuracao`, nunca de dentro do payload: é o
     * tenant do token da URL que decide de quem é o evento.
     *
     * @param  array<string, mixed>  $payload
     */
    public function registrar(
        SignatureProviderConfig $configuracao,
        ?string $documentoNoProvedor,
        string $tipo,
        array $payload,
    ): SignatureEvent {
        return TenantAtual::comTenant(
            $configuracao->company_id,
            function () use ($configuracao, $documentoNoProvedor, $tipo, $payload): SignatureEvent {
                $pedido = $this->localizarPedido($documentoNoProvedor);

                return SignatureEvent::firstOrCreate(
                    ['evento_id' => $this->identificadorDoEvento($configuracao, $documentoNoProvedor, $tipo, $payload)],
                    [
                        'provedor' => $configuracao->provedor,
                        'tipo' => $tipo,
                        'signature_request_id' => $pedido?->getKey(),
                        'payload' => $payload,
                    ]
                );
            }
        );
    }

    /**
     * Sincroniza o pedido com o provedor e marca o evento como processado.
     *
     * Não lança: falha vira `erro` e `tentativas` na própria linha do evento.
     */
    public function processar(SignatureEvent $evento, SignatureProviderConfig $configuracao): void
    {
        TenantAtual::comTenant($configuracao->company_id, function () use ($evento, $configuracao): void {
            try {
                $pedido = $evento->signature_request_id !== null
                    ? SignatureRequest::find($evento->signature_request_id)
                    : null;

                if (! $pedido instanceof SignatureRequest) {
                    // Documento que não é desta empresa, ou pedido que nunca
                    // existiu aqui. Fica registrado como processado, sem
                    // efeito: reprocessar não mudaria nada, e deixar
                    // pendente encheria a tela de conciliação de ruído.
                    $evento->forceFill([
                        'processado_em' => now(),
                        'erro' => 'Nenhum pedido de assinatura desta empresa corresponde ao documento do evento.',
                    ])->save();

                    return;
                }

                $this->signatureRequestService->sincronizar($pedido, $configuracao);

                $evento->forceFill(['processado_em' => now(), 'erro' => null])->save();
            } catch (Throwable $excecao) {
                Log::warning('assinatura.evento_nao_processado', [
                    'signature_event_id' => $evento->getKey(),
                    'company_id' => $configuracao->company_id,
                    'tipo' => $evento->tipo,
                    'erro' => $excecao->getMessage(),
                ]);

                $evento->forceFill([
                    'erro' => $excecao->getMessage(),
                    'tentativas' => (int) $evento->tentativas + 1,
                ])->save();
            }
        });
    }

    /**
     * Pedido deste tenant cujo documento no provedor é o informado.
     *
     * A consulta roda dentro do escopo do tenant do token: documento de outra
     * empresa não é encontrado, e é assim que um webhook não alcança contrato
     * alheio.
     */
    private function localizarPedido(?string $documentoNoProvedor): ?SignatureRequest
    {
        if (blank($documentoNoProvedor)) {
            return null;
        }

        return SignatureRequest::query()
            ->where('provedor_documento_id', $documentoNoProvedor)
            ->latest('id')
            ->first();
    }

    /**
     * Chave de deduplicação do evento.
     *
     * A ZapSign não manda identificador de evento próprio, então ele é
     * sintético, montado a partir do documento, do tipo e do estado que o
     * corpo informa — mesma técnica de
     * `GatewayPagBank::identificadorSinteticoDeCobranca()` (Plano 19). O
     * estado entra na chave para que dois eventos legítimos e diferentes do
     * mesmo documento (visualizado, depois assinado) não sejam confundidos com
     * uma reentrega do mesmo evento.
     *
     * O `company_id` não entra na string: a unique do banco já é composta com
     * ele, e é a composição que garante que dois tenants não colidam.
     *
     * @param  array<string, mixed>  $payload
     */
    private function identificadorDoEvento(
        SignatureProviderConfig $configuracao,
        ?string $documentoNoProvedor,
        string $tipo,
        array $payload,
    ): string {
        $partes = array_filter([
            $configuracao->provedor,
            $documentoNoProvedor,
            $tipo,
            $this->textoOuNulo(data_get($payload, 'status')),
            $this->textoOuNulo(data_get($payload, 'signer.token')),
        ], static fn (?string $parte): bool => $parte !== null && $parte !== '');

        if ($partes === []) {
            return 'assinatura-'.hash('sha256', json_encode($payload) ?: '');
        }

        return implode('-', $partes);
    }

    private function textoOuNulo(mixed $valor): ?string
    {
        return is_scalar($valor) && trim((string) $valor) !== '' ? trim((string) $valor) : null;
    }
}
