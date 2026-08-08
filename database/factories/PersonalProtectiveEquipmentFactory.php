<?php

namespace Database\Factories;

use App\Models\PersonalProtectiveEquipment;
use App\Support\BusinessDate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Modelo de EPI do cadastro (Plano 28, Task 28.1).
 *
 * O model não usa o trait `HasFactory`, como o resto do domínio deste projeto,
 * então esta fábrica é sempre instanciada por
 * `PersonalProtectiveEquipmentFactory::new()`, nunca por
 * `PersonalProtectiveEquipment::factory()`.
 *
 * `company_id` não é declarado aqui de propósito: quem preenche é a trait
 * `BelongsToCompany`, a partir do tenant corrente. Criar fora de
 * `TenantAtual::comTenant()` produziria um cadastro sem empresa, que é
 * exatamente o que a coluna NOT NULL da migration recusa.
 *
 * Nenhuma data é sorteada. `validade_ca` é o dado de que dependem a recusa da
 * entrega (Task 28.2) e o alerta de vencimento (Task 28.3), e um valor
 * aleatório faria o mesmo teste passar hoje e falhar em outro dia. O padrão é
 * um ano à frente, no fuso do negócio, e cada teste diz explicitamente a data
 * que lhe interessa.
 *
 * @extends Factory<PersonalProtectiveEquipment>
 */
class PersonalProtectiveEquipmentFactory extends Factory
{
    protected $model = PersonalProtectiveEquipment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nome' => 'Respirador semifacial '.fake()->unique()->numerify('####'),
            'tipo' => 'respirador',
            'fabricante' => '3M',
            'numero_ca' => 'CA-'.fake()->unique()->numerify('#####'),
            'validade_ca' => BusinessDate::hoje()->addYear()->toDateString(),
            'vida_util_dias' => 180,
            'obrigatorio' => true,
            'ativo' => true,
            'observacoes' => null,
        ];
    }

    /**
     * CA vencido no dia informado (padrão: ontem, no fuso do negócio).
     *
     * O EPI que chega assim é recusado por `PpeDeliveryService::registrar()` e
     * entra no alerta semanal de CA vencido.
     */
    public function comCaVencido(?string $em = null): static
    {
        return $this->state(fn (): array => [
            'validade_ca' => $em ?? BusinessDate::hoje()->subDay()->toDateString(),
        ]);
    }

    /**
     * Cadastro sem certificado preenchido.
     *
     * Campo em branco é estado neutro, nunca irregularidade: este EPI pode ser
     * entregue e fica **fora** da varredura de vencimento. Os dois campos saem
     * juntos porque o alerta exige número e validade preenchidos, e deixar
     * metade produziria um cadastro que nenhum caminho do sistema trata.
     */
    public function semCa(): static
    {
        return $this->state(fn (): array => [
            'numero_ca' => null,
            'validade_ca' => null,
        ]);
    }

    /**
     * Sem vida útil cadastrada: "sem troca programada".
     *
     * A entrega deste modelo nasce com `trocar_ate` nulo e nunca gera alerta de
     * troca. Diferente de `vida_util_dias = 0`, que é uma escolha do cadastro e
     * produz troca no próprio dia da entrega.
     */
    public function semVidaUtil(): static
    {
        return $this->state(fn (): array => ['vida_util_dias' => null]);
    }

    /**
     * Modelo aposentado. Não pode ser entregue e sai da varredura de
     * vencimento do CA.
     */
    public function inativo(): static
    {
        return $this->state(fn (): array => ['ativo' => false]);
    }
}
