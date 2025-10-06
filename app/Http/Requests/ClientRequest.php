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
            'notes' => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'O nome é obrigatório.',
            'name.max' => 'O nome não pode ter mais de 255 caracteres.',
            'email.required' => 'O e-mail é obrigatório.',
            'phone.required' => 'O telefone é obrigatório.',
            'cnpj.required' => 'O CPF/CNPJ é obrigatório.',
            'cnpj.unique' => 'Este CPF/CNPJ já está sendo usado.',
            'notes.max' => 'As observações não podem ter mais de 1000 caracteres.',
        ];
    }
}
