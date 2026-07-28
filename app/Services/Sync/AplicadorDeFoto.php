<?php

namespace App\Services\Sync;

use App\Models\User;
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

        return WorkOrderPhoto::create([
            'work_order_id' => $payload['work_order_id'] ?? null,
            'entity_type' => $entityType,
            'entity_id' => $payload['entity_id'] ?? null,
            'room_id' => $payload['room_id'] ?? null,
            'path' => $path,
            'caption' => $payload['caption'] ?? null,
        ]);
    }
}
