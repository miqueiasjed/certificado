<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserRequest extends FormRequest
{
    /**
     * A rota inteira é protegida por permissão administrativa
     * (usuario-criar/usuario-editar), então liberar aqui é aceitável.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $usuarioId = $this->route('user')?->id;

        return [
            'name' => 'required|string|max:255',
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($usuarioId),
            ],
            'password' => [
                $this->isMethod('post') ? 'required' : 'nullable',
                'string',
                'min:8',
            ],
            'role' => ['required', 'string', 'exists:roles,name'],
            'technician_id' => ['nullable', 'integer', 'exists:technicians,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'O nome é obrigatório.',
            'name.max' => 'O nome não pode ter mais de 255 caracteres.',
            'email.required' => 'O e-mail é obrigatório.',
            'email.email' => 'Informe um e-mail válido.',
            'email.max' => 'O e-mail não pode ter mais de 255 caracteres.',
            'email.unique' => 'Este e-mail já está sendo usado por outro usuário.',
            'password.required' => 'A senha é obrigatória.',
            'password.min' => 'A senha precisa ter ao menos 8 caracteres.',
            'role.required' => 'O papel é obrigatório.',
            'role.exists' => 'O papel selecionado não existe.',
            'technician_id.integer' => 'Técnico inválido.',
            'technician_id.exists' => 'O técnico selecionado não existe.',
        ];
    }
}
