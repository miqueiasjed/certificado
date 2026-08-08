<?php

namespace App\Http\Requests;

use App\Models\Refueling;
use App\Support\DominioMultiempresa;
use App\Support\TenantAtual;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Registro de abastecimento (Plano 27, Task 27.4).
 *
 * A recusa de quilometragem retroativa **não** mora aqui: ela depende do estado
 * do veículo (`km_atual`) e é regra de negócio, então fica no
 * `RefuelingService`, que também é chamado fora de requisição HTTP. Aqui só
 * entra o que é forma do dado.
 *
 * `gerar_titulo` é opcional e nasce falso: o título a pagar é oferecido, nunca
 * criado automaticamente. Quando vem verdadeiro, `supplier_id` passa a ser
 * obrigatório — título sem fornecedor não existe no Plano 18.
 *
 * `supplier_id` e `chart_of_account_id` chegam pelo corpo da requisição e são
 * escopados à empresa corrente na própria regra: `exists:` solto roda no query
 * builder, fora do escopo global do Eloquent, e aceita id de outra empresa
 * (além de responder se aquele id existe em algum tenant, o que já é
 * vazamento). O fornecedor ainda é reconsultado pelo model escopado em
 * `RefuelingService::criarTitulo()`; a regra aqui adianta o erro para o
 * formulário, e é a única defesa que `chart_of_account_id` tem, porque ele
 * segue direto para o título como id cru.
 */
class RefuelingRequest extends FormRequest
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
            'data' => 'required|date',
            'km' => 'required|integer|min:0',
            'litros' => 'required|numeric|gt:0',
            'valor_total' => 'required|numeric|min:0',
            'valor_litro' => 'nullable|numeric|min:0',
            'tipo_combustivel' => ['required', Rule::in(Refueling::TIPOS_DE_COMBUSTIVEL)],
            'posto' => 'nullable|string|max:255',
            'tanque_cheio' => 'boolean',

            // Oferta de título a pagar (Plano 18).
            'gerar_titulo' => 'boolean',
            'supplier_id' => ['required_if:gerar_titulo,true,1', 'nullable', 'integer', $this->regraDeFornecedorDaEmpresa()],
            'vencimento' => 'nullable|date',
            'chart_of_account_id' => ['nullable', 'integer', $this->regraDeCategoriaDaEmpresa()],
        ];
    }

    /**
     * Fornecedor existente E da empresa corrente. `exigirId()` em vez de
     * `id()`: a rota é autenticada e `users.company_id` é NOT NULL, então
     * requisição sem empresa resolvida é bug, e falhar alto é melhor que
     * validar contra o banco inteiro.
     */
    private function regraDeFornecedorDaEmpresa(): Exists
    {
        return Rule::exists('suppliers', 'id')
            ->where(DominioMultiempresa::COLUNA_TENANT, TenantAtual::exigirId());
    }

    /**
     * Categoria do plano de contas existente E da empresa corrente.
     * `ChartOfAccount` é model de domínio escopado (`BelongsToCompany`): cada
     * empresa tem o próprio plano de contas.
     */
    private function regraDeCategoriaDaEmpresa(): Exists
    {
        return Rule::exists('chart_of_accounts', 'id')
            ->where(DominioMultiempresa::COLUNA_TENANT, TenantAtual::exigirId());
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'data.required' => 'Informe a data do abastecimento.',
            'km.required' => 'Informe a quilometragem do hodômetro no momento do abastecimento.',
            'litros.gt' => 'A quantidade de litros precisa ser maior que zero.',
            'valor_total.required' => 'Informe o valor total do abastecimento.',
            'tipo_combustivel.in' => 'Tipo de combustível inválido.',
            'supplier_id.required_if' => 'Para gerar o título a pagar, informe o fornecedor.',
            'supplier_id.exists' => 'O fornecedor selecionado não existe.',
            'chart_of_account_id.exists' => 'Categoria do plano de contas não encontrada.',
        ];
    }
}
