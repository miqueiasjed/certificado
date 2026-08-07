<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validação do posicionamento em lote dos dispositivos sobre uma planta
 * (Plano 21, Task 21.4).
 *
 * `x` e `y` são fração de 0 a 1 da largura/altura da imagem (mesma definição
 * de `DevicePosition`), e entram aqui como estritamente maiores que 0 e
 * menores que 1: um dispositivo em cima da borda exata (0 ou 1) não tem onde
 * o marcador desenhar a metade que sairia da imagem.
 *
 * O vínculo de cada `device_id` com o endereço da planta não é validado
 * aqui, de propósito: esta checagem depende da instância de `FloorPlan` que
 * só o controller resolve (route model binding), e por isso é regra de
 * negócio de `FloorPlanService::salvarPosicoes()`, não validação de input -
 * mesmo critério do restante do projeto para "ID vindo do corpo é escopado
 * antes de usar".
 *
 * `authorize()` confere `planta-gerenciar` diretamente, pelo mesmo motivo de
 * `StoreFloorPlanRequest`: ainda não existe rota/controller de plantas neste
 * plano (task futura), e a regra de acesso precisa valer assim que o
 * endpoint nascer.
 */
class UpdateDevicePositionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('planta-gerenciar') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'posicoes' => ['required', 'array', 'min:1'],
            'posicoes.*.device_id' => ['required', 'integer'],
            'posicoes.*.x' => ['required', 'numeric', 'gt:0', 'lt:1'],
            'posicoes.*.y' => ['required', 'numeric', 'gt:0', 'lt:1'],
            'posicoes.*.rotulo_visivel' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'posicoes.required' => 'Informe ao menos uma posição para salvar.',
            'posicoes.min' => 'Informe ao menos uma posição para salvar.',
            'posicoes.*.device_id.required' => 'Cada posição precisa indicar o dispositivo.',
            'posicoes.*.device_id.integer' => 'O dispositivo informado é inválido.',
            'posicoes.*.x.required' => 'Informe a posição horizontal do dispositivo.',
            'posicoes.*.x.numeric' => 'A posição horizontal deve ser um número.',
            'posicoes.*.x.gt' => 'A posição horizontal deve ser maior que 0.',
            'posicoes.*.x.lt' => 'A posição horizontal deve ser menor que 1.',
            'posicoes.*.y.required' => 'Informe a posição vertical do dispositivo.',
            'posicoes.*.y.numeric' => 'A posição vertical deve ser um número.',
            'posicoes.*.y.gt' => 'A posição vertical deve ser maior que 0.',
            'posicoes.*.y.lt' => 'A posição vertical deve ser menor que 1.',
        ];
    }
}
