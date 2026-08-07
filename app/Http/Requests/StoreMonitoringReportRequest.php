<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validação da geração/congelamento do relatório de monitoramento (Plano 21,
 * Task 21.5, `POST /monitoramento/relatorios`).
 *
 * Diferente de `MonitoringReportFilterRequest` (visão ao vivo, tudo
 * opcional), aqui `client_id`, `de` e `ate` são obrigatórios: gerar um
 * relatório sem período ou sem cliente não tem sentido, e regerar sempre cria
 * um registro novo (relatório salvo é imutável, ver `MonitoringReportController`).
 *
 * O vínculo entre `address_id` e `client_id` (endereço precisa pertencer ao
 * cliente informado) não é checado aqui, de propósito: depende de carregar os
 * dois models, e por isso é regra de negócio de
 * `ConsolidadorDePeriodo::confirmarEnderecoDoCliente()`, não validação de
 * input - mesmo critério já usado em `UpdateDevicePositionsRequest` para o
 * vínculo dispositivo/endereço.
 *
 * `authorize()` confere `monitoramento-gerar` diretamente, além do
 * `permission:monitoramento-gerar` já empilhado na rota.
 */
class StoreMonitoringReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('monitoramento-gerar') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'address_id' => ['nullable', 'integer', 'exists:addresses,id'],
            'de' => ['required', 'date'],
            'ate' => ['required', 'date', 'after_or_equal:de'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'client_id.required' => 'Selecione o cliente do relatório.',
            'client_id.exists' => 'Cliente não encontrado.',
            'address_id.exists' => 'Endereço não encontrado.',
            'de.required' => 'Informe a data inicial do período.',
            'de.date' => 'Informe uma data inicial válida.',
            'ate.required' => 'Informe a data final do período.',
            'ate.date' => 'Informe uma data final válida.',
            'ate.after_or_equal' => 'A data final não pode ser anterior à data inicial.',
        ];
    }
}
