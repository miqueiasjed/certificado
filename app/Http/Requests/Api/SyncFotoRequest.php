<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validação do envio de foto do aplicativo do técnico (Plano 12, Task 12.5).
 *
 * A foto chega em requisição própria, nunca embutida em base64 no lote de
 * `POST /api/app/sincronizar`: base64 aumenta o tamanho do arquivo em um
 * terço e faria o lote inteiro falhar por causa de uma imagem. O mesmo campo
 * `uuid` que o lote usa também aqui garante a idempotência (mesma foto
 * enviada duas vezes grava uma), delegada ao `AppSyncService::aplicar()` com
 * o mesmo aplicador (`AplicadorDeFoto`) da Task 12.4.
 *
 * `authorize() => true`: a rota está protegida por `auth:sanctum`, e o acesso
 * à ordem de serviço em si é revalidado dentro do Service, via
 * `WorkOrderAccessService`.
 */
class SyncFotoRequest extends FormRequest
{
    /**
     * Limite de 5 MB por foto, em kilobytes (unidade do validador `max` para
     * arquivo). O aplicativo já comprime a foto antes de enviar (Task 12.11);
     * acima disso a recusa precisa ser clara, e não um erro genérico de
     * upload.
     */
    public const LIMITE_EM_KILOBYTES = 5120;

    private const TIPOS_DE_ENTIDADE_VALIDOS = ['adequation', 'device_event', 'room_event'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'uuid' => ['required', 'uuid'],
            'work_order_id' => ['required', 'integer'],
            'foto' => ['required', 'file', 'image', 'max:'.self::LIMITE_EM_KILOBYTES],
            'legenda' => ['nullable', 'string', 'max:255'],
            'entity_type' => ['required', 'string', 'in:'.implode(',', self::TIPOS_DE_ENTIDADE_VALIDOS)],
            'entity_id' => ['nullable', 'integer'],
            'room_id' => ['nullable', 'integer'],
            'registrada_em' => ['nullable', 'date'],
            'updated_at_conhecido' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'uuid.required' => 'A foto precisa de um identificador único (uuid).',
            'uuid.uuid' => 'O identificador da foto precisa ser um uuid válido.',
            'work_order_id.required' => 'Informe a ordem de serviço desta foto.',
            'work_order_id.integer' => 'O identificador da ordem de serviço precisa ser numérico.',
            'foto.required' => 'Envie o arquivo da foto.',
            'foto.image' => 'O arquivo enviado precisa ser uma imagem.',
            'foto.max' => 'Esta foto passa do limite de 5 MB. Comprima antes de enviar.',
            'legenda.max' => 'A legenda não pode ter mais de 255 caracteres.',
            'entity_type.required' => 'Informe a que esta foto se refere.',
            'entity_type.in' => 'Tipo de foto desconhecido. Use adequação, evento de dispositivo ou evento de cômodo.',
        ];
    }
}
