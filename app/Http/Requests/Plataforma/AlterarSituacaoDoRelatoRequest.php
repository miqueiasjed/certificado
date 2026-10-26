<?php

namespace App\Http\Requests\Plataforma;

use App\Models\ProblemReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Troca de situação de um relato de problema, na área da plataforma.
 *
 * `authorize()` confere `is_platform_admin` com a mesma comparação estrita do
 * `EnsurePlatformAdmin`, como reforço: a rota já está inteira atrás de
 * `platform.admin`.
 */
class AlterarSituacaoDoRelatoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_platform_admin === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'situacao' => ['required', 'string', Rule::in(ProblemReport::SITUACOES)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'situacao.required' => 'Escolha a situação do relato.',
            'situacao.in' => 'Situação de relato inválida.',
        ];
    }
}
