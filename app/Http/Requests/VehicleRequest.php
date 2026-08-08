<?php

namespace App\Http\Requests;

use App\Models\Vehicle;
use App\Support\DominioMultiempresa;
use App\Support\TenantAtual;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Cadastro de veículo da frota (Plano 27, Task 27.4).
 *
 * A unique de `placa` é composta com `company_id` na tabela, e a regra de
 * validação precisa dizer a mesma coisa: `Rule::unique()` sem o
 * `where(company_id)` recusaria a placa que **outra** empresa cadastrou, o que
 * além de errado vaza a informação de que aquela placa existe em algum lugar do
 * sistema.
 *
 * `km_atual` não é aceito aqui de propósito: quem move o hodômetro é o registro
 * de abastecimento e o de manutenção, que recusam leitura retroativa
 * (`QuilometragemRetroativaException`). Aceitá-lo no cadastro reabriria a porta
 * que essa recusa existe para fechar.
 *
 * `technician_id` vem do corpo da requisição e por isso é escopado à empresa
 * corrente pela própria regra: `exists:technicians,id` solto consulta a tabela
 * inteira, sem passar pelo escopo global do Eloquent, e aceitava técnico de
 * outra empresa (além de responder se aquele id existe em algum tenant).
 */
class VehicleRequest extends FormRequest
{
    /**
     * A rota inteira já está sob `module:frota` + `permission:frota-gerenciar`,
     * e o escopo global por empresa responde 404 para veículo de outro tenant.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $veiculo = $this->route('vehicle');
        $obrigatorio = $this->isMethod('POST') ? 'required' : 'sometimes|required';

        return [
            'placa' => [
                $this->isMethod('POST') ? 'required' : 'sometimes',
                'string',
                'max:10',
                Rule::unique('vehicles', 'placa')
                    ->where(DominioMultiempresa::COLUNA_TENANT, TenantAtual::id())
                    ->ignore($veiculo instanceof Vehicle ? $veiculo->getKey() : null),
            ],
            'modelo' => $obrigatorio.'|string|max:255',
            'marca' => $obrigatorio.'|string|max:255',
            'ano' => 'nullable|integer|min:1900|max:2100',
            'tipo' => ['nullable', Rule::in(Vehicle::TIPOS)],
            'technician_id' => ['nullable', 'integer', $this->regraDeTecnicoDaEmpresa()],
            'situacao' => ['nullable', Rule::in(Vehicle::SITUACOES)],
            'custo_km_padrao' => 'nullable|numeric|min:0',
        ];
    }

    /**
     * Técnico existente E da empresa corrente.
     *
     * `exigirId()` em vez de `id()` pelo mesmo motivo de
     * `UserRequest::regraDeTecnicoDaEmpresa()`: a rota é autenticada e
     * `users.company_id` é NOT NULL, então requisição sem empresa resolvida é
     * bug, e falhar alto é melhor que validar contra o banco inteiro.
     */
    private function regraDeTecnicoDaEmpresa(): Exists
    {
        return Rule::exists('technicians', 'id')
            ->where(DominioMultiempresa::COLUNA_TENANT, TenantAtual::exigirId());
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'placa.required' => 'Informe a placa do veículo.',
            'placa.unique' => 'Já existe um veículo com esta placa nesta empresa.',
            'modelo.required' => 'Informe o modelo do veículo.',
            'marca.required' => 'Informe a marca do veículo.',
            'tipo.in' => 'Tipo de veículo inválido.',
            'situacao.in' => 'Situação de veículo inválida.',
            'technician_id.integer' => 'Técnico inválido.',
            'technician_id.exists' => 'O técnico selecionado não existe.',
            'custo_km_padrao.min' => 'O custo por quilômetro não pode ser negativo.',
        ];
    }
}
