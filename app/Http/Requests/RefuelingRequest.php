<?php

namespace App\Http\Requests;

use App\Models\Refueling;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Registro de abastecimento (Plano 27, Task 27.4).
 *
 * A recusa de quilometragem retroativa **não** mora aqui: ela depende do estado
 * do veículo (`km_atual`) e é regra de negócio, então fica no
 * `RefuelingService`, que também é chamado fora de requisição HTTP. Aqui só
 * entra o que é forma do dado.
 *
 * `gerar_titulo` é opcional e nasce falso: o título a pagar é oferecido, nunca
 * criado automaticamente. Quando vem verdadeiro, `supplier_id` passa a ser
 * obrigatório — título sem fornecedor não existe no Plano 18.
 */
class RefuelingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'data' => 'required|date',
            'km' => 'required|integer|min:0',
            'litros' => 'required|numeric|gt:0',
            'valor_total' => 'required|numeric|min:0',
            'valor_litro' => 'nullable|numeric|min:0',
            'tipo_combustivel' => ['required', Rule::in(Refueling::TIPOS_DE_COMBUSTIVEL)],
            'posto' => 'nullable|string|max:255',
            'tanque_cheio' => 'boolean',

            // Oferta de título a pagar (Plano 18).
            'gerar_titulo' => 'boolean',
            'supplier_id' => 'required_if:gerar_titulo,true,1|nullable|exists:suppliers,id',
            'vencimento' => 'nullable|date',
            'chart_of_account_id' => 'nullable|exists:chart_of_accounts,id',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'data.required' => 'Informe a data do abastecimento.',
            'km.required' => 'Informe a quilometragem do hodômetro no momento do abastecimento.',
            'litros.gt' => 'A quantidade de litros precisa ser maior que zero.',
            'valor_total.required' => 'Informe o valor total do abastecimento.',
            'tipo_combustivel.in' => 'Tipo de combustível inválido.',
            'supplier_id.required_if' => 'Para gerar o título a pagar, informe o fornecedor.',
            'supplier_id.exists' => 'O fornecedor selecionado não existe.',
        ];
    }
}
