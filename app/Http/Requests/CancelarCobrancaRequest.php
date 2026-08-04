<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Cancelamento de uma cobrança emitida (Plano 19, Task 19.6).
 *
 * Um campo só, e ele é obrigatório: o motivo. Mesmo critério de
 * `App\Http\Requests\ReversalRequest` (estorno de baixa, Plano 18): quem
 * revisar depois precisa entender por que o boleto ou o Pix foi cancelado,
 * não só que foi. `App\Services\ChargeService::cancelar()` também recusa
 * motivo em branco (`ValidationException`), porque é chamado fora de
 * requisição HTTP em outros pontos (`reemitir()`); a validação aqui é a
 * mesma regra, só antecipada, para o usuário receber o erro no formulário.
 *
 * `authorize()` devolve `true` porque a rota inteira já exige
 * `permission:cobranca-emitir` e `module:cobranca_recorrente`.
 */
class CancelarCobrancaRequest extends FormRequest
{
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
            'motivo' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'motivo.required' => 'Informe o motivo do cancelamento.',
            'motivo.min' => 'O motivo do cancelamento precisa ter pelo menos 5 caracteres.',
        ];
    }
}
