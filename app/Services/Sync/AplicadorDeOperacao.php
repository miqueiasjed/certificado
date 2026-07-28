<?php

namespace App\Services\Sync;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Contrato de um aplicador de operação de sincronização (Plano 12, Task 12.4).
 *
 * Cada `sync_operations.tipo` tem um aplicador próprio, resolvido por um mapa
 * dentro de `AppSyncService`. O aplicador não conhece `sync_operations` nem
 * `sync_conflicts`: recebe só o `payload` já validado quanto ao acesso à OS e
 * ao `updated_at`, e devolve o registro criado ou alterado. Toda a
 * contabilidade de idempotência e de conflito fica em `AppSyncService`, para o
 * aplicador ficar livre de repetir essa lógica a cada tipo novo.
 *
 * Nesta task só existe `AplicadorDeFoto`. Os aplicadores de evento de
 * dispositivo, avistamento, adequação e conclusão de OS entram no Plano 13,
 * cada um implementando esta mesma interface.
 */
interface AplicadorDeOperacao
{
    /**
     * O valor de `sync_operations.tipo` que este aplicador atende.
     */
    public function tipo(): string;

    /**
     * Aplica o payload da operação e devolve o registro afetado.
     *
     * `$payload` já traz `work_order_id` quando a operação está ligada a uma
     * ordem de serviço: `AppSyncService` o injeta antes de chamar este método,
     * a partir da OS já validada, nunca a partir do que o aplicativo mandou
     * dentro do próprio payload.
     *
     * @throws ValidationException Quando o payload fere uma regra de negócio
     *                             do tipo. `AppSyncService` transforma essa
     *                             exceção em um `sync_conflict` com motivo
     *                             `regra_de_negocio`, com a mensagem em
     *                             português preservada para o técnico.
     */
    public function aplicar(array $payload, User $usuario): Model;
}
