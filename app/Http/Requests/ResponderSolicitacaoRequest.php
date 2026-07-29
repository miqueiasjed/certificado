<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validação da resposta da empresa a uma solicitação de atendimento (Plano
 * 15, Task 15.5).
 *
 * `authorize()` sempre `true`: a rota (`POST /solicitacoes/{id}/responder`)
 * já está inteiramente protegida por `permission:solicitacao-responder`, o
 * caso que a skill de multiempresa aceita para autorização sempre positiva no
 * FormRequest.
 */
class ResponderSolicitacaoRequest extends FormRequest
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
            'resposta' => ['required', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'resposta.required' => 'Escreva uma resposta para a solicitação.',
            'resposta.max' => 'A resposta pode ter no máximo 5000 caracteres.',
        ];
    }
}
