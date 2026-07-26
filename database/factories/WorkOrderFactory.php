<?php

namespace Database\Factories;

use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkOrder>
 */
class WorkOrderFactory extends Factory
{
    protected $model = WorkOrder::class;

    /**
     * Datas fixas de propósito: os testes que dependem de data fixam o relógio
     * com Carbon::setTestNow e definem os valores que importam explicitamente.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_number' => fake()->unique()->numerify('OS-######'),
            'client_id' => ClientFactory::new(),
            // O endereço nasce vinculado ao mesmo cliente da ordem, porque
            // endereço pertence a cliente e o sistema confere esse vínculo.
            'address_id' => fn (array $atributos) => AddressFactory::new()
                ->create(['client_id' => $atributos['client_id']])
                ->id,
            'scheduled_date' => '2026-07-01',
            'status' => 'completed',
            'total_cost' => 500.00,
            'discount_amount' => 0,
            'final_amount' => 500.00,
            'payment_status' => 'pending',
            'active' => true,
        ];
    }
}
