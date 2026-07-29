<?php

namespace App\Http\Requests;

use App\Services\ReceivableService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Criação de título a receber pela tela de contas a receber (Plano 18,
 * Task 18.7).
 *
 * Duas origens no mesmo endpoint, e o que decide qual delas vale é a presença
 * de `work_order_id`:
 *
 * - **Com OS**: o título nasce de `ReceivableService::gerarDaOs()`, que já é
 *   idempotente por ordem de serviço. Cliente, valor e vencimento saem da
 *   própria OS quando não vierem informados.
 * - **Sem OS**: título avulso, e aí `client_id`, `valor` e
 *   `primeiro_vencimento` passam a ser obrigatórios, porque não existe origem
 *   de onde tirá-los.
 *
 * A validação aqui é de formato e de obrigatoriedade. A empresa dona de cada
 * id (`client_id`, `work_order_id`, `chart_of_account_id`) é conferida no
 * controller, resolvendo cada um pelo model escopado: id vindo do corpo da
 * requisição não passa pelo escopo global sozinho, e é assim que se cria
 * cobrança em cima do cliente de outra empresa sem ninguém perceber.
 *
 * `authorize()` devolve `true` porque a rota inteira já exige
 * `permission:financeiro-titulos` e `module:financeiro`. Não é rota de
 * autoatendimento nem do portal do cliente, os dois casos em que a skill
 * `permissoes-e-multitenancy` proíbe esse atalho.
 */
class ReceivableRequest extends FormRequest
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
        $semOrdemDeServico = ! $this->filled('work_order_id');

        return [
            'work_order_id' => 'nullable|integer',
            'client_id' => ($semOrdemDeServico ? 'required' : 'nullable').'|integer',
            'chart_of_account_id' => 'nullable|integer',
            'descricao' => ($semOrdemDeServico ? 'required' : 'nullable').'|string|max:255',
            // `min:0.01` e não `min:0`: título de valor zero não é cobrança
            // nenhuma, e o Service recusa de qualquer forma.
            'valor' => ($semOrdemDeServico ? 'required' : 'nullable').'|numeric|min:0.01',
            'primeiro_vencimento' => ($semOrdemDeServico ? 'required' : 'nullable').'|date',
            'emitido_em' => 'nullable|date',
            'forma' => ['nullable', Rule::in([ReceivableService::FORMA_A_VISTA, ReceivableService::FORMA_PARCELADO])],
            // Teto de 60 parcelas: é o maior parcelamento que a empresa faz na
            // prática (5 anos), e sem teto um erro de digitação geraria
            // milhares de linhas em uma transação só.
            'parcelas' => 'nullable|integer|min:1|max:60',
            // Os dois intervalos são exclusivos entre si: informar os dois
            // deixaria a data da segunda parcela dependendo da ordem de leitura
            // do array dentro do Service.
            'intervalo_dias' => 'nullable|integer|min:1|max:365|prohibits:intervalo_meses',
            'intervalo_meses' => 'nullable|integer|min:1|max:12',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'client_id.required' => 'Informe o cliente que vai ser cobrado.',
            'descricao.required' => 'Informe a descrição do título.',
            'valor.required' => 'Informe o valor do título.',
            'valor.min' => 'O valor do título precisa ser maior que zero.',
            'primeiro_vencimento.required' => 'Informe o vencimento da primeira parcela.',
            'primeiro_vencimento.date' => 'A data de vencimento informada é inválida.',
            'parcelas.max' => 'O parcelamento aceita no máximo 60 parcelas.',
            'intervalo_dias.prohibits' => 'Informe o intervalo entre as parcelas em dias ou em meses, nunca nos dois.',
        ];
    }
}
