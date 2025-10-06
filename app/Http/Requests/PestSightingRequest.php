<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PestSightingRequest extends FormRequest
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
            'work_order_id' => 'required|exists:work_orders,id',
            'sighting_date' => 'required|date',
            'pest_type' => 'required|in:rats,mice,cockroaches,ants,termites,flies,fleas,ticks,scorpions,spiders,bees,wasps,other',
            'severity_level' => 'required|in:low,medium,high,critical',
            'location_description' => 'required|string|max:1000',
            'description' => 'nullable|string|max:1000',
            'observations' => 'nullable|string|max:1000',
            'environmental_conditions' => 'nullable|string|max:1000',
            'control_measures_applied' => 'nullable|string|max:1000',
            'technician_notes' => 'nullable|string|max:1000',
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
            'work_order_id.required' => 'A ordem de serviço é obrigatória.',
            'work_order_id.exists' => 'A ordem de serviço selecionada não existe.',
            'sighting_date.required' => 'A data do avistamento é obrigatória.',
            'sighting_date.date' => 'A data do avistamento deve ser uma data válida.',
            'pest_type.required' => 'O tipo de praga é obrigatório.',
            'pest_type.in' => 'O tipo de praga selecionado é inválido.',
            'severity_level.required' => 'O nível de severidade é obrigatório.',
            'severity_level.in' => 'O nível de severidade selecionado é inválido.',
            'location_description.required' => 'A descrição da localização é obrigatória.',
            'location_description.max' => 'A descrição da localização não pode ter mais de 1000 caracteres.',
            'description.max' => 'A descrição não pode ter mais de 1000 caracteres.',
            'observations.max' => 'As observações não podem ter mais de 1000 caracteres.',
            'environmental_conditions.max' => 'As condições ambientais não podem ter mais de 1000 caracteres.',
            'control_measures_applied.max' => 'As medidas de controle aplicadas não podem ter mais de 1000 caracteres.',
            'technician_notes.max' => 'As observações do técnico não podem ter mais de 1000 caracteres.',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation()
    {
        // Definir valores padrão para campos opcionais
        $this->merge([
            'active' => $this->boolean('active', true),
        ]);
    }
}

