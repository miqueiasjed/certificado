<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WorkOrderAdequationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type'        => 'required|in:estrutural,sanitario,higienico,quimico,outros',
            'description' => 'required|string|max:1000',
            'priority'    => 'required|in:alta,media,baixa',
            'status'      => 'required|in:pendente,concluido',
            'deadline'    => 'nullable|date|after_or_equal:today',
        ];
    }

    public function messages(): array
    {
        return [
            'type.required'        => 'O tipo de adequação é obrigatório.',
            'type.in'              => 'Tipo inválido.',
            'description.required' => 'A descrição é obrigatória.',
            'description.max'      => 'A descrição não pode ultrapassar 1000 caracteres.',
            'priority.required'    => 'A prioridade é obrigatória.',
            'priority.in'          => 'Prioridade inválida.',
            'status.required'      => 'O status é obrigatório.',
            'status.in'            => 'Status inválido.',
            'deadline.date'        => 'Prazo inválido.',
            'deadline.after_or_equal' => 'O prazo não pode ser uma data passada.',
        ];
    }
}
