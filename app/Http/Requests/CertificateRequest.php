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
        $isFromWorkOrder = $this->has('work_order_id') && $this->work_order_id;

        return [
            'client_id' => 'required|exists:clients,id',
            'address_id' => 'nullable|exists:addresses,id', // Opcional - pode vir da OS
            'work_order_id' => 'nullable|exists:work_orders,id', // Opcional - certificados avulsos
            'service_id' => $isFromWorkOrder ? 'nullable|exists:services,id' : 'required|exists:services,id',
            'products' => 'nullable|array',
            'products.*.product_id' => 'required|exists:products,id',
            'products.*.quantity' => 'nullable|numeric|min:0',
            'products.*.unit' => 'nullable|string',
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
            'address_id.exists' => 'O endereço selecionado não existe.',
            'work_order_id.exists' => 'A ordem de serviço selecionada não existe.',
            'service_id.required' => 'O serviço é obrigatório.',
            'service_id.exists' => 'O serviço selecionado não existe.',
            'products.array' => 'Os produtos devem ser uma lista.',
            'products.*.product_id.exists' => 'Um dos produtos selecionados não existe.',
            'execution_date.required' => 'A data da execução é obrigatória.',
            'execution_date.date' => 'A data da execução deve ser uma data válida.',
            'warranty.date' => 'A garantia deve ser uma data válida.',
            'notes.max' => 'As observações não podem ter mais de 1000 caracteres.',
            'procedure_used.required' => 'O procedimento utilizado é obrigatório.',
            'procedure_used.max' => 'O procedimento utilizado não pode ter mais de 2000 caracteres.',
        ];
    }
}
