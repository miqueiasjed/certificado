<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Contagem de um item de inventário (Plano 17, Task 17.7).
 *
 * `contado` aceita zero: prateleira vazia é resultado legítimo de contagem, e
 * é justamente o caso que mais precisa ser registrado.
 *
 * A obrigatoriedade da justificativa **não** vive aqui, e isso é decisão: quem
 * sabe se houve diferença é o `InventoryService`, que compara a contagem com o
 * saldo congelado na abertura. Repetir a comparação nesta classe exigiria ler
 * o item do banco e duplicaria a regra em dois lugares, com a garantia de que
 * um dia os dois discordariam. A recusa do Service volta como 422 pelo
 * controller.
 */
class InventoryCountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'contado' => 'required|numeric|min:0',
            'justificativa' => 'nullable|string|max:1000',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'contado.required' => 'Informe a quantidade contada.',
            'contado.numeric' => 'A quantidade contada precisa ser um número.',
            'contado.min' => 'A quantidade contada não pode ser negativa.',
            'justificativa.max' => 'A justificativa não pode passar de 1000 caracteres.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'contado' => 'quantidade contada',
            'justificativa' => 'justificativa',
        ];
    }
}
