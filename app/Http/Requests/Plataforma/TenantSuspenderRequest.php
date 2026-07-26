<?php

namespace App\Http\Requests\Plataforma;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validação da suspensão de um tenant.
 *
 * `TenantService::suspender()` recebe `motivo` como `string` obrigatória, não
 * opcional: o valor fica gravado em `companies.motivo_suspensao` e é o que
 * explica, meses depois, por que aquele tenant ficou sem acesso. Existe como
 * FormRequest próprio (e não `$request->validate()` dentro do controller)
 * pela mesma convenção de `ContractEncerrarRequest`/
 * `ContractVisitJustificationRequest`: toda validação vai para FormRequest.
 *
 * `authorize()` devolve true porque a rota inteira já está atrás do
 * middleware `platform.admin` (ver routes/web.php).
 */
class TenantSuspenderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'motivo' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'motivo.required' => 'Informe o motivo da suspensão.',
            'motivo.string' => 'O motivo deve ser um texto.',
            'motivo.min' => 'Escreva o motivo com pelo menos 3 caracteres.',
            'motivo.max' => 'O motivo pode ter no máximo 1000 caracteres.',
        ];
    }
}
