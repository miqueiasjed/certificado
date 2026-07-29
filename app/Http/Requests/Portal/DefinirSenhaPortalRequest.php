<?php

namespace App\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validação da definição de senha do portal (Plano 15, Task 15.2): atende
 * tanto o primeiro acesso (link do convite) quanto a recuperação (link de
 * "esqueci minha senha"), porque as duas telas pedem exatamente os mesmos dois
 * campos.
 *
 * Rota pública, `authorize()` sempre `true`: quem autoriza esta requisição é o
 * token de 64 caracteres na URL, conferido em
 * `ClientUserService::definirSenha()`, não este método.
 */
class DefinirSenhaPortalRequest extends FormRequest
{
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
            'senha' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'senha.required' => 'Escolha uma senha.',
            'senha.min' => 'A senha precisa ter ao menos 8 caracteres.',
            'senha.confirmed' => 'A confirmação da senha não confere.',
        ];
    }
}
