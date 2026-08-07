<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubstituteServiceInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'motivo' => ['required', 'string', 'min:15', 'max:2000'],
            'codigo_motivo' => ['nullable', 'string', 'max:20'],
            'descricao_servico' => ['nullable', 'string', 'max:4000'],
            'valor_servico' => ['nullable', 'numeric', 'min:0.01'],
            'competencia' => ['nullable', 'date_format:Y-m-d'],
            'address_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
