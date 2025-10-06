<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PaymentDetailRequest extends FormRequest
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
        $rules = [
            // Campos financeiros básicos agora são opcionais (para permitir apenas adicionar pagamento)
            'total_cost' => 'nullable|numeric|min:0|max:999999.99',
            'discount_amount' => 'nullable|numeric|min:0|max:999999.99',
            'final_amount' => 'nullable|numeric|min:0|max:999999.99',
            'payment_due_date' => 'nullable|date',
            // Campos específicos do pagamento
            'payment_date' => 'nullable|date',
            'payment_method' => 'nullable|in:pix,credit_card,debit_card,boleto,cash,bank_transfer',
            'amount_paid' => 'required|numeric|min:0',
            'payment_notes' => 'nullable|string|max:1000',
            'is_partial_payment' => 'boolean',
            'payment_status' => 'nullable|in:pending,paid,partial,overdue',
        ];

        // Para criação (store), work_order_id é obrigatório
        if ($this->isMethod('post')) {
            $rules['work_order_id'] = 'required|exists:work_orders,id';
        }
        // Para atualização (put/patch), work_order_id é opcional
        else {
            $rules['work_order_id'] = 'nullable|exists:work_orders,id';
        }

        return $rules;
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'work_order_id.required' => 'A ordem de serviço é obrigatória.',
            'work_order_id.exists' => 'A ordem de serviço selecionada não existe.',
            'total_cost.required' => 'O custo total é obrigatório.',
            'total_cost.numeric' => 'O custo total deve ser um número.',
            'total_cost.min' => 'O custo total deve ser maior ou igual a zero.',
            'total_cost.max' => 'O custo total não pode ser maior que 999.999,99.',
            'discount_amount.numeric' => 'O desconto deve ser um número.',
            'discount_amount.min' => 'O desconto deve ser maior ou igual a zero.',
            'discount_amount.max' => 'O desconto não pode ser maior que 999.999,99.',
            'final_amount.numeric' => 'O valor final deve ser um número.',
            'final_amount.min' => 'O valor final deve ser maior ou igual a zero.',
            'final_amount.max' => 'O valor final não pode ser maior que 999.999,99.',
            'payment_due_date.date' => 'A data de vencimento deve ser uma data válida.',
            'payment_due_date.after_or_equal' => 'A data de vencimento não pode ser anterior a hoje.',
            'payment_date.date' => 'A data do pagamento deve ser uma data válida.',
            'payment_date.before_or_equal' => 'A data do pagamento não pode ser futura.',
            'payment_method.in' => 'A forma de pagamento selecionada é inválida.',
            'amount_paid.required' => 'O valor pago é obrigatório.',
            'amount_paid.numeric' => 'O valor pago deve ser um número.',
            'amount_paid.min' => 'O valor pago deve ser maior ou igual a zero.',
            'payment_notes.max' => 'As observações não podem ter mais de 1000 caracteres.',
            'is_partial_payment.boolean' => 'O campo de pagamento parcial deve ser verdadeiro ou falso.',
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
            'work_order_id' => 'ordem de serviço',
            'total_cost' => 'custo total',
            'discount_amount' => 'desconto',
            'final_amount' => 'valor final',
            'payment_due_date' => 'data de vencimento',
            'payment_date' => 'data do pagamento',
            'payment_method' => 'forma de pagamento',
            'amount_paid' => 'valor pago',
            'payment_notes' => 'observações',
            'is_partial_payment' => 'pagamento parcial',
        ];
    }
}
