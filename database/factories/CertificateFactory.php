<?php

namespace Database\Factories;

use App\Models\Certificate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Certificate>
 */
class CertificateFactory extends Factory
{
    protected $model = Certificate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => ClientFactory::new(),
            'certificate_number' => fake()->unique()->numerify('CERT-######'),
            'execution_date' => '2026-07-01',
            'warranty' => '2026-07-25',
            'status' => 'active',
        ];
    }

    /**
     * Certificado cancelado: a rotina não pode encostar nele.
     */
    public function cancelado(): static
    {
        return $this->state(fn (array $atributos): array => ['status' => 'cancelled']);
    }

    /**
     * Certificado sem garantia definida.
     */
    public function semGarantia(): static
    {
        return $this->state(fn (array $atributos): array => ['warranty' => null]);
    }
}
