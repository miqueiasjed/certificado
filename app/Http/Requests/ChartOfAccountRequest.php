<?php

namespace App\Http\Requests;

use App\Models\ChartOfAccount;
use App\Support\TenantAtual;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Categoria do plano de contas (Plano 18, Task 18.7), na criação e na edição.
 *
 * `codigo` é único **por empresa**, nunca globalmente: cada empresa tem o
 * próprio plano de contas, e o mesmo "3.1.01" existir em duas empresas é o
 * caso normal, não o excepcional. A regra abaixo compõe o unique com
 * `company_id` justamente por isso; um unique global aqui impediria a
 * existência do segundo tenant, que é o item 3 de `divida-tecnica.md`.
 *
 * `parent_id` chega pelo corpo da requisição e é resolvido no controller pelo
 * model escopado, que devolve 404 para categoria de outra empresa. A
 * conferência de que a categoria-mãe não é a própria categoria (nem uma
 * descendente dela) também fica no controller, porque depende do registro que
 * está sendo editado.
 *
 * `authorize()` devolve `true` porque a rota inteira já exige
 * `permission:financeiro-plano-de-contas` e `module:financeiro`.
 */
class ChartOfAccountRequest extends FormRequest
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
        $conta = $this->route('conta');

        return [
            'codigo' => [
                'required',
                'string',
                'max:20',
                Rule::unique('chart_of_accounts', 'codigo')
                    ->where('company_id', TenantAtual::id())
                    ->ignore($conta instanceof ChartOfAccount ? $conta->getKey() : null),
            ],
            'nome' => 'required|string|max:255',
            'tipo' => ['required', Rule::in(ChartOfAccount::TIPOS)],
            'parent_id' => 'nullable|integer',
            'ativo' => 'nullable|boolean',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'codigo.required' => 'Informe o código da categoria.',
            'codigo.unique' => 'Já existe uma categoria com este código.',
            'nome.required' => 'Informe o nome da categoria.',
            'tipo.required' => 'Informe se a categoria é de receita ou de despesa.',
            'tipo.in' => 'Tipo inválido. Use receita ou despesa.',
        ];
    }
}
