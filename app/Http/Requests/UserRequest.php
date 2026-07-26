<?php

namespace App\Http\Requests;

use App\Support\DominioMultiempresa;
use App\Support\TenantAtual;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

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
            'technician_id' => ['nullable', 'integer', $this->regraDeTecnicoDaEmpresa()],
        ];
    }

    /**
     * Técnico existente E da empresa corrente.
     *
     * O `exists:technicians,id` solto era consulta crua, sem escopo: técnico de
     * qualquer empresa passava na validação, e o que impedia a gravação de
     * atravessar o tenant era o escopo global do Eloquent lá no Service, ou
     * seja, um efeito colateral e não uma decisão. Compondo a empresa aqui, o
     * id de fora para na validação, com mensagem, no lugar de sumir em silêncio
     * mais adiante.
     *
     * `exigirId()` em vez de `id()`: a rota é autenticada e `users.company_id`
     * é NOT NULL, então não existe requisição legítima sem empresa resolvida
     * neste ponto. Se existir, é bug, e falhar alto é melhor que validar contra
     * o banco inteiro.
     */
    private function regraDeTecnicoDaEmpresa(): Exists
    {
        return Rule::exists('technicians', 'id')
            ->where(DominioMultiempresa::COLUNA_TENANT, TenantAtual::exigirId());
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
