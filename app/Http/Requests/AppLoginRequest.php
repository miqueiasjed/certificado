<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validação do login do aplicativo do técnico (Plano 12, Task 12.2).
 *
 * `authorize() => true` é aceitável aqui: a rota é pública (não exige
 * `auth:sanctum`), então não há dono nem papel a checar antes da autenticação
 * em si. Quem decide se a credencial é válida e se o usuário pode entrar é o
 * `AppSessionService`, não este Request.
 */
class AppLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            // Nome do aparelho (ex.: "Moto G54 de João"), para a empresa
            // reconhecer a sessão na lista de `GET /api/app/sessoes`.
            'dispositivo' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'O e-mail é obrigatório.',
            'email.email' => 'Informe um e-mail válido.',
            'password.required' => 'A senha é obrigatória.',
            'dispositivo.required' => 'O nome do aparelho é obrigatório.',
            'dispositivo.max' => 'O nome do aparelho não pode ter mais de 255 caracteres.',
        ];
    }
}
