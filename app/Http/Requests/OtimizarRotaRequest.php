<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /roteiros/otimizar` (Plano 22, Task 22.5): recalcula e persiste a
 * ordem, descartando qualquer ordenação manual anterior - é a única ação do
 * sistema com esse efeito, pedida explicitamente por quem tem
 * `roteiro-gerenciar`. Mesmos campos de `RouteFilterRequest`, mesma regra de
 * "technician_id obrigatório só para quem não é o próprio técnico", decidida
 * no controller.
 */
class OtimizarRotaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('roteiro-gerenciar') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'data' => ['required', 'date'],
            'technician_id' => ['nullable', 'integer', 'exists:technicians,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'data.required' => 'Informe a data do roteiro a otimizar.',
            'data.date' => 'Informe uma data válida.',
            'technician_id.integer' => 'O técnico informado é inválido.',
            'technician_id.exists' => 'Técnico não encontrado.',
        ];
    }
}
