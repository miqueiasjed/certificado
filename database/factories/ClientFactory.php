<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * O model Client não usa o trait HasFactory, então esta fábrica é sempre
 * instanciada por ClientFactory::new(), nunca por Client::factory().
 *
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    protected $model = Client::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->numerify('(11) 9####-####'),
            'cnpj' => fake()->numerify('##.###.###/0001-##'),
            'notes' => null,
        ];
    }
}
