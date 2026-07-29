<?php

namespace App\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validação do login do portal do cliente (Plano 15, Task 15.2).
 *
 * Só o formato é validado aqui. Se o e-mail existe, se a senha confere e se o
 * acesso está ativo são decisões do `PortalAuthController`, que devolvem
 * sempre a mesma mensagem para os três casos - diferenciar aqui, no nível de
 * validação, abriria a mesma brecha que o controller fecha.
 */
class PortalLoginRequest extends FormRequest
{
    /**
     * Rota pública, sem ninguém autenticado ainda: não existe "dono do
     * registro" para conferir, porque é justamente esta requisição que
     * estabelece quem é o cliente. A ressalva da skill de multitenancy contra
     * `authorize() => true` no portal do cliente é sobre rota que opera em
     * cima de um recurso do cliente já autenticado (ver Task 15.3 em diante);
     * aqui não há recurso nenhum, só e-mail e senha.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Informe o e-mail.',
            'email.email' => 'Informe um e-mail válido.',
            'password.required' => 'Informe a senha.',
        ];
    }
}
