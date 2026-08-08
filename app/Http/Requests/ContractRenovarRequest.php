<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Renovação de contrato (Plano 23, Task 23.6).
 *
 * Todo campo é opcional: `ContractRenewalService::renovar()` trata reajuste
 * ausente como 0% (mantém o valor do contrato anterior) e `end_date` ausente
 * como "a vigência nova dura os mesmos dias que a anterior" - os dois
 * comportamentos documentados no cabeçalho daquele método. A elegibilidade
 * (contrato já renovado, fora da janela de 90 dias antes / 30 depois do fim)
 * é regra de negócio do Service, não deste Request.
 *
 * `authorize()` devolve `true` porque a rota inteira já exige
 * `permission:contrato-renovar` e `module:contratos`.
 */
class ContractRenovarRequest extends FormRequest
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
            'percentual_reajuste' => ['nullable', 'numeric'],
            'indice_reajuste' => ['nullable', 'string', 'max:50'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'percentual_reajuste.numeric' => 'O percentual de reajuste precisa ser um número.',
            'indice_reajuste.max' => 'O nome do índice pode ter no máximo 50 caracteres.',
            'end_date.date_format' => 'Data de término inválida: use o formato AAAA-MM-DD.',
        ];
    }
}
