<?php

namespace App\Http\Requests;

use App\Services\ContractRenewalService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Registro de não renovação de contrato (Plano 23, Task 23.6): motivo da
 * lista fechada de `ContractRenewalService::MOTIVOS_NAO_RENOVACAO`, com texto
 * livre obrigatório quando o motivo é "outro". A mesma checagem já existe
 * dentro de `ContractRenewalService::registrarNaoRenovacao()` (o Service
 * também é alcançável fora desta rota); a regra `required_if` abaixo só
 * antecipa o erro para o formulário.
 *
 * `authorize()` devolve `true` porque a rota inteira já exige
 * `permission:contrato-renovar` e `module:contratos`.
 */
class ContractNaoRenovarRequest extends FormRequest
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
            'motivo' => ['required', Rule::in(array_keys(ContractRenewalService::MOTIVOS_NAO_RENOVACAO))],
            'motivo_livre' => ['required_if:motivo,outro', 'nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'motivo.required' => 'Informe o motivo da não renovação.',
            'motivo.in' => 'Motivo inválido. Escolha um item da lista.',
            'motivo_livre.required_if' => 'Descreva o motivo quando escolher "Outro".',
        ];
    }
}
