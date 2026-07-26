<?php

namespace App\Http\Requests;

use App\Models\BaitType;
use App\Support\BusinessDate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validação da substituição de um dispositivo por outro no mesmo ponto.
 *
 * O que este FormRequest garante antes de `DeviceReplacementService::substituir`
 * ser chamado:
 *
 * - o motivo é um dos cinco valores do enum da coluna `device_replacements.motivo`;
 * - `outro` vem sempre acompanhado da observação, porque um motivo genérico sem
 *   explicação não responde nada à fiscalização meses depois;
 * - o dia da troca não é futuro. Registrar uma substituição que ainda não
 *   aconteceu criaria um dispositivo ativo no ponto antes de alguém ter ido ao
 *   local, e a comparação é dia contra dia no fuso do negócio: uma troca feita
 *   às 22h de Brasília é de hoje, e comparar com o instante em UTC a recusaria
 *   como se fosse de amanhã.
 *
 * `address_id` não é aceito de propósito: substituição acontece no mesmo ponto,
 * e o Service herda o endereço do dispositivo anterior. Ponto que muda de
 * endereço é instalação nova, não troca.
 *
 * `authorize()` devolve true pelo mesmo motivo de `ContractEncerrarRequest`: a
 * rota é inteiramente protegida por permissão do Spatie (`dispositivo-editar`,
 * ver routes/web.php), o `Device` chega por Model Binding e o escopo global por
 * empresa já barra o dispositivo de outro tenant antes de chegar aqui.
 */
class StoreDeviceReplacementRequest extends FormRequest
{
    /**
     * Motivos aceitos, espelhando o enum de `device_replacements.motivo`.
     *
     * @var array<int, string>
     */
    public const MOTIVOS = ['danificado', 'perdido', 'desgaste', 'troca_de_tipo', 'outro'];

    /**
     * Motivo que só é aceito com observação preenchida.
     */
    public const MOTIVO_QUE_EXIGE_OBSERVACAO = 'outro';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Sem dia informado, a troca é de hoje no fuso do negócio.
     *
     * O padrão é resolvido aqui, e não no frontend, porque o "hoje" do
     * navegador do técnico não é necessariamente o dia de Brasília, e o do
     * servidor é UTC.
     */
    protected function prepareForValidation(): void
    {
        if (blank($this->input('substituido_em'))) {
            $this->merge([
                'substituido_em' => BusinessDate::hoje()->toDateString(),
            ]);
        }
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'motivo' => ['required', Rule::in(self::MOTIVOS)],

            // Campo de dia: validado como data e comparado por dia no fuso do
            // negócio em `withValidator`, sem `before_or_equal:today`, que
            // usaria o "hoje" do servidor em UTC.
            'substituido_em' => ['required', 'date'],

            'observacao' => [
                'nullable',
                'required_if:motivo,'.self::MOTIVO_QUE_EXIGE_OBSERVACAO,
                'string',
                'max:1000',
            ],

            // Campos do dispositivo novo. Todos opcionais: sem eles, o Service
            // herda o valor do dispositivo anterior. `sometimes|required` nos
            // obrigatórios impede que a tela mande string vazia e deixe o
            // dispositivo novo sem rótulo ou sem número.
            'label' => ['sometimes', 'required', 'string', 'max:255'],
            'number' => ['sometimes', 'required', 'string', 'max:255'],
            // A existência do tipo de isca é conferida pelo model, e não por
            // `exists:bait_types,id`: a regra crua consulta a tabela inteira e
            // aceitaria o id de um tipo de isca de outra empresa. Consultando
            // pelo model, o escopo global por empresa entra na consulta.
            'bait_type_id' => [
                'nullable',
                function (string $atributo, mixed $valor, \Closure $falhar): void {
                    if (! BaitType::query()->whereKey($valor)->exists()) {
                        $falhar('O tipo de isca selecionado não existe.');
                    }
                },
            ],
            'default_location_note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Dia da troca não pode ser futuro.
     *
     * Fica fora de `rules()` porque a comparação precisa ser dia contra dia no
     * fuso do negócio, e as regras prontas de data do Laravel comparam com o
     * fuso da aplicação, que aqui é UTC.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('substituido_em')) {
                return;
            }

            $dia = BusinessDate::paraFusoNegocio($this->input('substituido_em'));

            if ($dia === null) {
                return;
            }

            if ($dia->startOfDay()->greaterThan(BusinessDate::hoje())) {
                $validator->errors()->add(
                    'substituido_em',
                    'A data da substituição não pode ser futura: registre a troca no dia em que ela aconteceu.'
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
            'motivo.required' => 'Informe o motivo da substituição.',
            'motivo.in' => 'Motivo inválido. Use um destes: '.implode(', ', self::MOTIVOS).'.',
            'substituido_em.required' => 'Informe a data da substituição.',
            'substituido_em.date' => 'A data da substituição é inválida.',
            'observacao.required_if' => 'Com o motivo "outro", a observação é obrigatória: é ela que explica a troca depois.',
            'observacao.string' => 'A observação deve ser um texto.',
            'observacao.max' => 'A observação pode ter no máximo 1000 caracteres.',
            'label.required' => 'O nome do dispositivo novo não pode ficar em branco.',
            'label.max' => 'O nome do dispositivo novo pode ter no máximo 255 caracteres.',
            'number.required' => 'O número do dispositivo novo não pode ficar em branco.',
            'number.max' => 'O número do dispositivo novo pode ter no máximo 255 caracteres.',
            // `bait_type_id` não aparece aqui: a mensagem sai da própria regra
            // de closure, que é onde a checagem por empresa acontece.
            'default_location_note.max' => 'A observação de localização pode ter no máximo 1000 caracteres.',
        ];
    }
}
