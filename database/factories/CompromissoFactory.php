<?php

namespace Database\Factories;

use App\Models\Compromisso;
use App\Models\Technician;
use App\Support\BusinessDate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Compromisso avulso (Plano 30, Task 30.1).
 *
 * Estado padrão de propósito: `client_id`, `address_id`, `technician_id` e
 * `work_order_id` nascem nulos, porque compromisso sem nada vinculado ainda
 * precisa continuar válido (ver o motivo em `Compromisso`). `company_id` fica
 * a cargo da trait `BelongsToCompany`; criar fora de
 * `TenantAtual::comTenant()` deixa a coluna NOT NULL sem valor.
 *
 * @extends Factory<Compromisso>
 */
class CompromissoFactory extends Factory
{
    protected $model = Compromisso::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tipo' => fake()->randomElement(Compromisso::TIPOS),
            'titulo' => fake()->sentence(4),
            'observacoes' => fake()->optional()->sentence(),
            'client_id' => null,
            'address_id' => null,
            'endereco_texto' => null,
            'technician_id' => null,
            'data' => BusinessDate::hoje()->toDateString(),
            'hora_inicio' => sprintf('%02d:00:00', fake()->numberBetween(7, 11)),
            'hora_fim' => sprintf('%02d:00:00', fake()->numberBetween(12, 17)),
            'situacao' => 'agendado',
            'work_order_id' => null,
            'criado_por' => null,
        ];
    }

    /**
     * Compromisso com cliente já cadastrado, e o endereço nascendo vinculado
     * ao mesmo cliente, porque endereço pertence a cliente e o sistema
     * confere esse vínculo (mesmo critério de `WorkOrderFactory`).
     */
    public function comCliente(): static
    {
        return $this->state(fn (array $atributos): array => [
            'client_id' => ClientFactory::new(),
            'address_id' => fn (array $atributos): int => AddressFactory::new()
                ->create(['client_id' => $atributos['client_id']])
                ->id,
        ]);
    }

    /**
     * Compromisso com técnico já definido.
     *
     * Sem `TechnicianFactory` no projeto (nenhum model o usa hoje; os testes
     * criam o técnico direto com `Technician::create()`), então esta fábrica
     * segue o mesmo caminho em vez de introduzir uma fábrica nova fora do
     * escopo desta task.
     */
    public function comTecnico(): static
    {
        return $this->state(fn (): array => [
            'technician_id' => Technician::create([
                'name' => fake()->name(),
                'email' => fake()->unique()->safeEmail(),
                'phone' => fake()->numerify('119########'),
                'is_active' => true,
            ])->id,
        ]);
    }
}
