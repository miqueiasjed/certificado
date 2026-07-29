<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Estorno de baixa, dos dois lados do caixa (Plano 18, Task 18.7).
 *
 * Um campo só, e ele é obrigatório: o motivo. Estorno é a única operação que
 * desfaz dinheiro já lançado, e "erro" não explica nada a quem for auditar
 * seis meses depois. O mínimo de 10 caracteres é o mesmo de
 * `SettlementService` e `PayableService`, repetido aqui para o usuário receber
 * o erro no campo, ainda no formulário, em vez de só depois da tentativa de
 * gravação.
 *
 * Quem pode estornar não é decidido aqui: a rota exige
 * `permission:financeiro-estornar`, e o Service confere a mesma permissão de
 * novo, porque também é chamado fora de requisição HTTP.
 *
 * `authorize()` devolve `true` porque a rota inteira já exige
 * `permission:financeiro-estornar` e `module:financeiro`.
 */
class ReversalRequest extends FormRequest
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
            'motivo' => 'required|string|min:10|max:1000',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'motivo.required' => 'Informe o motivo do estorno.',
            'motivo.min' => 'O motivo do estorno precisa ter pelo menos 10 caracteres, para explicar de verdade por que o lançamento foi desfeito.',
        ];
    }
}
