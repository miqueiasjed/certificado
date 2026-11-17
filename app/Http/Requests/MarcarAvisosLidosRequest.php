<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MarcarAvisosLidosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:50'],
            'ids.*' => ['required', 'string', 'max:120', 'regex:/^\d{4}-\d{2}-\d{2}-[a-z0-9-]+$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'ids.required' => 'Informe ao menos um aviso para marcar como lido.',
            'ids.array' => 'A lista de avisos está em formato inválido.',
            'ids.min' => 'Informe ao menos um aviso para marcar como lido.',
            'ids.max' => 'Não é possível marcar mais de 50 avisos de uma vez.',
            'ids.*.required' => 'O identificador do aviso é obrigatório.',
            'ids.*.string' => 'O identificador do aviso é inválido.',
            'ids.*.max' => 'O identificador do aviso é longo demais.',
            'ids.*.regex' => 'O identificador do aviso é inválido.',
        ];
    }
}
