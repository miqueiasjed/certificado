<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Justificativa da reabertura de uma comissão fechada ou paga (Plano 23, Task
 * 23.6).
 *
 * A permissão (`comissoes-reabrir`) é conferida duas vezes de propósito: pelo
 * middleware da rota e de novo dentro de
 * `CommissionClosingService::reabrir()`, porque o Service também é chamado
 * fora de requisição HTTP. Mesmo critério de `ReversalRequest`.
 *
 * O tamanho mínimo aqui é o mesmo de
 * `CommissionClosingService::JUSTIFICATIVA_MINIMA` (10), repetido para o
 * usuário receber o erro no campo, ainda no formulário, em vez de só depois
 * da tentativa de gravação.
 *
 * `authorize()` devolve `true` porque a rota inteira já exige
 * `permission:comissoes-reabrir`.
 */
class ReabrirComissaoRequest extends FormRequest
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
            'justificativa' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'justificativa.required' => 'Informe a justificativa da reabertura: é ela que explica, para a auditoria, por que uma comissão já fechada ou paga voltou a ser mexida.',
            'justificativa.min' => 'A justificativa precisa ter pelo menos 10 caracteres, para explicar de verdade o motivo.',
            'justificativa.max' => 'A justificativa pode ter no máximo 1000 caracteres.',
        ];
    }
}
