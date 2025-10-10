<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CertificateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_id' => 'required|exists:clients,id',
            'address_id' => 'required|exists:addresses,id',
            'product_id' => 'nullable|exists:products,id',
            'service_id' => 'nullable|exists:services,id',
            'products' => 'nullable|array',
            'products.*.product_id' => 'nullable|exists:products,id',
            'services' => 'nullable|array',
            'services.*.service_id' => 'nullable|exists:services,id',
            'execution_date' => 'required|date',
            'warranty' => 'nullable|date',
            'notes' => 'nullable|string|max:1000',
            'procedure_used' => 'nullable|string|max:2000',
        ];
    }

    public function messages(): array
    {
        return [
            'client_id.required' => 'O cliente é obrigatório.',
            'client_id.exists' => 'O cliente selecionado não existe.',
            'work_order_id.required' => 'A ordem de serviço é obrigatória. Um certificado deve sempre estar vinculado a uma ordem de serviço.',
            'work_order_id.exists' => 'A ordem de serviço selecionada não existe.',
            'product_id.exists' => 'O produto selecionado não existe.',
            'service_id.exists' => 'O serviço selecionado não existe.',
            'products.array' => 'Os produtos devem ser uma lista.',
            'products.*.product_id.exists' => 'Um dos produtos selecionados não existe.',
            'services.array' => 'Os serviços devem ser uma lista.',
            'services.*.service_id.exists' => 'Um dos serviços selecionados não existe.',
            'execution_date.required' => 'A data da execução é obrigatória.',
            'execution_date.date' => 'A data da execução deve ser uma data válida.',
            'warranty.date' => 'A garantia deve ser uma data válida.',
            'notes.max' => 'As observações não podem ter mais de 1000 caracteres.',
            'procedure_used.required' => 'O procedimento utilizado é obrigatório.',
            'procedure_used.max' => 'O procedimento utilizado não pode ter mais de 2000 caracteres.',
        ];
    }
}
