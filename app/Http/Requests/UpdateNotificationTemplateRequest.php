<?php

namespace App\Http\Requests;

use App\Services\Notification\RenderizadorDeTemplate;
use App\Support\EventosDeNotificacao;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validação de `PUT /notificacoes/templates/{evento}/{canal}`.
 *
 * A regra que mais importa aqui: variável desconhecida no assunto ou no corpo
 * é recusada agora, contra `EventosDeNotificacao::variaveis()` do próprio
 * evento, e não na hora do envio. Erro de digitação que só aparece semanas
 * depois, no e-mail do cliente, é o pior lugar para descobrir.
 *
 * `evento` e `canal` vêm da rota, não do corpo, e o controller devolve 404
 * quando o par não existe no catálogo (`NotificationTemplateController`). O
 * `after()` abaixo roda antes desse 404, então ele mesmo verifica se o evento
 * existe antes de perguntar a `EventosDeNotificacao::variaveis()`, para não
 * lançar `InvalidArgumentException` de dentro da validação.
 *
 * `authorize()` devolve true pelo mesmo motivo de outras rotas administrativas
 * do projeto (ver `ContractVisitJustificationRequest`): a rota inteira já está
 * atrás de `permission:notificacao-gerenciar` em `routes/web.php`.
 */
class UpdateNotificationTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'assunto' => ['nullable', 'string', 'max:255'],
            'corpo' => ['required', 'string'],
            'ativo' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $evento = (string) $this->route('evento');

            if (! EventosDeNotificacao::existe($evento)) {
                return;
            }

            $validas = EventosDeNotificacao::variaveis($evento);

            $usadas = array_unique(array_merge(
                RenderizadorDeTemplate::variaveisUsadas((string) $this->input('corpo', '')),
                RenderizadorDeTemplate::variaveisUsadas((string) $this->input('assunto', ''))
            ));

            $desconhecidas = array_values(array_diff($usadas, $validas));

            if ($desconhecidas !== []) {
                $validator->errors()->add(
                    'corpo',
                    'Variável desconhecida no template: {{'.implode('}}, {{', $desconhecidas).'}}. '
                    .'Variáveis válidas para este evento: '.implode(', ', $validas).'.'
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'corpo.required' => 'O corpo da mensagem é obrigatório.',
            'assunto.max' => 'O assunto não pode ter mais de 255 caracteres.',
        ];
    }
}
