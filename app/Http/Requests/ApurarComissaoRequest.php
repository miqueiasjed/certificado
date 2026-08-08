<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Disparo manual da apuração de comissão de uma competência (Plano 23, Task
 * 23.6).
 *
 * `CommissionCalculationService::apurar()` já valida o formato da competência
 * internamente e lança `InvalidArgumentException` quando ele é inválido; a
 * regra abaixo repete a mesma checagem aqui só para o usuário receber o erro
 * de campo, no formulário, em vez de uma exceção genérica de 500.
 *
 * `authorize()` devolve `true` porque a rota inteira já exige
 * `permission:comissoes-apurar`.
 */
class ApurarComissaoRequest extends FormRequest
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
            'competencia' => ['required', 'date_format:Y-m'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'competencia.required' => 'Informe a competência a apurar.',
            'competencia.date_format' => 'Competência inválida: use o formato AAAA-MM.',
        ];
    }
}
