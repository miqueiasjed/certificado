<?php

namespace App\Http\Requests;

use App\Models\CommissionRule;
use App\Support\DominioMultiempresa;
use App\Support\TenantAtual;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Regra de comissão, na criação e na edição (Plano 23, Task 23.6).
 *
 * A regra é de vendedor OU de técnico, nunca das duas coisas (`tipo` decide
 * qual das duas colunas pode vir preenchida): `user_id` é proibido quando
 * `tipo` é `tecnico`, e `technician_id` é proibido quando `tipo` é
 * `vendedor`. Os dois podem ficar nulos - é a regra geral da empresa, que vale
 * para toda pessoa daquele tipo sem regra própria (ver o docblock de
 * `CommissionRule`).
 *
 * `user_id` e `technician_id` chegam pelo corpo da requisição e são
 * escopados à empresa corrente: sem isso, `exists:tabela,id` sozinho
 * consultaria a tabela inteira, de qualquer empresa (a regra roda direto no
 * query builder, sem passar pelo escopo global do Eloquent). Mesmo critério
 * de `UserRequest::regraDeTecnicoDaEmpresa()`.
 *
 * `authorize()` devolve `true` porque a rota inteira já exige
 * `permission:comissoes-apurar` (a regra de comissão é o insumo direto da
 * apuração, e por isso reaproveita a mesma permissão, em vez de uma nova só
 * para o cadastro).
 */
class CommissionRuleRequest extends FormRequest
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
            'tipo' => ['required', Rule::in(CommissionRule::TIPOS)],
            'user_id' => ['nullable', 'integer', 'prohibited_if:tipo,tecnico', $this->regraDeVendedorDaEmpresa()],
            'technician_id' => ['nullable', 'integer', 'prohibited_if:tipo,vendedor', $this->regraDeTecnicoDaEmpresa()],
            'base' => ['required', Rule::in(CommissionRule::BASES)],
            'forma' => ['required', Rule::in(CommissionRule::FORMAS)],
            'valor' => ['required', 'numeric', 'min:0'],
            'service_type_id' => ['nullable', 'integer', $this->regraDeTipoDeServicoDaEmpresa()],
            'vigencia_inicio' => ['required', 'date_format:Y-m-d'],
            'vigencia_fim' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:vigencia_inicio'],
            'ativa' => ['nullable', 'boolean'],
        ];
    }

    private function regraDeVendedorDaEmpresa(): Exists
    {
        return Rule::exists('users', 'id')
            ->where(DominioMultiempresa::COLUNA_TENANT, TenantAtual::exigirId());
    }

    private function regraDeTecnicoDaEmpresa(): Exists
    {
        return Rule::exists('technicians', 'id')
            ->where(DominioMultiempresa::COLUNA_TENANT, TenantAtual::exigirId());
    }

    private function regraDeTipoDeServicoDaEmpresa(): Exists
    {
        return Rule::exists('service_types', 'id')
            ->where(DominioMultiempresa::COLUNA_TENANT, TenantAtual::exigirId());
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tipo.required' => 'Informe se a regra é de vendedor ou de técnico.',
            'tipo.in' => 'Tipo inválido. Use vendedor ou técnico.',
            'user_id.prohibited_if' => 'Regra de técnico não pode ter vendedor vinculado.',
            'user_id.exists' => 'Vendedor não encontrado.',
            'technician_id.prohibited_if' => 'Regra de vendedor não pode ter técnico vinculado.',
            'technician_id.exists' => 'Técnico não encontrado.',
            'base.required' => 'Informe a base de cálculo da comissão.',
            'base.in' => 'Base de cálculo inválida.',
            'forma.required' => 'Informe se a comissão é percentual ou valor fixo.',
            'forma.in' => 'Forma de cálculo inválida.',
            'valor.required' => 'Informe o valor da regra.',
            'valor.min' => 'O valor da regra não pode ser negativo.',
            'service_type_id.exists' => 'Tipo de serviço não encontrado.',
            'vigencia_inicio.required' => 'Informe o início da vigência da regra.',
            'vigencia_fim.after_or_equal' => 'O fim da vigência não pode ser antes do início.',
        ];
    }
}
