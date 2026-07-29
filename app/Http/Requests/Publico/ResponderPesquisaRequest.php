<?php

namespace App\Http\Requests\Publico;

use App\Services\SatisfactionSurveyService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validação da resposta da pesquisa de satisfação (Plano 16, Task 16.5),
 * enviada da página pública, sem login.
 *
 * `authorize()` devolve `true` porque não há identidade a autorizar: a rota é
 * pública. O que ocupa o lugar do controle de acesso aqui é o token de 64
 * caracteres da URL, resolvido em `SatisfactionSurveyService::resolverToken()`
 * pelo controller antes de qualquer gravação. Token com formato errado,
 * inexistente, vencido ou já respondido não grava nada.
 *
 * Sem campo armadilha e sem tempo mínimo de preenchimento, ao contrário de
 * `StoreAppointmentRequestRequest`: lá o formulário é aberto a qualquer pessoa da
 * internet, e a proteção precisa distinguir humano de robô. Aqui é preciso ter o
 * token para chegar, e quem tem o token é quem recebeu o convite. O que protege
 * esta rota é o limite de taxa (`throttle:pesquisa-publica`) contra marretada.
 *
 * Só `nota` e `comentario` são aceitos. `token`, `pendencia_de_contato`,
 * `respondida_em` e qualquer id não vêm do corpo em nenhuma hipótese: o token
 * vem da URL e o resto é decidido no Service.
 */
class ResponderPesquisaRequest extends FormRequest
{
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
            'nota' => [
                'required',
                'integer',
                'between:'.SatisfactionSurveyService::NOTA_MINIMA.','.SatisfactionSurveyService::NOTA_MAXIMA,
            ],

            'comentario' => ['nullable', 'string', 'max:'.SatisfactionSurveyService::TAMANHO_MAXIMO_DO_COMENTARIO],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nota.required' => 'Escolha uma nota de 1 a 5.',
            'nota.integer' => 'Escolha uma nota de 1 a 5.',
            'nota.between' => 'Escolha uma nota de 1 a 5.',
            'comentario.max' => 'O comentário pode ter no máximo '
                .SatisfactionSurveyService::TAMANHO_MAXIMO_DO_COMENTARIO.' caracteres.',
        ];
    }
}
