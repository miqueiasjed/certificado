<?php

namespace App\Services\Sync;

use App\Models\User;
use App\Services\Sync\Concerns\OperacaoDeCampo;
use App\Services\WorkOrderSignatureService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Aplica uma operação de sincronização do tipo `recusa_assinatura` (Plano 13,
 * Task 13.9 - lacuna encontrada na implementação do frontend).
 *
 * A Task 13.3 implementou `WorkOrderSignatureService::registrarRecusa()`, mas
 * só o painel web (`WorkOrderSignatureController::recusar()`) chamava esse
 * método. O aplicativo do técnico (Task 13.9) é offline-first e registra a
 * recusa em campo, exatamente onde ela mais acontece - sem esta operação de
 * sincronização, a recusa registrada no aparelho nunca chegaria ao servidor,
 * o que contradiz a regra "recusa é situação própria, e hoje não tem para
 * onde ir" (Plano 13).
 *
 * Delega inteiro a `WorkOrderSignatureService::registrarRecusa()`: nenhuma
 * regra de negócio reimplementada aqui. Assim como a recusa não trava a OS,
 * este aplicador não marca a operação como aplicar-e-fim de um estado
 * irreversível - o painel continua livre para tratar o atendimento depois.
 */
class AplicadorDeRecusaDeAssinatura implements AplicadorDeOperacao
{
    use OperacaoDeCampo;

    public function __construct(
        private readonly WorkOrderSignatureService $workOrderSignatureService,
    ) {}

    public function tipo(): string
    {
        return 'recusa_assinatura';
    }

    public function aplicar(array $payload, User $usuario): Model
    {
        $workOrder = $this->workOrderDestravada($payload);

        $motivo = trim((string) ($payload['motivo'] ?? ''));

        if ($motivo === '') {
            throw ValidationException::withMessages([
                'motivo' => 'Informe o motivo da recusa de assinatura.',
            ]);
        }

        return $this->workOrderSignatureService->registrarRecusa($workOrder, $motivo, $usuario);
    }
}
