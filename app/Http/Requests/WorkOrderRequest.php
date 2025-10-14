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
            'client_id' => $this->isMethod('POST') ? 'required|exists:clients,id' : 'sometimes|exists:clients,id',
            'address_id' => 'nullable|exists:addresses,id',
            'technician_id' => 'nullable|exists:users,id',
            'technicians' => 'nullable|array',
            'technicians.*' => 'nullable|exists:technicians,id',
            'products' => 'nullable|array',
            'products.*.id' => 'required_with:products|exists:products,id',
            'products.*.quantity' => 'nullable|numeric|min:0',
            'products.*.unit' => 'nullable|string',
            'products.*.observations' => 'nullable|string|max:500',
            'service_id' => 'required|exists:services,id',
            'rooms' => 'nullable|array',
            'rooms.*.id' => 'required_with:rooms|exists:rooms,id',
            'rooms.*.observation' => 'nullable|string|max:500',
            // Campos de evento (obrigatório quando cômodo é selecionado)
            'rooms.*.event_type' => 'required_with:rooms.*.id|integer|exists:event_types,id',
            'rooms.*.event_date' => 'required_with:rooms.*.id|date',
            'rooms.*.event_description' => 'nullable|string|max:1000',
               'rooms.*.event_observations' => 'nullable|string|max:1000',
               // Campo de dispositivo (opcional)
               'rooms.*.device_id' => 'nullable|exists:devices,id',
               // Campos de avistamento de praga (opcional)
               'rooms.*.pest_type' => 'nullable|string|max:255',
               'rooms.*.pest_sighting_date' => 'nullable|date',
               'rooms.*.pest_location' => 'nullable|string|max:255',
               'rooms.*.pest_quantity' => 'nullable|integer|min:1',
               'rooms.*.pest_observation' => 'nullable|string|max:1000',
            'order_number' => 'nullable|string|max:255',
            'priority_level' => 'required|in:low,medium,high,urgent,emergency',
            'scheduled_date' => 'required|date',
            'start_time' => 'nullable|date_format:Y-m-d\TH:i',
            'end_time' => 'nullable|date|after:start_time',
            'status' => 'required|in:pending,scheduled,in_progress,completed,cancelled,on_hold',
            'description' => 'nullable|string|max:1000',
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
            'service_id.required' => 'O serviço é obrigatório.',
            'service_id.exists' => 'O serviço selecionado não existe.',
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
            'description.max' => 'A descrição não pode ter mais de 1000 caracteres.',
            'observations.max' => 'As observações não podem ter mais de 1000 caracteres.',
            'materials_used.array' => 'Os materiais utilizados devem ser uma lista.',
            'materials_used.*.max' => 'Cada material não pode ter mais de 255 caracteres.',
            'rooms.array' => 'Os cômodos devem ser um array.',
            'rooms.*.id.required_with' => 'O ID do cômodo é obrigatório.',
            'rooms.*.id.exists' => 'O cômodo selecionado não existe.',
            'rooms.*.observation.max' => 'A observação do cômodo não pode ter mais de 500 caracteres.',
            // Mensagens para campos de evento
            'rooms.*.event_type.required_with' => 'O tipo de evento é obrigatório para o cômodo.',
            'rooms.*.event_type.integer' => 'O tipo de evento deve ser um ID válido.',
            'rooms.*.event_type.exists' => 'O tipo de evento selecionado não existe.',
            'rooms.*.event_date.required_with' => 'A data do evento é obrigatória para o cômodo.',
            'rooms.*.event_date.date' => 'A data do evento deve ser uma data válida.',
            'rooms.*.event_description.max' => 'A descrição do evento não pode ter mais de 1000 caracteres.',
            'rooms.*.event_observations.max' => 'As observações do evento não podem ter mais de 1000 caracteres.',
            // Mensagens para campos de avistamento de praga
            'rooms.*.pest_type.max' => 'O tipo de praga não pode ter mais de 255 caracteres.',
            'rooms.*.pest_sighting_date.date' => 'A data do avistamento deve ser uma data válida.',
            'rooms.*.pest_location.max' => 'A localização da praga não pode ter mais de 255 caracteres.',
            'rooms.*.pest_quantity.integer' => 'A quantidade de pragas deve ser um número inteiro.',
            'rooms.*.pest_quantity.min' => 'A quantidade de pragas deve ser pelo menos 1.',
            'rooms.*.pest_observation.max' => 'A observação do avistamento não pode ter mais de 1000 caracteres.',
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

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $rooms = $this->input('rooms', []);

            foreach ($rooms as $index => $room) {
                // Se o cômodo tem ID (foi selecionado), verificar se tem evento completo
                if (!empty($room['id'])) {
                    $hasEventType = !empty($room['event_type']);
                    $hasEventDate = !empty($room['event_date']);

                    // Se algum campo de evento está preenchido, tipo e data devem estar
                    if ($hasEventType || $hasEventDate) {
                        if (!$hasEventType) {
                            $validator->errors()->add("rooms.{$index}.event_type", 'O tipo de evento é obrigatório para o cômodo selecionado.');
                        }
                        if (!$hasEventDate) {
                            $validator->errors()->add("rooms.{$index}.event_date", 'A data do evento é obrigatória para o cômodo selecionado.');
                        }
                    } else {
                        // Se nenhum campo de evento está preenchido, é obrigatório preencher tipo e data
                        $validator->errors()->add("rooms.{$index}.event_type", 'É obrigatório adicionar um evento para o cômodo selecionado.');
                        $validator->errors()->add("rooms.{$index}.event_date", 'É obrigatório adicionar uma data para o evento do cômodo selecionado.');
                    }
                }
            }
        });
    }
}
