<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Baixa de parcela, dos dois lados do caixa (Plano 18, Task 18.7): o
 * recebimento de uma parcela a receber e o pagamento de uma parcela a pagar
 * têm exatamente os mesmos campos, e por isso compartilham este FormRequest.
 *
 * `data` é obrigatória e nunca cai para "hoje", pela mesma razão documentada
 * em `SettlementService::diaInformado()`: o depósito de sexta é lançado na
 * terça, e assumir hoje jogaria o dinheiro na competência errada do caixa sem
 * ninguém perceber. O formulário preenche o campo com a data de hoje; o
 * endpoint não.
 *
 * Quanto pode ser baixado (o saldo devedor da parcela, a parcela cancelada, a
 * parcela já quitada) é regra de negócio e fica no Service, dentro da mesma
 * transação que grava a baixa, com a parcela travada. Repetir a conferência
 * aqui daria uma segunda implementação da mesma regra, que é como as duas
 * passam a divergir.
 *
 * `authorize()` devolve `true` porque a rota inteira já exige
 * `permission:financeiro-baixar` e `module:financeiro`.
 */
class SettlementRequest extends FormRequest
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
            'valor' => 'required|numeric|min:0.01',
            'data' => 'required|date',
            'forma_pagamento' => 'nullable|string|max:50',
            'observacao' => 'nullable|string|max:1000',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'valor.required' => 'Informe o valor da baixa.',
            'valor.min' => 'O valor da baixa precisa ser maior que zero.',
            'data.required' => 'Informe a data em que o dinheiro entrou ou saiu.',
            'data.date' => 'A data informada é inválida.',
        ];
    }
}
