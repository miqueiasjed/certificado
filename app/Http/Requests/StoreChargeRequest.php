<?php

namespace App\Http\Requests;

use App\Models\Charge;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validação de entrada para `POST /cobrancas` (Plano 19, Task 19.6): emitir
 * uma cobrança (boleto ou Pix) individual ou em lote, a partir de uma ou mais
 * parcelas a receber.
 *
 * Duas formas de corpo, mutuamente exclusivas:
 *
 * - `{ "tipo": "boleto", "receivable_installment_id": 42 }` - emissão
 *   individual, atendida por `App\Services\ChargeService::emitir()`.
 * - `{ "tipo": "boleto", "receivable_installment_ids": [42, 43] }` - lote,
 *   atendido por `App\Services\ChargeService::emitirEmLote()`.
 *
 * Nenhum dos dois ids é resolvido aqui: `App\Http\Controllers\ChargeController`
 * consulta `ReceivableInstallment` pelo escopo global de empresa
 * (`BelongsToCompany`) antes de chamar o Service, e id de outra empresa
 * simplesmente não é encontrado. `ChargeService::emitir()` faz o resto da
 * validação (dados do cliente, cobrança ativa), porque depende do estado do
 * banco e não cabe em regra de FormRequest.
 */
class StoreChargeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tipo' => ['required', 'string', 'in:'.implode(',', Charge::TIPOS)],
            'receivable_installment_id' => ['required_without:receivable_installment_ids', 'nullable', 'integer', 'min:1'],
            'receivable_installment_ids' => ['required_without:receivable_installment_id', 'nullable', 'array', 'min:1'],
            'receivable_installment_ids.*' => ['integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tipo.required' => 'Informe o tipo de cobrança: boleto ou pix.',
            'tipo.in' => 'Tipo de cobrança inválido. Use boleto ou pix.',
            'receivable_installment_id.required_without' => 'Informe a parcela a cobrar, individual ou em lote.',
            'receivable_installment_ids.required_without' => 'Informe a parcela a cobrar, individual ou em lote.',
            'receivable_installment_ids.array' => 'A lista de parcelas em lote é inválida.',
            'receivable_installment_ids.min' => 'Informe ao menos uma parcela para emitir em lote.',
        ];
    }

    /**
     * `true` quando o corpo pediu emissão em lote (`receivable_installment_ids`).
     */
    public function emLote(): bool
    {
        return $this->filled('receivable_installment_ids');
    }

    /**
     * @return array<int, int>
     */
    public function idsDasParcelas(): array
    {
        if ($this->emLote()) {
            return array_map('intval', (array) $this->input('receivable_installment_ids'));
        }

        return [(int) $this->input('receivable_installment_id')];
    }
}
