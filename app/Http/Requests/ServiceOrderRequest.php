<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ServiceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_id' => 'required|exists:clients,id',
            'service_id' => 'required|exists:services,id',
            'status' => 'required|in:pending,in_progress,completed,cancelled',
            'priority' => 'nullable|in:low,medium,high,urgent',
            'start_date' => 'nullable|date',
            'due_date' => 'nullable|date|after_or_equal:start_date',
            'notes' => 'nullable|string|max:1000',
            'rooms' => 'nullable|array',
            'rooms.*.id' => 'required|exists:rooms,id',
            'rooms.*.observation' => 'nullable|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'client_id.required' => 'O cliente é obrigatório.',
            'client_id.exists' => 'O cliente selecionado não existe.',
            'service_id.required' => 'O serviço é obrigatório.',
            'service_id.exists' => 'O serviço selecionado não existe.',
            'status.required' => 'O status é obrigatório.',
            'status.in' => 'O status deve ser: Pendente, Em Andamento, Concluída ou Cancelada.',
            'priority.in' => 'A prioridade deve ser: Baixa, Média, Alta ou Urgente.',
            'start_date.date' => 'A data de início deve ser uma data válida.',
            'due_date.date' => 'A data de conclusão deve ser uma data válida.',
            'due_date.after_or_equal' => 'A data de conclusão deve ser igual ou posterior à data de início.',
            'notes.max' => 'As observações não podem ter mais de 1000 caracteres.',
            'rooms.array' => 'Os cômodos devem ser um array.',
            'rooms.*.id.required' => 'O ID do cômodo é obrigatório.',
            'rooms.*.id.exists' => 'O cômodo selecionado não existe.',
            'rooms.*.observation.max' => 'A observação do cômodo não pode ter mais de 500 caracteres.',
        ];
    }
}
