<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_id' => 'required|exists:clients,id',
            'nickname' => 'required|string|max:255',
            'street' => 'required|string|max:255',
            'number' => 'required|string|max:50',
            'district' => 'required|string|max:255',
            'city' => 'required|string|max:255',
            'state' => 'required|string|size:2',
            'zip' => 'required|string|max:9',
            'reference' => 'nullable|string|max:1000',
            'active' => 'boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'client_id.required' => 'O cliente é obrigatório.',
            'client_id.exists' => 'O cliente selecionado não existe.',
            'nickname.required' => 'O apelido é obrigatório.',
            'nickname.max' => 'O apelido deve ter no máximo 255 caracteres.',
            'street.required' => 'A rua é obrigatória.',
            'street.max' => 'A rua deve ter no máximo 255 caracteres.',
            'number.required' => 'O número é obrigatório.',
            'number.max' => 'O número deve ter no máximo 50 caracteres.',
            'district.required' => 'O bairro é obrigatório.',
            'district.max' => 'O bairro deve ter no máximo 255 caracteres.',
            'city.required' => 'A cidade é obrigatória.',
            'city.max' => 'A cidade deve ter no máximo 255 caracteres.',
            'state.required' => 'O estado é obrigatório.',
            'state.size' => 'O estado deve ter exatamente 2 caracteres.',
            'zip.required' => 'O CEP é obrigatório.',
            'zip.max' => 'O CEP deve ter no máximo 9 caracteres.',
            'reference.max' => 'A referência deve ter no máximo 1000 caracteres.',
        ];
    }
}
