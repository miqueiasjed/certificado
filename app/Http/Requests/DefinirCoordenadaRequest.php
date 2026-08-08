<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `PUT /enderecos/{address}/coordenada` (Plano 22, Task 22.5): correção
 * manual de coordenada. Sempre grava `origem_coordenada = manual`
 * (`GeocodificacaoService::definirManualmente()`, Task 22.2) - a partir daí a
 * geocodificação automática em lote nunca mais sobrescreve este endereço sem
 * `--forcar` explícito.
 */
class DefinirCoordenadaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('endereco-geo') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'latitude.required' => 'Informe a latitude.',
            'latitude.numeric' => 'A latitude precisa ser um número.',
            'latitude.between' => 'A latitude precisa estar entre -90 e 90.',
            'longitude.required' => 'Informe a longitude.',
            'longitude.numeric' => 'A longitude precisa ser um número.',
            'longitude.between' => 'A longitude precisa estar entre -180 e 180.',
        ];
    }
}
