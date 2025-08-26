<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'category' => 'nullable|string|max:255',
            'price' => 'nullable|numeric|min:0',
            'is_active' => 'boolean',
            'notes' => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'O nome do serviço é obrigatório.',
            'name.max' => 'O nome do serviço não pode ter mais de 255 caracteres.',
            'description.max' => 'A descrição não pode ter mais de 1000 caracteres.',
            'category.max' => 'A categoria não pode ter mais de 255 caracteres.',
            'price.numeric' => 'O preço deve ser um número válido.',
            'price.min' => 'O preço não pode ser negativo.',
            'notes.max' => 'As observações não podem ter mais de 1000 caracteres.',
        ];
    }
}
