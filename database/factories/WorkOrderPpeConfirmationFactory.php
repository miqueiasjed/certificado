<?php

namespace Database\Factories;

use App\Models\PersonalProtectiveEquipment;
use App\Models\WorkOrder;
use App\Models\WorkOrderPpeConfirmation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * O que o técnico confirmou vestir numa ordem de serviço (Plano 29, Task 29.1).
 *
 * O model não usa `HasFactory`: instanciar sempre por
 * `WorkOrderPpeConfirmationFactory::new()`.
 *
 * Esta fábrica não substitui o `ConfirmacaoDeEpiService`
 * -----------------------------------------------------
 * Em produção, **nenhuma escrita em `work_order_ppe_confirmations` acontece
 * fora do Service**: é ele que exige a justificativa da falta, que preserva o
 * `confirmado_em` original no reenvio e que garante a idempotência da linha do
 * par (OS, EPI). Gravar por aqui pula as três, e é justamente por isso que a
 * fábrica serve a um caso só — a **linha herdada**, aquela que já está no banco
 * antes do cenário começar: a confirmação de um EPI cuja exigência foi removida
 * depois, ou a linha antiga sem justificativa que o aviso ao gestor precisa
 * saber ler.
 *
 * Todo teste que exercita **regra** (justificativa obrigatória, reenvio
 * idempotente, instante preservado) chama o Service ou entra pela fila offline.
 * Usar a fábrica ali provaria apenas que a fábrica faz o que ela mesma escreveu.
 *
 * `confirmado` é declarado sempre, e de propósito: a coluna nasceu sem default
 * na Task 29.1, porque `false` acusaria o técnico de não ter usado o EPI por
 * causa de um insert incompleto e `true` seria pior ainda perante fiscalização.
 *
 * `company_id` fica a cargo da trait `BelongsToCompany`; criar fora de
 * `TenantAtual::comTenant()` deixa a coluna NOT NULL sem valor.
 *
 * @extends Factory<WorkOrderPpeConfirmation>
 */
class WorkOrderPpeConfirmationFactory extends Factory
{
    protected $model = WorkOrderPpeConfirmation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'confirmado' => true,
            'justificativa' => null,
            // Instante do aparelho do técnico, gravado em UTC. Nunca `date`:
            // duas OS no mesmo dia precisam de ordem entre si.
            'confirmado_em' => now(),
            'user_id' => null,
        ];
    }

    /**
     * A confirmação de um modelo de EPI em uma ordem de serviço.
     */
    public function naOrdem(WorkOrder $os, PersonalProtectiveEquipment $epi): static
    {
        return $this->state(fn (): array => [
            'work_order_id' => $os->getKey(),
            'personal_protective_equipment_id' => $epi->getKey(),
        ]);
    }

    /**
     * O técnico declarou que **não** usou o equipamento, com o motivo.
     *
     * Falta sem motivo declarado é registro que não serve para nada depois, nem
     * para o gestor nem para a fiscalização — por isso a fábrica também não
     * produz uma sem passar a justificativa explicitamente.
     */
    public function naoUsado(string $justificativa = 'Respirador com a troca vencida, sem reposição na base.'): static
    {
        return $this->state(fn (): array => [
            'confirmado' => false,
            'justificativa' => $justificativa,
        ]);
    }
}
