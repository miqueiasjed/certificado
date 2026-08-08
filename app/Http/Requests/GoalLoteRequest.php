<?php

namespace App\Http\Requests;

use App\Models\Goal;
use App\Support\DominioMultiempresa;
use App\Support\TenantAtual;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Criação em lote de meta por competência (Plano 23, Task 23.6): uma `Goal`
 * por pessoa informada, numa chamada só.
 *
 * `GoalController::storeLote()` grava com `updateOrCreate`, e não `create`:
 * reenviar o lote para ajustar o alvo de uma única pessoa não pode falhar
 * para o restante por violar o unique `[company_id, user_id, tipo,
 * competencia]` das demais linhas que não mudaram. Por isso este Request não
 * valida unicidade nenhuma - o upsert já é, por natureza, seguro para reenvio.
 *
 * `user_id` é escopado à empresa corrente pelo mesmo motivo de
 * `GoalRequest::regraDeUsuarioDaEmpresa()`.
 *
 * `authorize()` devolve `true` porque a rota inteira já exige
 * `permission:meta-gerenciar`.
 */
class GoalLoteRequest extends FormRequest
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
            'metas' => ['required', 'array', 'min:1'],
            'metas.*.user_id' => ['required', 'integer', $this->regraDeUsuarioDaEmpresa()],
            'metas.*.tipo' => ['required', Rule::in(Goal::TIPOS)],
            'metas.*.alvo' => ['required', 'numeric', 'min:0'],
            'metas.*.observacao' => ['nullable', 'string', 'max:500'],
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
            'competencia.required' => 'Informe a competência do lote de metas.',
            'competencia.date_format' => 'Competência inválida: use o formato AAAA-MM.',
            'metas.required' => 'Informe ao menos uma pessoa para receber meta.',
            'metas.min' => 'Informe ao menos uma pessoa para receber meta.',
            'metas.*.user_id.required' => 'Informe a pessoa de cada linha do lote.',
            'metas.*.user_id.exists' => 'Uma das pessoas informadas não foi encontrada.',
            'metas.*.tipo.required' => 'Informe o tipo de meta de cada linha do lote.',
            'metas.*.tipo.in' => 'Uma das linhas do lote tem tipo de meta inválido.',
            'metas.*.alvo.required' => 'Informe o alvo de cada linha do lote.',
            'metas.*.alvo.min' => 'O alvo não pode ser negativo.',
        ];
    }
}
