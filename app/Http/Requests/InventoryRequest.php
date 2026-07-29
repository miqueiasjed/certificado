<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Abertura de um inventário (Plano 17, Task 17.7).
 *
 * O único dado que a abertura recebe é o local a contar: os itens são gerados
 * pelo `InventoryService::abrir()`, um por combinação de produto e lote que
 * tenha linha de saldo ali, com o saldo do sistema congelado. Deixar o cliente
 * mandar a lista de itens abriria caminho para contagem que ignora o que
 * incomoda.
 */
class InventoryRequest extends FormRequest
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
            'stock_location_id' => 'required|integer|exists:stock_locations,id',
            'observacao' => 'nullable|string|max:1000',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'stock_location_id.required' => 'Informe o local que será contado.',
            'stock_location_id.exists' => 'O local de estoque informado não existe.',
            'observacao.max' => 'A observação não pode passar de 1000 caracteres.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'stock_location_id' => 'local de estoque',
            'observacao' => 'observação',
        ];
    }
}
