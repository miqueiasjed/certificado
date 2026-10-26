<?php

namespace App\Http\Requests;

use App\Models\ProblemReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * Validação do relato de problema, no painel (`relatos.store`) e no portal do
 * cliente (`portal.relatos.store`).
 *
 * Só a descrição é obrigatória: título vazio vira o começo da descrição em
 * `ProblemReportService`. `pagina_url` não é digitada por ninguém, o
 * formulário manda a URL da tela aberta; por isso ela é normalizada aqui em
 * vez de recusada, e quem relatou nunca vê erro de um campo que não preencheu.
 *
 * Quem relatou e a empresa nunca vêm do corpo: os dois saem do usuário
 * autenticado. `authorize()` confere que existe um, no guard do painel ou no
 * do portal, como reforço ao middleware de cada rota.
 */
class RelatarProblemaRequest extends FormRequest
{
    /**
     * Teto da descrição. Abaixo dos 20000 do sistema central porque a coluna
     * é `text` (65535 bytes): 10000 caracteres cabem mesmo com acentuação e
     * emoji ocupando até 4 bytes cada.
     */
    public const LIMITE_DESCRICAO = 10000;

    public function authorize(): bool
    {
        return $this->user() !== null || Auth::guard('cliente')->check();
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'pagina_url' => $this->paginaNormalizada($this->input('pagina_url')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'titulo' => ['nullable', 'string', 'max:'.ProblemReport::LIMITE_TITULO],
            'descricao' => ['required', 'string', 'max:'.self::LIMITE_DESCRICAO],
            'pagina_url' => ['nullable', 'string', 'url:http,https', 'max:'.ProblemReport::LIMITE_URL],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'titulo.max' => 'O título pode ter no máximo '.ProblemReport::LIMITE_TITULO.' caracteres.',
            'descricao.required' => 'Descreva o problema que você encontrou.',
            'descricao.max' => 'A descrição pode ter no máximo '.self::LIMITE_DESCRICAO.' caracteres.',
        ];
    }

    /**
     * URL absoluta da tela, cortada à parte que cabe no contrato.
     *
     * Valor que não é URL http(s) vira `null`. URL longa demais perde a query
     * string e o fragmento, que é onde mora o excesso (filtros, paginação); se
     * ainda passar do teto, vira `null`, porque cortar no meio do caminho daria
     * um link quebrado.
     */
    private function paginaNormalizada(mixed $valor): ?string
    {
        if (! is_string($valor)) {
            return null;
        }

        $valor = trim($valor);

        if ($valor === '' || filter_var($valor, FILTER_VALIDATE_URL) === false
            || ! in_array(strtolower((string) parse_url($valor, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            return null;
        }

        if (mb_strlen($valor) > ProblemReport::LIMITE_URL) {
            $valor = strtok($valor, '?#') ?: $valor;
        }

        return mb_strlen($valor) > ProblemReport::LIMITE_URL ? null : $valor;
    }
}
