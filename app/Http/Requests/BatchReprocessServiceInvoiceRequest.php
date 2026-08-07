<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BatchReprocessServiceInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nota_ids' => ['nullable', 'array', 'min:1', 'required_without:motivo', 'prohibits:motivo'],
            'nota_ids.*' => ['integer', 'min:1', 'distinct'],
            'motivo' => ['nullable', 'string', 'max:4000', 'required_without:nota_ids', 'prohibits:nota_ids'],
        ];
    }
}
