<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validação do aceite de convite (Plano 8, Task 8.4).
 *
 * Dois campos, e só eles: **nome e senha**. E-mail, papel e empresa vêm do
 * convite, e nada que chegue neste formulário os altera. Aceitar um campo
 * `papel` aqui, mesmo "só para o formulário preencher", entregaria
 * administrador para quem tivesse o link.
 *
 * A confirmação de senha é obrigatória porque este é o único momento em que a
 * senha é definida: quem digita errado sem confirmação fica sem acesso e sem
 * convite (o token é de uso único), e a saída passa a ser a recuperação de
 * senha por um e-mail que ele pode nem controlar ainda.
 */
class AceitarConviteRequest extends FormRequest
{
    /**
     * Rota pública, e mesmo assim `true` aqui.
     *
     * Quem autoriza esta requisição é o token de 64 caracteres na URL, e ele é
     * conferido no `InvitationService` (existência, validade, estado), não
     * neste método. É a mesma forma de autorização do recibo assinado: a
     * credencial está no link, e não em uma sessão.
     *
     * Recusar aqui devolveria 403 sem mensagem para quem abriu um link expirado
     * ou já usado. Deixando a decisão no Service, a tela pública consegue
     * dizer o que aconteceu e oferecer o login, que é o caminho de quem já tem
     * conta.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:255'],
            'senha' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return [
            'nome.required' => 'Informe o seu nome.',
            'nome.max' => 'O nome não pode ter mais de 255 caracteres.',
            'senha.required' => 'Escolha uma senha.',
            'senha.min' => 'A senha precisa ter ao menos 8 caracteres.',
            'senha.confirmed' => 'A confirmação da senha não confere.',
        ];
    }
}
