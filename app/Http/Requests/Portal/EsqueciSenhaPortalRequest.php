<?php

namespace App\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validação do pedido de recuperação de senha do portal (Plano 15, Task 15.2).
 *
 * Rota pública, `authorize()` sempre `true`: não há nada a autorizar antes de
 * o cliente se identificar pelo próprio e-mail, e é exatamente isso que este
 * formulário pede.
 */
class EsqueciSenhaPortalRequest extends FormRequest
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
            'email' => ['required', 'string', 'email'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Informe o e-mail cadastrado no portal.',
            'email.email' => 'Informe um e-mail válido.',
        ];
    }
}
