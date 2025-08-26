<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $clientId = $this->route('client');

        return [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:clients,email,' . $clientId,
            'phone' => 'required|string|max:20',
            'cnpj' => 'required|string|max:18|unique:clients,cnpj,' . $clientId,
            'city' => 'required|string|max:255',
            'state' => 'nullable|string|max:2',
            'zip_code' => 'nullable|string|max:9',
            'address' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'O nome é obrigatório.',
            'name.max' => 'O nome não pode ter mais de 255 caracteres.',
            'email.required' => 'O e-mail é obrigatório.',
            'email.email' => 'Digite um e-mail válido.',
            'email.unique' => 'Este e-mail já está sendo usado.',
            'phone.required' => 'O telefone é obrigatório.',
            'cnpj.required' => 'O CPF/CNPJ é obrigatório.',
            'cnpj.unique' => 'Este CPF/CNPJ já está sendo usado.',
            'city.required' => 'A cidade é obrigatória.',
            'state.max' => 'O estado deve ter no máximo 2 caracteres.',
            'zip_code.max' => 'O CEP deve ter no máximo 9 caracteres.',
            'address.max' => 'O endereço não pode ter mais de 255 caracteres.',
            'notes.max' => 'As observações não podem ter mais de 1000 caracteres.',
        ];
    }
}
