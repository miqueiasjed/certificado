<?php

namespace App\Http\Requests;

use App\Models\Technician;

/**
 * Atribuição de técnico a uma ordem de serviço pelo calendário.
 *
 * O id do técnico vem do corpo da requisição, e não da rota, então o escopo por
 * empresa do route model binding não vale aqui: a existência é conferida pelo
 * próprio model, cujo escopo global filtra por empresa. Técnico de outra
 * empresa responde "não existe", sem confirmar que existe em outro lugar.
 *
 * `technician_id` aceita nulo, para desatribuir a visita direto do calendário,
 * mas precisa vir na requisição (`present`): quem chama diz explicitamente o
 * que quer, e uma requisição vazia nunca limpa o técnico por acidente.
 */
class AtribuicaoTecnicoRequest extends OrdemDaAgendaRequest
{
    public function rules(): array
    {
        return [
            'technician_id' => [
                'present',
                'nullable',
                'integer',
                function (string $atributo, mixed $valor, callable $falhar): void {
                    $tecnico = Technician::query()
                        ->whereKey($valor)
                        ->first(['id', 'is_active']);

                    if ($tecnico === null) {
                        $falhar('O técnico selecionado não existe.');

                        return;
                    }

                    if (! $tecnico->is_active) {
                        $falhar('O técnico selecionado está inativo e não recebe novas visitas.');
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'technician_id.present' => 'Envie technician_id, com o id do técnico ou nulo para desatribuir.',
            'technician_id.integer' => 'O técnico selecionado é inválido.',
        ];
    }
}
