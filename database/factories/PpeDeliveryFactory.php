<?php

namespace Database\Factories;

use App\Models\PersonalProtectiveEquipment;
use App\Models\PpeDelivery;
use App\Models\Technician;
use App\Support\BusinessDate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Entrega de EPI a um técnico (Plano 28, Task 28.1).
 *
 * O model não usa `HasFactory`: instanciar sempre por
 * `PpeDeliveryFactory::new()`.
 *
 * Esta fábrica não substitui o `PpeDeliveryService`
 * -------------------------------------------------
 * Em produção, **nenhuma escrita em `ppe_deliveries` acontece fora do
 * Service** — é ele que copia o CA, calcula a troca e recusa o que não pode ser
 * entregue. A fábrica existe só para montar cenário de teste que o Service não
 * conseguiria produzir hoje: a entrega histórica com data antiga (o Service
 * recusa data futura, mas o cenário interessante é o retroativo), a entrega de
 * um EPI cujo CA já venceu depois do dia da entrega, e a linha crua usada para
 * conferir uma leitura.
 *
 * Todo teste que exercita **regra** (cópia do CA, cálculo de `trocar_ate`,
 * recusa) chama o Service. Usar a fábrica ali provaria apenas que a fábrica faz
 * o que ela mesma escreveu.
 *
 * `entregueA()` reproduz o snapshot do CA de propósito: a entrega guarda a
 * cópia do certificado do dia, e uma fábrica que deixasse `numero_ca` nulo
 * criaria histórico que o sistema real nunca gera.
 *
 * `company_id` fica a cargo da trait `BelongsToCompany`; criar fora de
 * `TenantAtual::comTenant()` deixa a coluna NOT NULL sem valor.
 *
 * @extends Factory<PpeDelivery>
 */
class PpeDeliveryFactory extends Factory
{
    protected $model = PpeDelivery::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quantidade' => 1,
            'entregue_em' => BusinessDate::hoje()->toDateString(),
            'motivo' => 'primeira_entrega',
            'numero_ca' => null,
            'validade_ca' => null,
            'trocar_ate' => null,
            'user_id' => null,
        ];
    }

    /**
     * Entrega de um modelo de EPI a um técnico, com o CA copiado e a troca
     * calculada como o `PpeDeliveryService` faria naquele dia.
     *
     * `$entregueEm` é o dia da entrega em `Y-m-d`, no fuso do negócio; hoje
     * quando ausente. `trocar_ate` sai nulo quando o cadastro não tem vida
     * útil — "sem troca programada", nunca um prazo inventado.
     */
    public function entregueA(
        Technician $tecnico,
        PersonalProtectiveEquipment $epi,
        ?string $entregueEm = null,
    ): static {
        return $this->state(function (array $atributos) use ($tecnico, $epi, $entregueEm): array {
            $dia = BusinessDate::paraFusoNegocio($entregueEm ?? ($atributos['entregue_em'] ?? null))
                ?? BusinessDate::hoje();

            return [
                'technician_id' => $tecnico->getKey(),
                'personal_protective_equipment_id' => $epi->getKey(),
                'entregue_em' => $dia->toDateString(),
                'numero_ca' => $epi->numero_ca,
                'validade_ca' => BusinessDate::diaDe($epi->validade_ca),
                'trocar_ate' => $epi->vida_util_dias === null
                    ? null
                    : $dia->addDays((int) $epi->vida_util_dias)->toDateString(),
            ];
        });
    }

    /**
     * Entrega já assinada pelo técnico.
     *
     * Só o instante e o caminho: a imagem em disco é assunto do Service, e a
     * ficha considera assinada a entrega que tem qualquer um dos dois.
     */
    public function assinada(?string $em = null): static
    {
        return $this->state(fn (): array => [
            'assinado_em' => $em ?? now()->toDateTimeString(),
            'assinatura_path' => 'ppe-deliveries/assinatura-de-teste.png',
        ]);
    }

    /**
     * Entrega estornada, com motivo.
     *
     * Estorno sem motivo é indistinguível de exclusão disfarçada, então a
     * fábrica também não produz um.
     */
    public function estornada(string $motivo = 'Lançada no técnico errado.'): static
    {
        return $this->state(fn (): array => [
            'estornada_em' => now()->toDateTimeString(),
            'motivo_estorno' => $motivo,
        ]);
    }

    /**
     * Item devolvido pelo técnico. Devolução não é estorno: a entrega
     * aconteceu e terminou.
     */
    public function devolvida(?string $em = null, string $motivo = 'Troca de equipamento.'): static
    {
        return $this->state(fn (array $atributos): array => [
            'devolvido_em' => $em ?? BusinessDate::diaDe($atributos['entregue_em'] ?? null) ?? BusinessDate::hoje()->toDateString(),
            'motivo_devolucao' => $motivo,
        ]);
    }
}
