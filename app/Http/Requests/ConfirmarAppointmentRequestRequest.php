<?php

namespace App\Http\Requests;

use App\Models\Address;
use App\Models\AppointmentRequest;
use App\Models\Client;
use App\Models\Technician;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validação da confirmação de um pedido de horário pela empresa (Plano 16,
 * Task 16.4).
 *
 * `authorize()` sempre `true`: a rota (`POST /solicitacoes-de-horario/{pedido}/
 * confirmar`) já está inteiramente protegida por `permission:agendamento-responder`,
 * mesmo critério de `ResponderSolicitacaoRequest`.
 *
 * `technician_id`, `client_id` e `address_id` vêm do corpo, não da rota: a
 * existência de cada um é conferida pelo próprio model, cujo escopo global
 * (`BelongsToCompany`) já filtra por empresa - id de outra empresa responde
 * "não existe", nunca revela que existe em outro tenant. Mesmo critério de
 * `AtribuicaoTecnicoRequest`. `AppointmentRequestService::confirmar()` ainda
 * reconsulta cada um dentro do próprio Service, como segunda barreira.
 */
class ConfirmarAppointmentRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'technician_id' => [
                'required',
                'integer',
                function (string $atributo, mixed $valor, callable $falhar): void {
                    $tecnico = Technician::query()->whereKey($valor)->first(['id', 'is_active']);

                    if ($tecnico === null) {
                        $falhar('O técnico selecionado não existe.');

                        return;
                    }

                    if (! $tecnico->is_active) {
                        $falhar('O técnico selecionado está inativo e não recebe novas visitas.');
                    }
                },
            ],

            'data' => ['nullable', 'date_format:Y-m-d'],
            'periodo' => ['nullable', Rule::in(AppointmentRequest::PERIODOS)],

            'client_id' => [
                'nullable',
                'integer',
                function (string $atributo, mixed $valor, callable $falhar): void {
                    if (Client::query()->whereKey($valor)->doesntExist()) {
                        $falhar('O cliente selecionado não existe.');
                    }
                },
            ],
            'cliente' => ['nullable', 'array'],
            'cliente.name' => ['required_with:cliente', 'string', 'max:255'],
            'cliente.email' => ['nullable', 'email', 'max:255'],
            'cliente.phone' => ['nullable', 'string', 'max:30'],
            'cliente.cnpj' => ['nullable', 'string', 'max:30'],

            'address_id' => [
                'nullable',
                'integer',
                function (string $atributo, mixed $valor, callable $falhar): void {
                    if (Address::query()->whereKey($valor)->doesntExist()) {
                        $falhar('O endereço selecionado não existe.');
                    }
                },
            ],
            'endereco' => ['nullable', 'array'],
            'endereco.street' => ['required_with:endereco', 'string', 'max:255'],
            'endereco.number' => ['nullable', 'string', 'max:30'],
            'endereco.district' => ['nullable', 'string', 'max:255'],
            'endereco.city' => ['nullable', 'string', 'max:255'],
            'endereco.state' => ['nullable', 'string', 'max:2'],
            'endereco.zip' => ['nullable', 'string', 'max:20'],

            'observacoes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'technician_id.required' => 'Selecione o técnico responsável pelo atendimento.',
            'technician_id.integer' => 'Técnico inválido.',
            'data.date_format' => 'Data inválida.',
            'periodo.in' => 'Escolha entre manhã e tarde.',
            'cliente.name.required_with' => 'Informe o nome do cliente.',
            'endereco.street.required_with' => 'Informe a rua do endereço.',
        ];
    }
}
