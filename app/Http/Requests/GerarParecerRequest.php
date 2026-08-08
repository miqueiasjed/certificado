<?php

namespace App\Http\Requests;

use App\Models\AiDraft;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validação do pedido de geração de rascunho (Plano 25, Task 25.5).
 *
 * `origem_id` chega do corpo da requisição, e não da rota, então o Service e o
 * controller precisam escopá-lo antes de usar: id vindo do formulário não
 * passa pelo escopo global de empresa por si só. Quem faz isso é
 * `AiDraftController::resolverOrigem()`, consultando o model — e é a consulta
 * pelo model, com o escopo global ativo, que garante o isolamento.
 */
class GerarParecerRequest extends FormRequest
{
    /**
     * A rota inteira já está atrás de `module:laudo_ia` e
     * `permission:ia-gerar`.
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
            'tipo' => ['required', 'string', Rule::in([
                AiDraft::TIPO_PARECER_OS,
                AiDraft::TIPO_RESUMO_MONITORAMENTO,
            ])],
            'origem_id' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tipo.required' => 'Informe o tipo de rascunho a gerar.',
            'tipo.in' => 'Tipo de rascunho não reconhecido.',
            'origem_id.required' => 'Informe o registro de origem do rascunho.',
        ];
    }
}
