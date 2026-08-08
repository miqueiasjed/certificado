<?php

namespace App\Http\Requests;

use App\Support\DominioMultiempresa;
use App\Support\TenantAtual;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Marca a comissão fechada como paga (Plano 23, Task 23.6), com a opção de já
 * gerar o título a pagar do Plano 18.
 *
 * `supplier_id` e `vencimento` só são exigidos quando `gerar_titulo` vem
 * `true`: sem gerar título, a comissão só muda de situação, sem precisar de
 * nenhum dos dois. `CommissionClosingService::marcarComoPaga()` valida os
 * mesmos dois campos de novo, porque também é alcançável fora desta rota (uso
 * futuro, ex. rotina em lote); a regra aqui só antecipa o erro para o
 * formulário.
 *
 * `supplier_id` é escopado à empresa corrente: vindo do corpo da requisição,
 * `exists:suppliers,id` sozinho consultaria a tabela inteira, de qualquer
 * empresa (a regra `exists` do Laravel roda direto no query builder, sem
 * passar pelo escopo global do Eloquent). Mesmo critério de
 * `UserRequest::regraDeTecnicoDaEmpresa()`.
 *
 * `authorize()` devolve `true` porque a rota inteira já exige
 * `permission:comissoes-fechar` (reaproveitada aqui: marcar como paga é a
 * continuação do fluxo de fechamento da comissão, não uma permissão nova).
 */
class MarcarComissaoComoPagaRequest extends FormRequest
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
            'gerar_titulo' => ['nullable', 'boolean'],
            'supplier_id' => ['required_if:gerar_titulo,true', 'nullable', 'integer', $this->regraDeFornecedorDaEmpresa()],
            'vencimento' => ['required_if:gerar_titulo,true', 'nullable', 'date_format:Y-m-d'],
            'descricao' => ['nullable', 'string', 'max:255'],
            'chart_of_account_id' => ['nullable', 'integer', $this->regraDeCategoriaDaEmpresa()],
        ];
    }

    private function regraDeFornecedorDaEmpresa(): \Illuminate\Validation\Rules\Exists
    {
        return Rule::exists('suppliers', 'id')
            ->where(DominioMultiempresa::COLUNA_TENANT, TenantAtual::exigirId());
    }

    private function regraDeCategoriaDaEmpresa(): \Illuminate\Validation\Rules\Exists
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
            'supplier_id.required_if' => 'Informe o fornecedor cadastrado que representa esta pessoa, para gerar o título a pagar.',
            'supplier_id.exists' => 'Fornecedor não encontrado.',
            'vencimento.required_if' => 'Informe o vencimento do título a pagar.',
            'vencimento.date_format' => 'Vencimento inválido: use o formato AAAA-MM-DD.',
            'chart_of_account_id.exists' => 'Categoria do plano de contas não encontrada.',
        ];
    }
}
