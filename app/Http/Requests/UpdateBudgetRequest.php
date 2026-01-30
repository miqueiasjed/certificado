<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBudgetRequest extends FormRequest
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
            'client_id' => 'nullable|exists:clients,id',
            'address_id' => 'nullable|exists:addresses,id',
            'prospect_name' => 'nullable|required_without:client_id|string|max:255',
            'prospect_phone' => 'nullable|string|max:20',
            'prospect_address' => 'nullable|string|max:255',
            'date' => 'required|date',
            'priority' => 'required|in:urgent,normal',
            'channel' => 'nullable|string',
            'target_pests' => 'nullable|array',
            'environment_type' => 'nullable|string',
            'areas_to_treat' => 'nullable|array',
            'items' => 'required|array',
            'products' => 'nullable|array',
            'labor_technicians' => 'nullable|integer',
            'labor_hours' => 'nullable|string',
            'discount' => 'nullable|numeric',
            'payment_method' => 'nullable|string',
            'payment_conditions' => 'nullable|string',
            'execution_deadline' => 'nullable|string',
            'validity_date' => 'nullable|date',
            'observations' => 'nullable|string',
            'status' => 'required|in:draft,sent,negotiating,approved,refused,expired,converted',
            'loss_reason' => 'nullable|string',
        ];
    }
}
