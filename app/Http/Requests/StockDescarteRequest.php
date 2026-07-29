<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Descarte de estoque (Plano 17, Task 17.7): o que foi perdido, quebrado ou
 * venceu.
 *
 * `motivo` é obrigatório, e essa é a única diferença de fundo em relação à
 * entrada e à transferência: descarte sem motivo é indistinguível de erro de
 * digitação, e é o descarte que fecha o ciclo do lote vencido perante
 * fiscalização. O `StockService` recusa por conta própria também, para o caso
 * de a chamada não vir por aqui.
 */
class StockDescarteRequest extends FormRequest
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
            'quantidade' => 'required|numeric|min:0.0001',
            'motivo' => 'required|string|max:1000',
            'ocorrido_em' => 'nullable|date',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'product_id.required' => 'Informe o produto que está sendo descartado.',
            'product_id.exists' => 'O produto informado não existe.',
            'stock_location_id.required' => 'Informe o local de onde o produto está saindo.',
            'stock_location_id.exists' => 'O local de estoque informado não existe.',
            'product_batch_id.exists' => 'O lote informado não existe.',
            'quantidade.required' => 'Informe a quantidade descartada.',
            'quantidade.numeric' => 'A quantidade precisa ser um número.',
            'quantidade.min' => 'A quantidade precisa ser maior que zero.',
            'motivo.required' => 'O descarte exige motivo registrado.',
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
