<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CancelServiceInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['motivo' => ['required', 'string', 'min:15', 'max:2000']];
    }
}
