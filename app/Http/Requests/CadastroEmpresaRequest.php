<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validação do cadastro público de empresa (Plano 8, Task 8.7).
 *
 * É o único formulário do sistema que cria um tenant sem ninguém autenticado do
 * outro lado, e por isso ele é mais fechado que o `TenantRequest` do super
 * admin em três pontos:
 *
 * 1. **Nenhum campo de plataforma existe aqui.** `is_internal`, `situacao`,
 *    `plan_id`, `trial_ends_at`, `suspensa_em` e `motivo_suspensao` não são
 *    aceitos, nem como opcionais. `TenantService::criar()` já filtra por
 *    `CAMPOS_DO_TENANT` e `is_internal` não está nem lá, mas a defesa começa
 *    aqui: um campo a mais neste formulário não pode ser capaz de fabricar um
 *    tenant interno (imune a suspensão e a limite de plano) a partir da
 *    internet aberta. Plano também não entra, por decisão do plano: a empresa
 *    entra em avaliação e escolhe plano depois, pela tela de assinatura.
 *
 * 2. **CNPJ e telefone são obrigatórios**, enquanto no formulário do super
 *    admin são opcionais. Lá o cadastro é feito por alguém que conversou com o
 *    cliente e completa o que falta depois; aqui não existe esse alguém, e
 *    empresa sem CNPJ nem telefone emite documento com o cabeçalho incompleto.
 *
 * 3. **As mensagens de duplicidade são genéricas.** Ver `messages()`.
 *
 * ## Contrato do formulário (a tela da Task 8.8 monta em cima disto)
 *
 * - `name`, `cnpj`, `phone` - a empresa
 * - `administrador_nome`, `administrador_email` - quem vai administrá-la
 * - `administrador_senha` + `administrador_senha_confirmation` - a senha dela
 * - `aceite_termos` - booleano, precisa chegar verdadeiro
 *
 * Os nomes seguem os de `TenantService::criar()` de propósito: o payload
 * validado vai inteiro para o Service, sem remontagem no controller.
 */
class CadastroEmpresaRequest extends FormRequest
{
    /**
     * Rota pública, e mesmo assim `true` aqui.
     *
     * Não há papel nem permissão a exigir de quem ainda não tem conta: é para
     * criar a conta dele que a rota existe, mesma situação do aceite de convite
     * (`AceitarConviteRequest`). O que protege esta rota é o `throttle`
     * declarado nela e o fato de o formulário não aceitar nenhum campo capaz de
     * conceder privilégio.
     */
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
            'name' => ['required', 'string', 'max:255'],

            // Mesma regra de formato do `TenantRequest`: aceita os 14 dígitos
            // crus ou o formato mascarado que a tela envia. O `unique` é global
            // e não composto com `company_id` porque `companies` é a própria
            // tabela de tenants, e não um cadastro escopado por empresa.
            'cnpj' => [
                'required',
                'string',
                'regex:/^(\d{14}|\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2})$/',
                Rule::unique('companies', 'cnpj'),
            ],

            'phone' => ['required', 'string', 'max:20'],

            'administrador_nome' => ['required', 'string', 'max:255'],

            // `users.email` é unique global de propósito (o login é por
            // e-mail), então este `unique` não leva `company_id`. Ele também
            // não substitui a unique do banco: duas requisições simultâneas
            // com o mesmo e-mail passam as duas por aqui, e quem recusa a
            // segunda é o índice. O controller trata esse desfecho.
            'administrador_email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email'),
            ],

            // `min:8` e `confirmed`, iguais aos do aceite de convite: são as
            // duas portas de entrada de senha nova do sistema, e exigir regras
            // diferentes em cada uma só confundiria quem se cadastra hoje e
            // convida a equipe amanhã.
            'administrador_senha' => ['required', 'string', 'min:8', 'confirmed'],

            // `boolean` recusa "yes"/"on" e `accepted` recusa `false`: juntos,
            // só o booleano verdadeiro passa. O aceite fica registrado por ter
            // sido condição para a empresa existir, e não em coluna própria.
            'aceite_termos' => ['required', 'boolean', 'accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Informe o nome da sua empresa.',
            'name.max' => 'O nome da empresa não pode ter mais de 255 caracteres.',

            'cnpj.required' => 'Informe o CNPJ da sua empresa.',
            'cnpj.regex' => 'Informe um CNPJ válido, no formato 00.000.000/0000-00.',

            // As duas mensagens de duplicidade abaixo são deliberadamente
            // genéricas. Quem está do outro lado é anônimo, e confirmar "este
            // CNPJ pertence à empresa Tal" entregaria dado de cliente a quem só
            // digitou um número. Elas dizem que o cadastro não segue por aqui e
            // apontam o caminho de quem já é cliente: entrar, ou pedir convite
            // a quem já administra a conta.
            'cnpj.unique' => 'Não foi possível criar a conta com este CNPJ. Se a sua empresa já usa o sistema, '
                .'entre com a sua conta ou peça um convite a quem administra a conta dela.',

            'phone.required' => 'Informe um telefone de contato.',
            'phone.max' => 'O telefone não pode ter mais de 20 caracteres.',

            'administrador_nome.required' => 'Informe o seu nome.',
            'administrador_nome.max' => 'O nome não pode ter mais de 255 caracteres.',

            'administrador_email.required' => 'Informe o seu e-mail.',
            'administrador_email.email' => 'Informe um e-mail válido.',
            'administrador_email.unique' => 'Não foi possível criar a conta com este e-mail. Se você já tem acesso '
                .'ao sistema, entre com a sua conta.',

            'administrador_senha.required' => 'Escolha uma senha.',
            'administrador_senha.min' => 'A senha precisa ter ao menos 8 caracteres.',
            'administrador_senha.confirmed' => 'A confirmação da senha não confere.',

            'aceite_termos.required' => 'É preciso aceitar os termos de uso para criar a conta.',
            'aceite_termos.accepted' => 'É preciso aceitar os termos de uso para criar a conta.',
            'aceite_termos.boolean' => 'É preciso aceitar os termos de uso para criar a conta.',
        ];
    }

    /**
     * O payload validado no formato que `TenantService::criar()` espera.
     *
     * A única diferença em relação a `validated()` é `email`: a empresa nasce
     * com o e-mail do administrador como contato, porque é o único e-mail que
     * este formulário coleta e é para ele que a régua de avaliação
     * (`AvaliacaoService`) e a de cobrança (`InadimplenciaService`) escrevem.
     * Empresa sem `email` entraria em avaliação e chegaria ao fim do prazo sem
     * ninguém avisado. A empresa pode trocar esse contato depois, em
     * configurações.
     *
     * `aceite_termos` segue junto e é ignorado pelo Service (`Arr::only` filtra
     * por `CAMPOS_DO_TENANT`); tirá-lo aqui só acrescentaria uma linha que
     * precisaria ser mantida em sincronia com o Service.
     *
     * @return array<string, mixed>
     */
    public function dadosDoTenant(): array
    {
        return $this->validated() + [
            'email' => (string) $this->validated('administrador_email'),
        ];
    }
}
