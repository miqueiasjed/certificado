<?php

namespace App\Http\Requests;

use App\Models\Goal;
use App\Support\DominioMultiempresa;
use App\Support\TenantAtual;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Meta individual (Plano 23, Task 23.6): criação de uma única `Goal` e edição
 * do alvo/observação de uma já existente.
 *
 * A criação em lote por competência (uma `Goal` por pessoa) tem o próprio
 * FormRequest, `GoalLoteRequest`, porque o formato do corpo é outro (lista de
 * pessoas, não uma pessoa só).
 *
 * Na edição (`PUT`), só `alvo` e `observacao` podem mudar: `user_id`, `tipo`
 * e `competencia` são a identidade da meta (compõem o unique
 * `[company_id, user_id, tipo, competencia]`) e trocar qualquer um deles
 * seria, de fato, criar outra meta, não editar esta.
 *
 * `authorize()` devolve `true` porque a rota inteira já exige
 * `permission:meta-gerenciar`.
 */
class GoalRequest extends FormRequest
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
        if (! $this->isMethod('post')) {
            return [
                'alvo' => ['required', 'numeric', 'min:0'],
                'observacao' => ['nullable', 'string', 'max:500'],
            ];
        }

        return [
            'user_id' => ['required', 'integer', $this->regraDeUsuarioDaEmpresa()],
            'tipo' => ['required', Rule::in(Goal::TIPOS)],
            'competencia' => [
                'required',
                'date_format:Y-m',
                Rule::unique('goals', 'competencia')->where(function ($consulta) {
                    return $consulta
                        ->where(DominioMultiempresa::COLUNA_TENANT, TenantAtual::exigirId())
                        ->where('user_id', $this->input('user_id'))
                        ->where('tipo', $this->input('tipo'));
                }),
            ],
            'alvo' => ['required', 'numeric', 'min:0'],
            'observacao' => ['nullable', 'string', 'max:500'],
        ];
    }

    private function regraDeUsuarioDaEmpresa(): Exists
    {
        return Rule::exists('users', 'id')
            ->where(DominioMultiempresa::COLUNA_TENANT, TenantAtual::exigirId());
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_id.required' => 'Informe a pessoa dona da meta.',
            'user_id.exists' => 'Usuário não encontrado.',
            'tipo.required' => 'Informe o tipo de meta.',
            'tipo.in' => 'Tipo de meta inválido.',
            'competencia.required' => 'Informe a competência da meta.',
            'competencia.date_format' => 'Competência inválida: use o formato AAAA-MM.',
            'competencia.unique' => 'Esta pessoa já tem uma meta deste tipo nesta competência.',
            'alvo.required' => 'Informe o alvo da meta.',
            'alvo.min' => 'O alvo não pode ser negativo.',
        ];
    }
}
