<?php

namespace App\Http\Requests\Plataforma;

use App\Support\BusinessDate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validação do formulário de tenant (criação e edição), no painel do super
 * admin.
 *
 * As chaves aqui espelham exatamente o contrato de `$dados` documentado no
 * PHPDoc de `TenantService::criar()`: os campos de `TenantService::CAMPOS_DO_TENANT`
 * mais `administrador_nome`/`administrador_email`, só na criação. Nenhum
 * campo de estado de plataforma (`is_internal`, `situacao`, `suspensa_em`,
 * `motivo_suspensao`) aparece aqui de propósito: quem muda situação é
 * `TenantService::suspender()`/`reativar()`, nunca o formulário de cadastro.
 *
 * `authorize()` devolve true porque a rota inteira já está atrás do
 * middleware `platform.admin` (ver routes/web.php): não é rota de
 * autoatendimento nem de portal do cliente.
 */
class TenantRequest extends FormRequest
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
        $empresa = $this->route('company');
        $criando = $empresa === null;

        $regras = [
            'name' => ['required', 'string', 'max:255'],

            // Aceita tanto o formato mascarado (00.000.000/0000-00) quanto os
            // 14 dígitos crus: quem preenche o formulário de tenant é o super
            // admin, e a tela pode enviar em qualquer um dos dois formatos.
            // Único ENTRE TENANTS: `companies` é a própria tabela de tenants,
            // não é escopada por empresa, então um unique comum já cobre.
            'cnpj' => [
                'nullable',
                'string',
                'regex:/^(\d{14}|\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2})$/',
                Rule::unique('companies', 'cnpj')->ignore($empresa?->id),
            ],

            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'street' => ['nullable', 'string', 'max:255'],
            'number' => ['nullable', 'string', 'max:20'],
            'complement' => ['nullable', 'string', 'max:255'],
            'district' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:2'],
            'zip' => ['nullable', 'string', 'max:9'],

            'plan_id' => ['nullable', 'integer', 'exists:plans,id'],

            // Dia puro, sem hora: comparado contra o dia de hoje no fuso do
            // negócio (BusinessDate::hoje()), nunca contra "today" resolvido
            // pelo timezone do servidor (UTC, dívida técnica conhecida). Um
            // "after:today" comum compararia contra o dia em UTC e poderia
            // aceitar ou recusar a data errada perto da virada da meia-noite
            // de Brasília.
            'trial_ends_at' => [
                'nullable',
                'date',
                'after:'.BusinessDate::hoje()->toDateString(),
            ],
        ];

        // Campos do administrador: só existem no formulário de criação.
        // `TenantService::atualizar()` nem os aceita (`Arr::only` filtra pelos
        // campos da empresa), então não faz sentido validar, e muito menos
        // exigir, o e-mail do administrador ao editar um tenant existente.
        if ($criando) {
            $regras['administrador_nome'] = ['required', 'string', 'max:255'];
            $regras['administrador_email'] = [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email'),
            ];
        }

        return $regras;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'O nome do tenant é obrigatório.',
            'name.max' => 'O nome não pode ter mais de 255 caracteres.',
            'cnpj.regex' => 'Informe um CNPJ válido, no formato 00.000.000/0000-00.',
            'cnpj.unique' => 'Já existe um tenant cadastrado com este CNPJ.',
            'email.email' => 'Informe um e-mail válido.',
            'plan_id.exists' => 'O plano selecionado não existe.',
            'trial_ends_at.date' => 'Informe uma data válida.',
            'trial_ends_at.after' => 'O fim da avaliação precisa ser uma data futura.',
            'administrador_nome.required' => 'O nome do administrador é obrigatório.',
            'administrador_email.required' => 'O e-mail do administrador é obrigatório.',
            'administrador_email.email' => 'Informe um e-mail válido para o administrador.',
            'administrador_email.unique' => 'Já existe um usuário cadastrado com este e-mail.',
        ];
    }
}
