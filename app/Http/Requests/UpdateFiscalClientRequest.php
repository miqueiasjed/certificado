<?php

namespace App\Http\Requests;

use App\Support\TenantAtual;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFiscalClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $cliente = $this->route('cliente');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('clients', 'email')
                    ->where(fn ($consulta) => $consulta->where('company_id', TenantAtual::exigirId()))
                    ->ignore($cliente?->getKey()),
            ],
            'email_nfe' => ['nullable', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:20'],
            'cnpj' => [
                'required',
                'string',
                'max:18',
                Rule::unique('clients', 'cnpj')
                    ->where(fn ($consulta) => $consulta->where('company_id', TenantAtual::exigirId()))
                    ->ignore($cliente?->getKey()),
            ],
            'inscricao_municipal' => ['nullable', 'string', 'max:100'],
            'inscricao_estadual' => ['nullable', 'string', 'max:100'],
            'address' => ['required', 'array'],
            'address.id' => ['nullable', 'integer', 'min:1'],
            'address.nickname' => ['required', 'string', 'max:255'],
            'address.street' => ['required', 'string', 'max:255'],
            'address.number' => ['required', 'string', 'max:50'],
            'address.district' => ['required', 'string', 'max:255'],
            'address.city' => ['required', 'string', 'max:255'],
            'address.state' => ['required', 'string', 'size:2'],
            'address.zip' => ['required', 'string', 'max:9'],
            'address.codigo_municipio_ibge' => ['required', 'regex:/^\d{7}$/'],
            'nota_ids' => ['sometimes', 'array', 'max:100'],
            'nota_ids.*' => ['integer', 'min:1', 'distinct'],
        ];
    }

    public function messages(): array
    {
        return [
            'address.required' => 'Informe o endereço usado na nota fiscal.',
            'address.codigo_municipio_ibge.regex' => 'O código IBGE precisa ter 7 dígitos.',
        ];
    }
}
