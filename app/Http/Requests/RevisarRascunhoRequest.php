<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validação da revisão de um rascunho (Plano 25, Task 25.5).
 *
 * Só o texto revisado entra: situação, autor e instante são decididos pelo
 * `ParecerService`, nunca pelo formulário. Aceitar `situacao` do corpo seria
 * abrir um caminho para marcar como revisado sem ninguém ter lido nada.
 */
class RevisarRascunhoRequest extends FormRequest
{
    /**
     * A rota já está atrás de `module:laudo_ia` e `permission:ia-revisar` —
     * a permissão que carrega a responsabilidade profissional pelo texto.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'conteudo_revisado' => ['required', 'string', 'min:20', 'max:20000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'conteudo_revisado.required' => 'Escreva o texto revisado do parecer.',
            'conteudo_revisado.min' => 'O parecer revisado está curto demais para ser um texto técnico.',
            'conteudo_revisado.max' => 'O parecer revisado ultrapassou o tamanho máximo aceito.',
        ];
    }
}
