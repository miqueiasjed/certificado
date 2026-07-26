<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validação de criação e edição de contrato.
 *
 * Antes desta classe, a regra vivia inline em `ContractController::store` e
 * `update`, duplicada nos dois métodos e contrariando a skill de arquitetura
 * do projeto (toda validação vai para FormRequest). Fechada junto da dívida
 * da Task 9.7.
 *
 * `authorize()` devolve true porque as rotas de contrato já são
 * inteiramente protegidas por permissão do Spatie
 * (`contrato-criar`/`contrato-editar`, ver routes/web.php): não é rota de
 * autoatendimento nem de portal do cliente.
 */
class ContractRequest extends FormRequest
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
        $regras = [
            'contract_number' => 'nullable|string',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'service_value' => 'nullable|numeric|min:0',
            'service_type' => 'required|in:pontual,periodico',
            'visit_frequency' => 'required|in:weekly,biweekly,monthly',
            'visit_count' => 'required|integer|min:1',
            'pest_target' => 'nullable|string',
            'payment_method' => 'nullable|string',
            'payment_details' => 'nullable|string',
            'additional_clause' => 'nullable|string',
            'jurisdiction' => 'nullable|string',
        ];

        // Só a criação sem endereço na rota (POST /contracts, sem passar por
        // /addresses/{address}/contracts) precisa do endereço no corpo da
        // requisição. Edição (PUT/PATCH) nunca leva address_id: o contrato já
        // está vinculado.
        if ($this->isMethod('POST') && ! $this->route('address')) {
            $regras['address_id'] = 'required|exists:addresses,id';
        }

        return $regras;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'start_date.required' => 'A data de início é obrigatória.',
            'end_date.required' => 'A data de término é obrigatória.',
            'end_date.after' => 'A data de término deve ser posterior à data de início.',
            'service_value.numeric' => 'O valor do serviço deve ser numérico.',
            'service_type.required' => 'O tipo de serviço é obrigatório.',
            'service_type.in' => 'Tipo de serviço inválido.',
            'visit_frequency.required' => 'A frequência de visitas é obrigatória.',
            'visit_frequency.in' => 'Frequência de visitas inválida.',
            'visit_count.required' => 'A quantidade de visitas é obrigatória.',
            'visit_count.integer' => 'A quantidade de visitas deve ser um número inteiro.',
            'visit_count.min' => 'A quantidade de visitas deve ser pelo menos 1.',
            'address_id.required' => 'O endereço é obrigatório.',
            'address_id.exists' => 'O endereço selecionado não existe.',
        ];
    }
}
