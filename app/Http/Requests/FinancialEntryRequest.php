<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FinancialEntryRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'source' => 'required|in:work_order,manual',
            'amount' => 'required|numeric|min:0.01',
            'description' => 'required|string|max:255',
            'entry_date' => 'required|date',
            'payment_method' => 'nullable|in:pix,credit_card,debit_card,boleto,cash,bank_transfer',
            'reference_number' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:1000',
            'status' => 'nullable|in:confirmed,pending,cancelled',
            'work_order_id' => 'nullable|exists:work_orders,id',
            'payment_detail_id' => 'nullable|exists:payment_details,id',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'source.required' => 'A origem da entrada é obrigatória.',
            'source.in' => 'A origem da entrada deve ser "Ordem de Serviço" ou "Manual".',
            'amount.required' => 'O valor da entrada é obrigatório.',
            'amount.numeric' => 'O valor deve ser um número.',
            'amount.min' => 'O valor deve ser maior que zero.',
            'description.required' => 'A descrição é obrigatória.',
            'description.max' => 'A descrição não pode ter mais de 255 caracteres.',
            'entry_date.required' => 'A data da entrada é obrigatória.',
            'entry_date.date' => 'A data deve ser válida.',
            'payment_method.in' => 'A forma de pagamento selecionada é inválida.',
            'reference_number.max' => 'O número de referência não pode ter mais de 100 caracteres.',
            'notes.max' => 'As observações não podem ter mais de 1000 caracteres.',
            'status.in' => 'O status deve ser "Confirmado", "Pendente" ou "Cancelado".',
            'work_order_id.exists' => 'A ordem de serviço selecionada não existe.',
            'payment_detail_id.exists' => 'O pagamento selecionado não existe.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'source' => 'origem',
            'amount' => 'valor',
            'description' => 'descrição',
            'entry_date' => 'data da entrada',
            'payment_method' => 'forma de pagamento',
            'reference_number' => 'número de referência',
            'notes' => 'observações',
            'status' => 'status',
            'work_order_id' => 'ordem de serviço',
            'payment_detail_id' => 'pagamento',
        ];
    }
}
