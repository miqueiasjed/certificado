<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Filtro de `GET /roteiros` (Plano 22, Task 22.5).
 *
 * `data` é sempre obrigatória: todo roteiro é de um dia específico.
 * `technician_id` fica opcional aqui de propósito - é
 * `RouteController::resolverTecnico()` quem decide se o campo é obrigatório
 * (só é, para quem não é o próprio técnico), porque essa é uma regra de
 * FLUXO ("quem está pedindo, e o que ele pode pedir"), não de formato de
 * campo, e por isso não pertence a este FormRequest.
 */
class RouteFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('roteiro-ver') ?? false;
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
            'data.required' => 'Informe a data do roteiro.',
            'data.date' => 'Informe uma data válida.',
            'technician_id.integer' => 'O técnico informado é inválido.',
            'technician_id.exists' => 'Técnico não encontrado.',
        ];
    }
}
