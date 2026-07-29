<?php

namespace App\Http\Requests;

use App\Models\ProductBatch;
use App\Support\TenantAtual;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cadastro e edição de lote (Plano 17, Task 17.7), com a entrada inicial
 * opcional no cadastro.
 *
 * Duas decisões que valem comentário:
 *
 * - **`product_id` sai da requisição na edição.** Trocar o produto de um lote
 *   que já tem movimento faria o razão dizer que o lote de um produto foi
 *   aplicado na conta de outro, que é justamente a pergunta que o módulo
 *   existe para responder. Na edição o campo é descartado com a regra
 *   `exclude`, e o produto continua sendo o gravado.
 * - **A unicidade é conferida aqui, e é composta com a empresa.** O unique da
 *   tabela é `(company_id, product_id, lote)`; sem esta regra, o segundo lote
 *   de mesmo código chegaria ao banco e voltaria como erro 500 em vez de
 *   mensagem no campo. Empresas diferentes continuam podendo repetir "L-001",
 *   que é o ponto do unique composto.
 *
 * `validade` e `recebido_em` são dias (colunas `date`), não instantes: nunca
 * sofrem conversão de fuso, e a comparação de vencimento é feita por dia no
 * fuso do negócio pelas consultas da Task 17.3.
 *
 * Lote com validade já vencida é aceito de propósito: é assim que se cadastra
 * o que já está na prateleira para poder descartar com registro.
 */
class ProductBatchRequest extends FormRequest
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
        $lote = $this->loteDaRota();
        $ehCriacao = $lote === null;

        return [
            'product_id' => $ehCriacao
                ? 'required|integer|exists:products,id'
                : 'exclude',

            'lote' => [
                'required',
                'string',
                'max:100',
                Rule::unique('product_batches', 'lote')
                    ->where('company_id', TenantAtual::id())
                    ->where('product_id', $ehCriacao ? $this->input('product_id') : $lote->product_id)
                    ->ignore($lote?->getKey()),
            ],

            'validade' => 'required|date',
            'recebido_em' => 'required|date',
            // 4 casas: custo por mililitro de saneante concentrado é fração
            // pequena, e arredondar para 2 zeraria o custo do serviço.
            'custo_unitario' => 'required|numeric|min:0',
            'fornecedor' => 'nullable|string|max:255',
            'nota_fiscal' => 'nullable|string|max:255',

            // Entrada inicial, só no cadastro: informada a quantidade, o local
            // passa a ser obrigatório, porque saldo sem lugar não existe.
            'quantidade' => $ehCriacao ? 'nullable|numeric|min:0.0001' : 'exclude',
            'stock_location_id' => $ehCriacao
                ? 'nullable|required_with:quantidade|integer|exists:stock_locations,id'
                : 'exclude',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'product_id.required' => 'Informe o produto do lote.',
            'product_id.exists' => 'O produto informado não existe.',
            'lote.required' => 'Informe o número do lote.',
            'lote.unique' => 'Este produto já tem um lote com esse número.',
            'lote.max' => 'O número do lote não pode passar de 100 caracteres.',
            'validade.required' => 'Informe a validade do lote.',
            'validade.date' => 'A validade informada é inválida.',
            'recebido_em.required' => 'Informe a data de recebimento do lote.',
            'recebido_em.date' => 'A data de recebimento é inválida.',
            'custo_unitario.required' => 'Informe o custo unitário do lote.',
            'custo_unitario.numeric' => 'O custo unitário precisa ser um número.',
            'custo_unitario.min' => 'O custo unitário não pode ser negativo.',
            'quantidade.numeric' => 'A quantidade da entrada inicial precisa ser um número.',
            'quantidade.min' => 'A quantidade da entrada inicial precisa ser maior que zero.',
            'stock_location_id.required_with' => 'Informe o local que vai receber a entrada inicial.',
            'stock_location_id.exists' => 'O local de estoque informado não existe.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'product_id' => 'produto',
            'lote' => 'lote',
            'validade' => 'validade',
            'recebido_em' => 'data de recebimento',
            'custo_unitario' => 'custo unitário',
            'fornecedor' => 'fornecedor',
            'nota_fiscal' => 'nota fiscal',
            'quantidade' => 'quantidade da entrada inicial',
            'stock_location_id' => 'local de estoque',
        ];
    }

    /**
     * Lote resolvido pelo route-model binding na edição, e `null` no cadastro.
     */
    private function loteDaRota(): ?ProductBatch
    {
        $lote = $this->route('lote');

        return $lote instanceof ProductBatch ? $lote : null;
    }
}
