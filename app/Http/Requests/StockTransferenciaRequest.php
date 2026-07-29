<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Transferência de estoque entre dois locais (Plano 17, Task 17.7): o caminho
 * do depósito para a van do técnico e a volta dela.
 *
 * Origem e destino diferentes é validado aqui, e não só no `StockService`, para
 * o usuário receber o erro no campo em vez de uma exceção genérica. O Service
 * continua recusando por conta própria: ele também roda fora de requisição
 * HTTP.
 */
class StockTransferenciaRequest extends FormRequest
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
            'origem_id' => 'required|integer|exists:stock_locations,id',
            'destino_id' => 'required|integer|different:origem_id|exists:stock_locations,id',
            'product_batch_id' => 'nullable|integer|exists:product_batches,id',
            'quantidade' => 'required|numeric|min:0.0001',
            'motivo' => 'nullable|string|max:1000',
            'ocorrido_em' => 'nullable|date',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'product_id.required' => 'Informe o produto que está sendo transferido.',
            'product_id.exists' => 'O produto informado não existe.',
            'origem_id.required' => 'Informe o local de origem.',
            'origem_id.exists' => 'O local de origem informado não existe.',
            'destino_id.required' => 'Informe o local de destino.',
            'destino_id.different' => 'A transferência precisa de dois locais diferentes.',
            'destino_id.exists' => 'O local de destino informado não existe.',
            'product_batch_id.exists' => 'O lote informado não existe.',
            'quantidade.required' => 'Informe a quantidade a transferir.',
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
            'origem_id' => 'local de origem',
            'destino_id' => 'local de destino',
            'product_batch_id' => 'lote',
            'quantidade' => 'quantidade',
            'motivo' => 'motivo',
            'ocorrido_em' => 'data da movimentação',
        ];
    }
}
