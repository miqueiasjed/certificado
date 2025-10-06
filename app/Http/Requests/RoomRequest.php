<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'name' => 'required|string|max:255',
            'notes' => 'nullable|string|max:1000',
            'active' => 'boolean',
        ];

        // address_id só é obrigatório na criação, não na edição
        if ($this->isMethod('POST')) {
            $rules['address_id'] = 'required|exists:addresses,id';
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'address_id.required' => 'O endereço é obrigatório.',
            'address_id.exists' => 'O endereço selecionado não existe.',
            'name.required' => 'O nome do cômodo é obrigatório.',
            'name.max' => 'O nome do cômodo deve ter no máximo 255 caracteres.',
            'notes.max' => 'As observações devem ter no máximo 1000 caracteres.',
        ];
    }
}
