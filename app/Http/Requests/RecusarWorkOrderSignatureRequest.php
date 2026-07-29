<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validação do registro de recusa do cliente em assinar a OS (Plano 13,
 * Task 13.4).
 *
 * Arquivo à parte, e não `$request->validate()` dentro do controller: a
 * skill `laravel-arquitetura` deste projeto exige FormRequest para toda
 * validação, mesmo para um único campo.
 */
class RecusarWorkOrderSignatureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'motivo' => 'required|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'motivo.required' => 'Informe o motivo da recusa de assinatura.',
            'motivo.max' => 'O motivo não pode ter mais de 1000 caracteres.',
        ];
    }
}
