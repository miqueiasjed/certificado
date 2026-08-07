<?php

namespace App\Http\Requests;

use App\Models\ServiceInvoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListServiceInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'situacao' => ['nullable', Rule::in(ServiceInvoice::SITUACOES)],
            'de' => ['nullable', 'date_format:Y-m-d'],
            'ate' => array_values(array_filter([
                'nullable',
                'date_format:Y-m-d',
                $this->filled('de') ? 'after_or_equal:de' : null,
            ])),
            'client_id' => ['nullable', 'integer', 'min:1'],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
