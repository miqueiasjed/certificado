<?php

namespace App\Http\Requests;

use App\Services\PayableService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alteração do valor de um título a pagar (Plano 18, Task 18.7), com o
 * alcance que a tela da Task 18.9 pergunta antes de confirmar.
 *
 * `alcance` é obrigatório e não tem padrão de propósito: "apenas este" e
 * "este e os futuros" mudam quantos títulos são reescritos, e assumir um dos
 * dois em silêncio é como o aluguel dos próximos meses muda de valor sem
 * ninguém ter pedido. Título com parcela já paga nunca é alterado, decisão do
 * `PayableService::alterarValor()`.
 *
 * `authorize()` devolve `true` porque a rota inteira já exige
 * `permission:financeiro-titulos` e `module:financeiro`.
 */
class PayableValueRequest extends FormRequest
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
            'alcance' => ['required', Rule::in([
                PayableService::ALCANCE_APENAS_ESTE,
                PayableService::ALCANCE_ESTE_E_FUTUROS,
            ])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'valor.required' => 'Informe o novo valor do título.',
            'valor.min' => 'O valor do título precisa ser maior que zero.',
            'alcance.required' => 'Escolha se a alteração vale só para este título ou também para os futuros.',
            'alcance.in' => 'Alcance inválido. Use apenas_este ou este_e_futuros.',
        ];
    }
}
