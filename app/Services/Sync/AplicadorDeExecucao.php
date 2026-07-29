<?php

namespace App\Services\Sync;

use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Sync\Concerns\OperacaoDeCampo;
use App\Services\WorkOrderService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Aplica uma operação de sincronização do tipo `execucao` (Plano 13, Task
 * 13.2): o início e a conclusão da execução da ordem de serviço pelo técnico
 * em campo.
 *
 * `AplicadorDeOperacao::tipo()` amarra um único valor de
 * `sync_operations.tipo` a cada aplicador, e a Task 13.2 pede as duas ações
 * (iniciar e concluir) num único arquivo. Por isso as duas vivem sob o mesmo
 * tipo `execucao`, e o payload traz a chave `acao` (`iniciar` ou `concluir`)
 * para desambiguar qual delas aplicar; não há dois tipos de operação
 * separados para isso.
 *
 * `iniciar` não tem regra de domínio existente para delegar (não há um
 * "iniciar" hoje em `WorkOrderService`): grava o começo da execução
 * diretamente, com o instante do celular. `concluir` delega a
 * `WorkOrderService::markAsCompleted()`, a regra atual de fechamento da OS,
 * sem repeti-la aqui; como aquele método sempre grava `end_time` com o
 * instante do servidor (`now()`), o instante do celular é corrigido logo
 * depois, sem tocar em `WorkOrderService.php` nem duplicar a regra de
 * fechamento em si (só o valor de um campo, depois que a regra já rodou).
 */
class AplicadorDeExecucao implements AplicadorDeOperacao
{
    use OperacaoDeCampo;

    private const ACAO_INICIAR = 'iniciar';

    private const ACAO_CONCLUIR = 'concluir';

    private const ACOES_VALIDAS = [self::ACAO_INICIAR, self::ACAO_CONCLUIR];

    /**
     * Situações de onde partir para "em andamento" ao iniciar a execução.
     */
    private const SITUACOES_ANTES_DE_INICIAR = ['pending', 'scheduled'];

    public function __construct(
        private readonly WorkOrderService $workOrderService,
    ) {}

    public function tipo(): string
    {
        return 'execucao';
    }

    public function aplicar(array $payload, User $usuario): Model
    {
        $workOrder = $this->workOrderDestravada($payload);

        $acao = (string) ($payload['acao'] ?? '');

        if (! in_array($acao, self::ACOES_VALIDAS, true)) {
            throw ValidationException::withMessages([
                'acao' => 'Ação de execução inválida. Use "iniciar" ou "concluir".',
            ]);
        }

        return $acao === self::ACAO_INICIAR
            ? $this->iniciar($workOrder, $payload)
            : $this->concluir($workOrder, $payload);
    }

    private function iniciar(WorkOrder $workOrder, array $payload): WorkOrder
    {
        $instante = $this->instanteDoCelular($payload);

        $dados = [];

        // O início registrado é sempre o primeiro: reenviar "iniciar" (uuid
        // novo, mesmo instante ou não) nunca sobrescreve o começo real já
        // gravado.
        if ($workOrder->start_time === null) {
            $dados['start_time'] = $instante;
        }

        if (in_array($workOrder->status, self::SITUACOES_ANTES_DE_INICIAR, true)) {
            $dados['status'] = 'in_progress';
        }

        if ($dados !== []) {
            $workOrder->update($dados);
        }

        return $workOrder->fresh();
    }

    private function concluir(WorkOrder $workOrder, array $payload): WorkOrder
    {
        $instante = $this->instanteDoCelular($payload);

        // O instante do celular vai junto com o fechamento, e não numa
        // correção depois: `markAsCompleted()` respeita o `end_time` recebido
        // e usa esse mesmo instante para a baixa de estoque (Plano 17, Task
        // 17.4), que precisa saber o dia em que a aplicação aconteceu em campo
        // para julgar a validade do lote. Corrigir o campo depois deixaria a
        // baixa registrada no dia da sincronização, que pode ser dias à frente.
        $this->workOrderService->markAsCompleted($workOrder, ['end_time' => $instante]);

        return $workOrder->fresh();
    }
}
