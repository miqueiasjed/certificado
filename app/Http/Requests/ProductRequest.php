<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $productId = $this->route('product');

        return [
            'name' => 'required|string|max:255',
            'active_ingredient_id' => 'required|exists:active_ingredients,id',
            'chemical_group_id' => 'required|exists:chemical_groups,id',
            'antidote_id' => 'required|exists:antidotes,id',
            'organ_registration_id' => 'nullable|exists:organ_registrations,id',
            // Dimensão de estoque (Plano 17): ligar o controle não cria saldo,
            // que só entra por lote. O mínimo é nulo por padrão (sem ponto de
            // reposição definido), e a unidade é texto livre de propósito
            // (ver migration `add_stock_fields_to_products_table`).
            'controla_estoque' => 'nullable|boolean',
            'estoque_minimo' => 'nullable|numeric|min:0',
            'unidade' => 'nullable|string|max:10',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'O nome do produto é obrigatório.',
            'name.max' => 'O nome não pode ter mais de 255 caracteres.',
            'active_ingredient_id.required' => 'O princípio ativo é obrigatório.',
            'active_ingredient_id.exists' => 'O princípio ativo selecionado não existe.',
            'chemical_group_id.required' => 'O grupo químico é obrigatório.',
            'chemical_group_id.exists' => 'O grupo químico selecionado não existe.',
            'antidote_id.required' => 'O antídoto é obrigatório.',
            'antidote_id.exists' => 'O antídoto selecionado não existe.',
            'organ_registration_id.exists' => 'O registro ministerial selecionado não existe.',
            'estoque_minimo.numeric' => 'O estoque mínimo precisa ser um número.',
            'estoque_minimo.min' => 'O estoque mínimo não pode ser negativo.',
            'unidade.max' => 'A unidade não pode passar de 10 caracteres.',
        ];
    }
}
