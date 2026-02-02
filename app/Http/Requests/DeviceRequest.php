<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeviceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'address_id' => 'required|exists:addresses,id',
            'label' => 'required|string|max:255',
            'number' => 'required|string|max:255',
            'bait_type_id' => 'nullable|exists:bait_types,id',
            'default_location_note' => 'nullable|string|max:1000',
            'active' => 'boolean',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'address_id.required' => 'O endereço é obrigatório.',
            'address_id.exists' => 'O endereço selecionado não existe.',
            'label.required' => 'O nome do dispositivo é obrigatório.',
            'label.max' => 'O nome não pode ter mais de 255 caracteres.',
            'number.required' => 'O número/código do dispositivo é obrigatório.',
            'number.max' => 'O número não pode ter mais de 255 caracteres.',
            'bait_type_id.exists' => 'O tipo de isca selecionado não existe.',
            'default_location_note.max' => 'A observação de localização não pode ter mais de 1000 caracteres.',
        ];
    }
}
