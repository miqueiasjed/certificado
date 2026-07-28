<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validação do convite de usuário (Plano 8, Task 8.4).
 *
 * O `unique` em `users` está aqui para o erro sair no campo, e não como
 * mensagem solta no topo do formulário. Ele não substitui a checagem do
 * `InvitationService`: o Service é chamado também de fora desta rota, e é lá
 * que a regra vale de verdade.
 *
 * `papel` é validado contra o catálogo de papéis do Spatie, que é global (sem
 * `teams`, decisão do Plano 2). Papel inventado no formulário para de existir
 * aqui, antes de virar um convite que só falharia na hora em que o convidado
 * tentasse criar a senha dele.
 */
class ConviteRequest extends FormRequest
{
    /**
     * A rota inteira é protegida por `permission:usuario-criar`, o mesmo
     * critério já usado em `UserRequest`.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'nome' => ['nullable', 'string', 'max:255'],
            'papel' => ['required', 'string', 'exists:roles,name'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'O e-mail é obrigatório.',
            'email.email' => 'Informe um e-mail válido.',
            'email.max' => 'O e-mail não pode ter mais de 255 caracteres.',
            'email.unique' => 'Já existe um usuário com este e-mail no sistema.',
            'nome.max' => 'O nome não pode ter mais de 255 caracteres.',
            'papel.required' => 'Escolha o papel que a pessoa convidada terá.',
            'papel.exists' => 'O papel selecionado não existe.',
        ];
    }
}
