<?php

namespace App\Http\Requests;

use App\Models\ClientRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validação da mudança de situação de uma solicitação de atendimento (Plano
 * 15, Task 15.5).
 *
 * `authorize()` sempre `true`: mesmo critério de `ResponderSolicitacaoRequest`
 * - a rota (`POST /solicitacoes/{id}/situacao`) já está inteiramente
 * protegida por `permission:solicitacao-responder`.
 */
class AlterarSituacaoSolicitacaoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'situacao' => ['required', 'string', Rule::in(ClientRequest::SITUACOES)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'situacao.required' => 'Informe a nova situação da solicitação.',
            'situacao.in' => 'Situação inválida.',
        ];
    }
}
