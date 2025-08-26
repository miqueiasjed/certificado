<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TechnicianRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $technicianId = $this->route('technician')?->id;

        return [
            'name' => 'required|string|max:255',
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('technicians', 'email')->ignore($technicianId),
            ],
            'phone' => 'required|string|max:20',
            'specialty' => 'nullable|string|max:255',
            'registration_number' => 'nullable|string|max:255',
            'is_active' => 'boolean',
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
            'phone.max' => 'O telefone não pode ter mais de 20 caracteres.',
            'specialty.max' => 'A especialidade não pode ter mais de 255 caracteres.',
            'registration_number.max' => 'O número de registro não pode ter mais de 255 caracteres.',
            'notes.max' => 'As observações não podem ter mais de 1000 caracteres.',
        ];
    }
}
