<?php

namespace App\Http\Requests;

use App\Models\VehicleMaintenance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Registro de manutenção de veículo (Plano 27, Task 27.4).
 *
 * `proxima_em_data` e `proxima_em_km` são independentes e ambos opcionais: a
 * manutenção corretiva não tem próxima prevista, e a preventiva pode ter uma,
 * a outra ou as duas — é a Task 27.3 que decide qual dispara primeiro.
 *
 * `data` e `km` também são opcionais, porque manutenção `agendada` ainda não
 * aconteceu. A regra de quilometragem retroativa fica no Service, pelo mesmo
 * motivo do abastecimento: depende do estado do veículo.
 */
class VehicleMaintenanceRequest extends FormRequest
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
        $obrigatorio = $this->isMethod('POST') ? 'required' : 'sometimes|required';

        return [
            'tipo' => [$this->isMethod('POST') ? 'required' : 'sometimes', Rule::in(VehicleMaintenance::TIPOS)],
            'descricao' => $obrigatorio.'|string|max:255',
            'data' => 'nullable|date',
            'km' => 'nullable|integer|min:0',
            'proxima_em_data' => 'nullable|date',
            'proxima_em_km' => 'nullable|integer|min:0',
            'valor' => 'nullable|numeric|min:0',
            'oficina' => 'nullable|string|max:255',
            'situacao' => ['nullable', Rule::in(VehicleMaintenance::SITUACOES)],

            // Oferta de título a pagar (Plano 18).
            'gerar_titulo' => 'boolean',
            'supplier_id' => 'required_if:gerar_titulo,true,1|nullable|exists:suppliers,id',
            'vencimento' => 'nullable|date',
            'chart_of_account_id' => 'nullable|exists:chart_of_accounts,id',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tipo.required' => 'Informe se a manutenção é preventiva ou corretiva.',
            'tipo.in' => 'Tipo de manutenção inválido.',
            'descricao.required' => 'Descreva a manutenção.',
            'situacao.in' => 'Situação de manutenção inválida.',
            'supplier_id.required_if' => 'Para gerar o título a pagar, informe o fornecedor.',
            'supplier_id.exists' => 'O fornecedor selecionado não existe.',
        ];
    }
}
