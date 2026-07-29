<?php

namespace App\Exceptions;

use App\Models\WorkOrder;
use RuntimeException;
use Throwable;

/**
 * Recusa de alteração de uma ordem de serviço já assinada, pelo caminho comum
 * de atualização (Plano 13, Task 13.3).
 *
 * A OS assinada é documento: o cliente confirmou o serviço prestado, e deixar
 * qualquer `update()`/`delete()` comum mexer nela depois anularia esse valor.
 * A partir da assinatura (`WorkOrder::estaTravada()`), a única porta de
 * alteração passa a ser `WorkOrderSignatureService::corrigirComJustificativa()`,
 * que exige a permissão `os.corrigir_assinada`, justificativa registrada e
 * grava o antes/depois na auditoria.
 *
 * Por que classe própria, e não um erro genérico do framework: quem chama
 * `WorkOrderService` (painel, agenda, aplicativo via sincronização) precisa
 * de uma mensagem em português, específica o bastante para orientar quem bateu
 * nela ("use a correção justificada"), e não uma `QueryException` ou um erro
 * de framework sem contexto de negócio.
 *
 * Quem lança: os métodos de atualização de `App\Services\WorkOrderService`
 * (`updateWorkOrder`, `updateStatus`, `markAsCompleted`), sempre antes de
 * qualquer escrita. Fica sem efeito quando a chamada acontece dentro de
 * `WorkOrderService::comTravamentoIgnorado()`, a porta usada exclusivamente
 * por `WorkOrderSignatureService::corrigirComJustificativa()`.
 */
class OsTravadaException extends RuntimeException
{
    public const MENSAGEM_PADRAO = 'Esta ordem de serviço já foi assinada pelo cliente e está travada para '
        .'edição pelo caminho comum. Para alterar um dado depois da assinatura, use a correção justificada.';

    public function __construct(
        string $message = self::MENSAGEM_PADRAO,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Recusa citando o número da ordem, quando conhecido.
     */
    public static function paraOrdem(WorkOrder $workOrder): self
    {
        $identificador = $workOrder->order_number ?? ('#'.$workOrder->id);

        return new self(sprintf(
            'A ordem de serviço "%s" já foi assinada pelo cliente e está travada para edição pelo caminho '
            .'comum. Para alterar um dado depois da assinatura, use a correção justificada.',
            $identificador
        ));
    }
}
