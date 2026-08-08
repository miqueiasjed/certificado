<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `PUT /roteiros/{route}/ordem` (Plano 22, Task 22.5): reordenação manual.
 *
 * Contrato escolhido (decisão de design documentada, a task deixava em
 * aberto entre "lista de `work_order_id`" e "`route_stop_id` + `ordem`"):
 * `work_order_ids`, a lista de identificadores de OS na nova ordem desejada,
 * do primeiro ao último. Mais simples para o frontend montar (arrastar e
 * soltar cartões que já mostram a OS, sem precisar saber o id interno de
 * `RouteStop`) e mais fácil de validar de uma vez (a lista precisa bater
 * exatamente com as paradas atuais do roteiro - `RouteService::reordenarManualmente()`
 * confere isso).
 */
class ReordenarRotaRequest extends FormRequest
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
            'work_order_ids' => ['required', 'array', 'min:1'],
            'work_order_ids.*' => ['required', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'work_order_ids.required' => 'Informe a nova ordem das paradas.',
            'work_order_ids.array' => 'A nova ordem precisa ser uma lista de ordens de serviço.',
            'work_order_ids.min' => 'Informe ao menos uma parada.',
            'work_order_ids.*.required' => 'Cada posição da lista precisa de uma ordem de serviço.',
            'work_order_ids.*.integer' => 'Cada ordem de serviço informada é inválida.',
        ];
    }
}
