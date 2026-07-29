<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Entrada de estoque (Plano 17, Task 17.7): compra recebida, devolução do
 * técnico ou saldo inicial cadastrado depois da contagem física.
 *
 * A validação aqui é de formato. A coerência entre produto, lote e local (e a
 * empresa dona de cada um) é conferida no controller, resolvendo cada id pelo
 * model escopado, e de novo dentro do `StockService`: id que chega pelo corpo
 * da requisição não passa pelo escopo global sozinho, e é assim que se
 * movimenta o lote de uma empresa na conta de outra sem ninguém perceber.
 *
 * `authorize()` devolve `true` porque a rota inteira já exige
 * `permission:estoque-movimentar` e `module:estoque`. Não é rota de
 * autoatendimento nem do portal do cliente, os dois casos em que a skill
 * `permissoes-e-multitenancy` proíbe esse atalho.
 */
class StockEntradaRequest extends FormRequest
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
            'product_id' => 'required|integer|exists:products,id',
            'stock_location_id' => 'required|integer|exists:stock_locations,id',
            'product_batch_id' => 'nullable|integer|exists:product_batches,id',
            // As quantidades têm 4 casas porque saneante concentrado é dosado
            // em fração de mililitro. O mínimo é a menor fração representável,
            // e não zero: movimento de zero só polui o extrato.
            'quantidade' => 'required|numeric|min:0.0001',
            'motivo' => 'nullable|string|max:1000',
            // Instante da entrada em campo, diferente do instante do registro.
            // Omitido, vale agora.
            'ocorrido_em' => 'nullable|date',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'product_id.required' => 'Informe o produto que está entrando no estoque.',
            'product_id.exists' => 'O produto informado não existe.',
            'stock_location_id.required' => 'Informe o local que vai receber o produto.',
            'stock_location_id.exists' => 'O local de estoque informado não existe.',
            'product_batch_id.exists' => 'O lote informado não existe.',
            'quantidade.required' => 'Informe a quantidade que está entrando.',
            'quantidade.numeric' => 'A quantidade precisa ser um número.',
            'quantidade.min' => 'A quantidade precisa ser maior que zero.',
            'motivo.max' => 'O motivo não pode passar de 1000 caracteres.',
            'ocorrido_em.date' => 'A data da movimentação é inválida.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'product_id' => 'produto',
            'stock_location_id' => 'local de estoque',
            'product_batch_id' => 'lote',
            'quantidade' => 'quantidade',
            'motivo' => 'motivo',
            'ocorrido_em' => 'data da movimentação',
        ];
    }
}
