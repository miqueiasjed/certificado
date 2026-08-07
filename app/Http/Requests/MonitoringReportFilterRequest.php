<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Filtros da visão interativa do relatório de monitoramento e da lista de
 * relatórios já gerados (Plano 21, Task 21.5, `GET /monitoramento` e
 * `GET /monitoramento/relatorios`).
 *
 * Reaproveitada pelas duas rotas: as duas leituras aceitam o mesmo conjunto
 * de filtros opcionais (cliente, endereço, período) e exigem a mesma
 * permissão (`monitoramento-ver`), então duplicar a classe só para o nome
 * combinar com a rota criaria duas regras de validação para manter em
 * sincronia sem ganho nenhum.
 *
 * Todos os campos são opcionais na validação: a primeira visita a qualquer
 * uma das duas páginas, sem nenhum filtro na querystring, precisa renderizar
 * normalmente (o usuário ainda vai escolher cliente e período na tela). É o
 * controller quem decide se já tem o suficiente (`client_id`, `de` e `ate`)
 * para chamar `ConsolidadorDePeriodo::consolidar()` na visão ao vivo - regra
 * de fluxo, não de formato de campo, por isso fica lá e não aqui.
 *
 * `authorize()` confere `monitoramento-ver` diretamente, além do
 * `permission:monitoramento-ver` já empilhado nas duas rotas (defesa em
 * profundidade, mesmo padrão de `StoreFloorPlanRequest`).
 */
class MonitoringReportFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('monitoramento-ver') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'address_id' => ['nullable', 'integer', 'exists:addresses,id'],
            'de' => ['nullable', 'date'],
            'ate' => ['nullable', 'date', 'after_or_equal:de'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'client_id.exists' => 'Cliente não encontrado.',
            'address_id.exists' => 'Endereço não encontrado.',
            'de.date' => 'Informe uma data inicial válida.',
            'ate.date' => 'Informe uma data final válida.',
            'ate.after_or_equal' => 'A data final não pode ser anterior à data inicial.',
        ];
    }
}
