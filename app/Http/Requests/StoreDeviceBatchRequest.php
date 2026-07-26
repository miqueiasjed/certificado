<?php

namespace App\Http\Requests;

use App\Models\BaitType;
use App\Services\DeviceBatchService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validação do cadastro em lote de dispositivos de um endereço.
 *
 * O que este FormRequest garante antes de `DeviceBatchService::criarLote` ser
 * chamado:
 *
 * - a quantidade fica dentro do teto do Service. O limite é lido da constante,
 *   e não repetido como número solto aqui, para o dia em que ele mudar não
 *   deixar validação e regra discordando;
 * - o número inicial é inteiro maior que zero, porque a faixa numera posições no
 *   croqui do imóvel e não existe posição zero;
 * - o tipo de isca, quando informado, pertence à empresa de quem está pedindo.
 *
 * A conferência do tipo de isca é feita por consulta ao model, e não por
 * `exists:bait_types,id`: a regra crua varre a tabela inteira, sem escopo, e
 * aceitaria o id de um tipo de isca de outro tenant. Como o id vem do corpo da
 * requisição, e não da rota, nenhum route-model binding o filtra antes. É a
 * mesma escolha de `StoreDeviceReplacementRequest`, e aqui pesa mais: um id
 * errado se propagaria para as dezenas de linhas do lote de uma vez.
 *
 * `authorize()` devolve true pelo mesmo motivo dos outros FormRequests deste
 * módulo: a rota é inteiramente protegida por permissão do Spatie
 * (`dispositivo-criar`, ver routes/web.php), o `Address` chega por Model Binding
 * e o escopo global por empresa já barra o endereço de outro tenant com 404
 * antes de qualquer código daqui rodar.
 */
class StoreDeviceBatchRequest extends FormRequest
{
    /**
     * Tamanho máximo do prefixo do rótulo. Vinte caracteres cabem em "PORTA
     * ISCA EXTERNO" com folga, e o rótulo completo ainda sobra dentro dos 255 da
     * coluna `devices.label` mesmo com número de muitas casas.
     */
    public const TAMANHO_MAXIMO_DO_PREFIXO = 20;

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
            'quantidade' => ['required', 'integer', 'between:1,'.DeviceBatchService::QUANTIDADE_MAXIMA],
            'numero_inicial' => ['required', 'integer', 'min:1'],
            'prefixo' => ['required', 'string', 'max:'.self::TAMANHO_MAXIMO_DO_PREFIXO],

            // Escopado pela empresa via model, e não por `exists` cru. Ver o
            // cabeçalho da classe.
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
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quantidade.required' => 'Informe quantos dispositivos o lote deve criar.',
            'quantidade.integer' => 'A quantidade deve ser um número inteiro.',
            'quantidade.between' => 'A quantidade deve ficar entre 1 e '.DeviceBatchService::QUANTIDADE_MAXIMA
                .' dispositivos por lote. Para criar mais, envie o lote em partes.',
            'numero_inicial.required' => 'Informe o número inicial da faixa.',
            'numero_inicial.integer' => 'O número inicial deve ser um número inteiro.',
            'numero_inicial.min' => 'O número inicial deve ser maior que zero.',
            'prefixo.required' => 'Informe o prefixo do rótulo, por exemplo "PCE".',
            'prefixo.string' => 'O prefixo do rótulo deve ser um texto.',
            'prefixo.max' => 'O prefixo do rótulo pode ter no máximo '
                .self::TAMANHO_MAXIMO_DO_PREFIXO.' caracteres.',
            // `bait_type_id` não aparece aqui: a mensagem sai da própria regra de
            // closure, que é onde a checagem por empresa acontece.
            'default_location_note.string' => 'A observação de localização deve ser um texto.',
            'default_location_note.max' => 'A observação de localização pode ter no máximo 1000 caracteres.',
        ];
    }
}
