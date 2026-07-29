<?php

namespace App\Services\Sync;

use App\Models\User;
use App\Models\WorkOrderAdequation;
use App\Models\WorkOrderPhoto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Aplica uma operação de sincronização do tipo `foto` (Plano 12, Task 12.4).
 *
 * O arquivo binário nunca viaja dentro do lote de operações: ele chega por
 * uma requisição própria (`POST /api/app/fotos`, Task 12.5), do mesmo jeito
 * que uma foto embutida em base64 no lote aumentaria o tamanho em um terço e
 * faria o lote inteiro falhar por causa de uma imagem. O que chega aqui é a
 * metadata da foto já gravada em disco por aquela requisição: o caminho
 * (`path`) devolvido por ela, o tipo e o id da entidade dona da foto, o
 * cômodo (quando for foto de evento de cômodo) e a legenda opcional. É o
 * mesmo contrato de campos que `WorkOrderPhotoController::store()` grava hoje
 * pelo painel web.
 *
 * ## Foto de adequação: `entity_id` pode chegar como uuid (Task 13.8)
 *
 * O técnico gera o uuid da adequação no aparelho e tira a foto dela antes de
 * a adequação ter `id` no servidor (os dois são offline, sem ordem garantida
 * de sincronização entre a operação `adequacao` e a operação `foto`). Por
 * isso, quando `entity_type === 'adequation'` e o `entity_id` recebido não é
 * um número, ele é resolvido como o `uuid` de `work_order_adequations`
 * (gravado por `AplicadorDeAdequacao`) para o `id` real antes de gravar
 * `work_order_photos.entity_id`, que é uma coluna inteira. Sem esta
 * resolução, a foto ficaria com um uuid dentro de uma FK numérica e a
 * associação nunca fecharia.
 */
class AplicadorDeFoto implements AplicadorDeOperacao
{
    /**
     * Mesma lista aceita por `WorkOrderPhotoController::store()`.
     */
    private const TIPOS_DE_ENTIDADE_VALIDOS = ['adequation', 'device_event', 'room_event'];

    public function tipo(): string
    {
        return 'foto';
    }

    public function aplicar(array $payload, User $usuario): Model
    {
        $entityType = (string) ($payload['entity_type'] ?? '');
        $path = trim((string) ($payload['path'] ?? ''));

        if (! in_array($entityType, self::TIPOS_DE_ENTIDADE_VALIDOS, true)) {
            throw ValidationException::withMessages([
                'entity_type' => 'Tipo de foto desconhecido. Use adequação, evento de dispositivo ou evento de cômodo.',
            ]);
        }

        if ($path === '' || ! Storage::disk('public')->exists($path)) {
            throw ValidationException::withMessages([
                'path' => 'O arquivo desta foto não foi encontrado no armazenamento. '
                    .'Envie a foto pelo aplicativo antes de sincronizar esta operação.',
            ]);
        }

        $entityId = $entityType === 'adequation'
            ? $this->resolverEntityIdDeAdequacao($payload['entity_id'] ?? null, $payload['work_order_id'] ?? null)
            : $payload['entity_id'] ?? null;

        return WorkOrderPhoto::create([
            'work_order_id' => $payload['work_order_id'] ?? null,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'room_id' => $payload['room_id'] ?? null,
            'path' => $path,
            'caption' => $payload['caption'] ?? null,
        ]);
    }

    /**
     * Resolve o `entity_id` de uma foto de adequação: valor já numérico passa
     * direto (compatibilidade com uma futura foto anexada depois, pelo
     * painel, quando a adequação já tiver `id` conhecido); valor não numérico
     * é tratado como o `uuid` gerado no aparelho e traduzido para o `id` real,
     * escopado à mesma ordem de serviço.
     *
     * @throws ValidationException Quando o uuid não corresponde a nenhuma
     *                             adequação desta ordem de serviço.
     */
    private function resolverEntityIdDeAdequacao(mixed $entityId, mixed $workOrderId): ?int
    {
        if ($entityId === null) {
            return null;
        }

        if (is_numeric($entityId)) {
            return (int) $entityId;
        }

        $adequacao = WorkOrderAdequation::query()
            ->where('work_order_id', $workOrderId)
            ->where('uuid', (string) $entityId)
            ->first();

        if ($adequacao === null) {
            throw ValidationException::withMessages([
                'entity_id' => 'A adequação desta foto ainda não foi sincronizada. '
                    .'Sincronize a adequação antes de reenviar a foto.',
            ]);
        }

        return $adequacao->id;
    }
}
