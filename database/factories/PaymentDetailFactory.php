<?php

namespace Database\Factories;

use App\Models\PaymentDetail;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentDetail>
 */
class PaymentDetailFactory extends Factory
{
    protected $model = PaymentDetail::class;

    /**
     * A parcela nasce em aberto e com a coluna payment_status nula, que é o
     * estado das parcelas antigas em produção e o que força o ramo calculado
     * do accessor.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'work_order_id' => WorkOrderFactory::new(),
            'total_cost' => 500.00,
            'discount_amount' => 0,
            'final_amount' => 500.00,
            'payment_due_date' => null,
            'payment_date' => null,
            'amount_paid' => null,
            'is_partial_payment' => false,
            'payment_status' => null,
            'active' => true,
        ];
    }
}
