<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreServiceInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'work_order_id' => ['nullable', 'integer', 'min:1', 'required_without:receivable_id', 'prohibits:receivable_id'],
            'receivable_id' => ['nullable', 'integer', 'min:1', 'required_without:work_order_id', 'prohibits:work_order_id'],
        ];
    }

    public function messages(): array
    {
        return [
            'work_order_id.required_without' => 'Informe a ordem de serviço ou o título a receber.',
            'receivable_id.required_without' => 'Informe a ordem de serviço ou o título a receber.',
            'work_order_id.prohibits' => 'Informe somente uma origem para a nota fiscal.',
            'receivable_id.prohibits' => 'Informe somente uma origem para a nota fiscal.',
        ];
    }
}
