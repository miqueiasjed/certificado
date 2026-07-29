<?php

namespace App\Services\Sync;

use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderSignature;
use App\Services\WorkOrderSignatureService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Aplica uma operação de sincronização do tipo `assinatura` (Plano 13,
 * Task 13.3), ligando a coleta feita no aplicativo do técnico ao
 * `WorkOrderSignatureService::coletar()`.
 *
 * Nenhuma regra de negócio é reimplementada aqui: OS não concluída, dados
 * obrigatórios ausentes ou imagem inválida são recusados pelo próprio
 * `WorkOrderSignatureService`, e a exceção de validação sobe intacta para
 * `AppSyncService`, que a transforma em `sync_conflict` com motivo
 * `regra_de_negocio` (mesmo contrato de `AplicadorDeOperacao::aplicar()`).
 *
 * Coordenada (latitude, longitude, precisão) só é repassada quando o técnico
 * autorizou a localização no aparelho e o payload realmente trouxe o campo:
 * o servidor nunca infere localização por IP para completá-la.
 */
class AplicadorDeAssinatura implements AplicadorDeOperacao
{
    /**
     * Campos de coordenada, só repassados quando presentes e não nulos no
     * payload.
     */
    private const CAMPOS_DE_COORDENADA = ['latitude', 'longitude', 'precisao_metros'];

    public function __construct(
        private readonly WorkOrderSignatureService $workOrderSignatureService,
    ) {}

    public function tipo(): string
    {
        return 'assinatura';
    }

    public function aplicar(array $payload, User $usuario): Model
    {
        $os = $this->resolverOrdemDeServico($payload);

        $dados = [
            'assinante_nome' => $payload['assinante_nome'] ?? null,
            'assinante_documento' => $payload['assinante_documento'] ?? null,
            'assinante_vinculo' => $payload['assinante_vinculo'] ?? null,
            'imagem' => $payload['imagem'] ?? null,
            'coletada_em' => $payload['coletada_em'] ?? $payload['registrada_em'] ?? null,
            'origem' => 'aplicativo',
        ];

        foreach (self::CAMPOS_DE_COORDENADA as $campo) {
            if (array_key_exists($campo, $payload) && $payload[$campo] !== null) {
                $dados[$campo] = $payload[$campo];
            }
        }

        // Sem justificativa no payload comum: se esta operação chegar a uma
        // OS que já está assinada (recoleta), `coletar()` trata isso como
        // correção justificada e recusa por falta de justificativa/permissão,
        // igual a qualquer outra recoleta. `justificativa` só é repassada
        // quando o aplicativo mandar explicitamente (ver docblock de
        // `WorkOrderSignatureService::coletar()`).
        $justificativa = isset($payload['justificativa']) ? (string) $payload['justificativa'] : null;

        /** @var WorkOrderSignature $assinatura */
        $assinatura = $this->workOrderSignatureService->coletar($os, $dados, $usuario, $justificativa);

        return $assinatura;
    }

    /**
     * `AppSyncService` já validou a existência e o acesso à OS antes de
     * chamar este aplicador, e injeta `work_order_id` no payload a partir
     * dela (nunca a partir do que o aplicativo mandou). Este método só
     * refaz a busca porque `coletar()` precisa da instância do Model, não
     * apenas do id.
     */
    private function resolverOrdemDeServico(array $payload): WorkOrder
    {
        $workOrderId = $payload['work_order_id'] ?? null;

        if (! is_numeric($workOrderId)) {
            throw ValidationException::withMessages([
                'work_order_id' => 'A operação de assinatura não veio ligada a nenhuma ordem de serviço.',
            ]);
        }

        $os = WorkOrder::find((int) $workOrderId);

        if ($os === null) {
            throw new RuntimeException(
                "Ordem de serviço {$workOrderId} não encontrada para aplicar a assinatura."
            );
        }

        return $os;
    }
}
