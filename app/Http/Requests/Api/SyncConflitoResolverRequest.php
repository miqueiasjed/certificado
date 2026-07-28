<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validação da resolução de um conflito de sincronização (Plano 12, Task
 * 12.5), consumida por `AppSyncService::resolver()`.
 *
 * `authorize() => true`: a rota está protegida por `auth:sanctum`, e a
 * checagem de que este conflito pertence ao próprio técnico (quando aplicável)
 * é feita no controller antes de chamar o Service, porque depende do registro
 * carregado por route-model binding, não só do corpo da requisição.
 */
class SyncConflitoResolverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'escolha' => ['required', 'string', 'in:aplicativo,servidor'],
        ];
    }

    public function messages(): array
    {
        return [
            'escolha.required' => 'Informe qual lado do conflito deve ser aplicado.',
            'escolha.in' => 'Escolha inválida: use "aplicativo" ou "servidor".',
        ];
    }
}
