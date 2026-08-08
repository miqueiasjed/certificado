<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validação do pedido de sugestão de preço (Plano 25, Task 25.5).
 *
 * Nenhum campo aqui é obrigatório além do nada: sem critério, a busca devolve
 * o histórico aprovado inteiro da própria empresa, que é uma resposta legítima
 * ("o que costumamos cobrar"). O que a sugestão nunca faz é gravar valor no
 * orçamento — ela é referência ao lado do campo, e quem digita é a pessoa.
 */
class SugerirPrecoRequest extends FormRequest
{
    /**
     * A rota já está atrás de `module:laudo_ia` e `permission:ia-gerar`.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'environment_type' => ['nullable', 'string', 'max:50'],
            'size' => ['nullable', 'string', 'max:50'],
            'rooms' => ['nullable', 'string', 'max:50'],
            'service_ids' => ['nullable', 'array', 'max:20'],
            'service_ids.*' => ['integer', 'min:1'],
            // Quando informado, é o orçamento que recebe a justificativa em
            // texto. Escopado pelo controller antes de qualquer uso: id vindo
            // do corpo não passa pelo escopo de empresa sozinho.
            'budget_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
