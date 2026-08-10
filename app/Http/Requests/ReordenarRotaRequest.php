<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `PUT /roteiros/{route}/ordem` (Plano 22, Task 22.5): reordenação manual.
 *
 * Contrato trocado na Task 31.3 (Plano 31): com `Compromisso` como segunda
 * origem possível de parada (ao lado de `WorkOrder`), `work_order_ids: [int]`
 * deixou de identificar toda parada do roteiro. O corpo passa a ser
 * `paradas: [string]`, cada item no formato `"os:{id}"` ou
 * `"compromisso:{id}"` (ex.: `["os:12", "compromisso:5", "os:7"]`) - mantém
 * o corpo um array plano de strings, mais simples de montar no frontend
 * (arrastar e soltar cartões que já sabem o próprio tipo e id) do que uma
 * lista de objetos, e mais simples de validar com uma regra de formato só.
 * A lista continua precisando bater exatamente com as paradas atuais do
 * roteiro - `RouteService::reordenarManualmente()` confere isso.
 *
 * Sem período de transição entre os dois contratos: backend e frontend
 * mudam juntos dentro do Plano 31, então o formato antigo (`work_order_ids`)
 * não é mais aceito.
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
            'paradas' => ['required', 'array', 'min:1'],
            'paradas.*' => ['required', 'string', 'regex:/^(os|compromisso):\d+$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'paradas.required' => 'Informe a nova ordem das paradas.',
            'paradas.array' => 'A nova ordem precisa ser uma lista de paradas.',
            'paradas.min' => 'Informe ao menos uma parada.',
            'paradas.*.required' => 'Cada posição da lista precisa de uma parada.',
            'paradas.*.string' => 'Cada parada informada é inválida.',
            'paradas.*.regex' => 'Cada parada informada é inválida.',
        ];
    }
}
