<?php

namespace App\Http\Requests;

use App\Models\VehicleDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Documento do veículo, com validade e arquivo (Plano 27, Task 27.4).
 *
 * `validade` é obrigatória: documento de veículo sem validade não tem o que
 * alertar, e é justamente o alerta que justifica guardar o documento aqui em vez
 * de numa pasta compartilhada.
 *
 * O arquivo é opcional (o número e a validade já sustentam o alerta) e limitado
 * a PDF e imagem, que é o que sai de um portal de despachante ou da foto do
 * documento.
 */
class VehicleDocumentRequest extends FormRequest
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
            'tipo' => [$this->isMethod('POST') ? 'required' : 'sometimes', Rule::in(VehicleDocument::TIPOS)],
            'numero' => 'nullable|string|max:255',
            'validade' => ($this->isMethod('POST') ? 'required' : 'sometimes|required').'|date',
            'arquivo' => 'nullable|file|mimes:pdf,jpg,jpeg,png,webp|max:10240',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tipo.required' => 'Informe o tipo do documento.',
            'tipo.in' => 'Tipo de documento inválido.',
            'validade.required' => 'Informe a validade do documento: sem ela não há o que alertar.',
            'arquivo.mimes' => 'O arquivo precisa ser PDF ou imagem (jpg, png ou webp).',
            'arquivo.max' => 'O arquivo não pode passar de 10 MB.',
        ];
    }
}
