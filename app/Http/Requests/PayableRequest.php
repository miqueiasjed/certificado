<?php

namespace App\Http\Requests;

use App\Models\Payable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Criação de título a pagar (Plano 18, Task 18.7).
 *
 * Espelha `ReceivableRequest` do lado de pagar, trocando o cliente pelo
 * fornecedor e acrescentando a recorrência. Título com `recorrencia` diferente
 * de `nenhuma` faz `PayableService::criar()` já gerar a janela de 3
 * competências (ver o cabeçalho daquele Service), e por isso `recorrencia_ate`
 * só faz sentido acompanhado de uma recorrência de verdade.
 *
 * A validação aqui é de formato. O fornecedor é resolvido pelo model escopado
 * dentro do próprio `PayableService`; `chart_of_account_id` é resolvido no
 * controller, pelo mesmo motivo: id vindo do corpo não passa pelo escopo
 * global sozinho.
 *
 * `authorize()` devolve `true` porque a rota inteira já exige
 * `permission:financeiro-titulos` e `module:financeiro`.
 */
class PayableRequest extends FormRequest
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
            'supplier_id' => 'required|integer',
            'chart_of_account_id' => 'nullable|integer',
            'descricao' => 'required|string|max:255',
            'valor' => 'required|numeric|min:0.01',
            'vencimento' => 'required|date',
            'emitido_em' => 'nullable|date',
            'recorrencia' => ['nullable', Rule::in(Payable::RECORRENCIAS)],
            // `recorrencia_ate` sozinho não faz nada: quem decide se existe
            // série é `recorrencia`, e `PayableService::criar()` descarta o
            // limite quando a recorrência é `nenhuma`. Recusar a combinação
            // aqui só devolveria erro a um formulário que ainda não escolheu a
            // recorrência.
            'recorrencia_ate' => 'nullable|date',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'supplier_id.required' => 'Informe o fornecedor do título.',
            'descricao.required' => 'Informe a descrição do título.',
            'valor.required' => 'Informe o valor do título.',
            'valor.min' => 'O valor do título precisa ser maior que zero.',
            'vencimento.required' => 'Informe o vencimento da parcela.',
            'vencimento.date' => 'A data de vencimento informada é inválida.',
            'recorrencia.in' => 'Recorrência inválida. Use nenhuma, mensal, trimestral ou anual.',
        ];
    }
}
