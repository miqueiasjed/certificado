<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validação da recusa de um pedido de horário pela empresa (Plano 16, Task
 * 16.4).
 *
 * `authorize()` sempre `true`: a rota (`POST /solicitacoes-de-horario/{pedido}/
 * recusar`) já está inteiramente protegida por `permission:agendamento-responder`,
 * mesmo critério de `ResponderSolicitacaoRequest`.
 *
 * O motivo é sempre obrigatório: a empresa escolhe entre um motivo pronto
 * (ver `AppointmentRequestService::MOTIVOS_PRONTOS_DE_RECUSA`) ou escreve o
 * próprio texto - os dois chegam aqui como a mesma string livre, nunca
 * validada contra uma lista fechada.
 */
class RecusarAppointmentRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'motivo' => ['required', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'motivo.required' => 'Informe o motivo da recusa.',
            'motivo.max' => 'O motivo pode ter no máximo 1000 caracteres.',
        ];
    }
}
