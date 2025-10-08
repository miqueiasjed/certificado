<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WorkOrderRequest extends FormRequest
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
            'client_id' => 'required|exists:clients,id',
            'address_id' => 'nullable|exists:addresses,id',
            'technician_id' => 'nullable|exists:users,id',
            'technicians' => 'nullable|array',
            'technicians.*' => 'nullable|exists:users,id',
            'service_type_id' => 'required|exists:service_types,id',
            'order_number' => 'nullable|string|max:255',
            'priority_level' => 'required|in:low,medium,high,urgent,emergency',
            'scheduled_date' => 'required|date',
            'start_time' => 'nullable|date_format:Y-m-d\TH:i',
            'end_time' => 'nullable|date|after:start_time',
            'status' => 'required|in:pending,scheduled,in_progress,completed,cancelled,on_hold',
            'description' => 'required|string|max:1000',
            'observations' => 'nullable|string|max:1000',
            'materials_used' => 'nullable|array',
            'materials_used.*' => 'string|max:255',
            'completion_notes' => 'nullable|string|max:1000',
            'active' => 'boolean',
        ];

        // Regras específicas para criação
        if ($this->isMethod('POST')) {
            $rules['order_number'] = 'nullable|string|max:255';
        }

        return $rules;
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'client_id.required' => 'O cliente é obrigatório.',
            'client_id.exists' => 'O cliente selecionado não existe.',
            'address_id.exists' => 'O endereço selecionado não existe.',
            'technician_id.required' => 'O técnico é obrigatório.',
            'technician_id.exists' => 'O técnico selecionado não existe.',
            'service_type_id.required' => 'O tipo de serviço é obrigatório.',
            'service_type_id.exists' => 'O tipo de serviço selecionado não existe.',
            'order_number.unique' => 'Este número de ordem já está em uso.',
            'order_number.max' => 'O número da ordem não pode ter mais de 255 caracteres.',
            'priority_level.required' => 'O nível de prioridade é obrigatório.',
            'priority_level.in' => 'O nível de prioridade selecionado é inválido.',
            'scheduled_date.required' => 'A data agendada é obrigatória.',
            'scheduled_date.date' => 'A data agendada deve ser uma data válida.',
            'start_time.date' => 'O horário de início deve ser uma data/hora válida.',
            'end_time.date' => 'O horário de término deve ser uma data/hora válida.',
            'end_time.after' => 'O horário de término deve ser posterior ao horário de início.',
            'status.required' => 'O status é obrigatório.',
            'status.in' => 'O status selecionado é inválido.',
            'description.required' => 'A descrição é obrigatória.',
            'description.max' => 'A descrição não pode ter mais de 1000 caracteres.',
            'observations.max' => 'As observações não podem ter mais de 1000 caracteres.',
            'materials_used.array' => 'Os materiais utilizados devem ser uma lista.',
            'materials_used.*.max' => 'Cada material não pode ter mais de 255 caracteres.',
            'completion_notes.max' => 'As observações de conclusão não podem ter mais de 1000 caracteres.',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation()
    {
        // Definir valores padrão para campos opcionais
        $mergeData = [
            'active' => $this->boolean('active', true),
            'status' => $this->status ?? 'pending',
        ];

        // Gerar número da ordem apenas para criação (POST), não para atualização (PUT/PATCH)
        // E apenas se não foi fornecido
        if ($this->isMethod('POST') && !$this->filled('order_number')) {
            try {
                $mergeData['order_number'] = app(\App\Services\WorkOrderService::class)->generateOrderNumber();
            } catch (\Exception $e) {
                // Em caso de erro, usar fallback com timestamp
                $mergeData['order_number'] = 'OS' . date('YmdHis');
            }
        }

        $this->merge($mergeData);
    }
}
