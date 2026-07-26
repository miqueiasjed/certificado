<?php

namespace App\Http\Requests\Plataforma;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validação do formulário de plano comercial, no painel do super admin.
 *
 * `slug` é único GLOBAL, sem compor com `company_id`: `plans` é catálogo da
 * plataforma, compartilhado entre todos os tenants (ver o cabeçalho da
 * migration `2026_07_26_190000_create_plans_table` e
 * `DominioMultiempresa::UNIQUES_GLOBAIS_MANTIDOS['plans']`).
 *
 * Os quatro limites (`limite_usuarios`, `limite_clientes`, `limite_os_mes`,
 * `limite_armazenamento_mb`) aceitam `null` (ilimitado) ou inteiro positivo;
 * nunca zero, porque zero teria o efeito ambíguo de "ilimitado" para quem lê
 * rápido e "bloqueado" para quem lê a regra do Plano 6.
 *
 * `authorize()` devolve true porque a rota inteira já está atrás do
 * middleware `platform.admin` (ver routes/web.php).
 */
class PlanRequest extends FormRequest
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
        $plano = $this->route('plan');

        return [
            'nome' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                'alpha_dash',
                Rule::unique('plans', 'slug')->ignore($plano?->id),
            ],
            'valor' => ['required', 'numeric', 'min:0'],
            'periodicidade' => ['required', 'string', Rule::in(['mensal', 'anual'])],
            'limite_usuarios' => ['nullable', 'integer', 'min:1'],
            'limite_clientes' => ['nullable', 'integer', 'min:1'],
            'limite_os_mes' => ['nullable', 'integer', 'min:1'],
            'limite_armazenamento_mb' => ['nullable', 'integer', 'min:1'],
            'ativo' => ['nullable', 'boolean'],
            'ordem' => ['nullable', 'integer', 'min:0'],
            'descricao' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nome.required' => 'O nome do plano é obrigatório.',
            'slug.required' => 'O slug é obrigatório.',
            'slug.alpha_dash' => 'O slug só pode ter letras, números, hífen e underline.',
            'slug.unique' => 'Já existe um plano com este slug.',
            'valor.required' => 'Informe o valor do plano.',
            'valor.numeric' => 'O valor precisa ser um número.',
            'valor.min' => 'O valor não pode ser negativo.',
            'periodicidade.required' => 'Selecione a periodicidade.',
            'periodicidade.in' => 'A periodicidade precisa ser mensal ou anual.',
            'limite_usuarios.min' => 'O limite de usuários precisa ser positivo, ou vazio para ilimitado.',
            'limite_clientes.min' => 'O limite de clientes precisa ser positivo, ou vazio para ilimitado.',
            'limite_os_mes.min' => 'O limite de OS por mês precisa ser positivo, ou vazio para ilimitado.',
            'limite_armazenamento_mb.min' => 'O limite de armazenamento precisa ser positivo, ou vazio para ilimitado.',
            'descricao.max' => 'A descrição pode ter no máximo 1000 caracteres.',
        ];
    }
}
