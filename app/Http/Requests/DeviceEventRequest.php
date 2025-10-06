<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeviceEventRequest extends FormRequest
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
        $rules = [
            'device_id' => 'required|exists:devices,id',
            'event_type' => 'required|in:bait_consumption,cleaning,bait_change,technician_notes',
            'event_date' => 'required|date',
            'description' => 'nullable|string|max:1000',
            'observations' => 'nullable|string|max:1000',
            'active' => 'boolean',
        ];

        // work_order_id é obrigatório apenas na criação
        if ($this->isMethod('POST')) {
            $rules['work_order_id'] = 'required|exists:work_orders,id';
        }

        // Regras específicas para cada tipo de evento
        switch ($this->event_type) {
            case 'bait_consumption':
                $rules['bait_consumption_status'] = 'required|in:partial,total,none,spoiled,replacement';
                $rules['bait_consumption_quantity'] = 'nullable|numeric|min:0|max:999.99';
                break;

            case 'cleaning':
                $rules['cleaning_done'] = 'required|boolean';
                $rules['cleaning_notes'] = 'nullable|string|max:1000';
                break;

            case 'bait_change':
                $rules['bait_change_type'] = 'nullable|string|max:255';
                $rules['bait_change_lot'] = 'nullable|string|max:255';
                $rules['bait_change_quantity'] = 'nullable|numeric|min:0|max:999.99';
                break;

            case 'technician_notes':
                $rules['technician_notes'] = 'required|string|max:1000';
                break;
        }

        return $rules;
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'device_id.required' => 'O dispositivo é obrigatório.',
            'device_id.exists' => 'O dispositivo selecionado não existe.',
            'work_order_id.required' => 'A ordem de serviço é obrigatória.',
            'work_order_id.exists' => 'A ordem de serviço selecionada não existe.',
            'event_type.required' => 'O tipo de evento é obrigatório.',
            'event_type.in' => 'O tipo de evento selecionado é inválido.',
            'event_date.required' => 'A data do evento é obrigatória.',
            'event_date.date' => 'A data do evento deve ser uma data válida.',
            'description.max' => 'A descrição não pode ter mais de 1000 caracteres.',
            'observations.max' => 'As observações não podem ter mais de 1000 caracteres.',
            'bait_consumption_status.required' => 'O status do consumo de isca é obrigatório.',
            'bait_consumption_status.in' => 'O status do consumo de isca selecionado é inválido.',
            'bait_consumption_quantity.numeric' => 'A quantidade deve ser um número.',
            'bait_consumption_quantity.min' => 'A quantidade não pode ser negativa.',
            'bait_consumption_quantity.max' => 'A quantidade não pode ser maior que 999.99.',
            'cleaning_done.required' => 'O status da limpeza é obrigatório.',
            'cleaning_done.boolean' => 'O status da limpeza deve ser verdadeiro ou falso.',
            'cleaning_notes.max' => 'As observações de limpeza não podem ter mais de 1000 caracteres.',
            'bait_change_type.max' => 'O tipo de isca não pode ter mais de 255 caracteres.',
            'bait_change_lot.max' => 'O lote não pode ter mais de 255 caracteres.',
            'bait_change_quantity.numeric' => 'A quantidade deve ser um número.',
            'bait_change_quantity.min' => 'A quantidade não pode ser negativa.',
            'bait_change_quantity.max' => 'A quantidade não pode ser maior que 999.99.',
            'technician_notes.required' => 'As observações do técnico são obrigatórias.',
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
            'cleaning_done' => $this->boolean('cleaning_done', false),
        ]);
    }
}

