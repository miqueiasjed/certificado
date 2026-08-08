<?php

namespace App\Services\Sync;

use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Routing\LocalDeExecucaoService;
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
 *
 * **Local da execução (Plano 22, Task 22.4).** O payload de `iniciar` e de
 * `concluir` aceita, opcionalmente, `latitude` e `longitude` (float): a
 * coordenada do aparelho no momento de cada ação, quando o técnico autorizou
 * a localização. As duas ações usam as mesmas duas chaves porque `acao` já
 * desambigua qual delas está em jogo - não existem `inicio_latitude`/
 * `fim_latitude` no payload, só no banco, e é este aplicador quem faz essa
 * tradução ao chamar `LocalDeExecucaoService`. Ausência de uma das duas
 * chaves (ou das duas) é tratada como "sem autorização", nunca como erro de
 * validação: `LocalDeExecucaoService::registrarInicio()`/`registrarFim()`
 * gravam o instante de qualquer forma e deixam a coordenada de fora. A Task
 * 22.7 (aplicativo do técnico) lê este contrato para saber o que enviar.
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
        private readonly LocalDeExecucaoService $localDeExecucaoService,
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

        // Local do início (Task 22.4): sempre grava o instante, e a
        // coordenada só quando as duas chaves vieram no payload. Roda mesmo
        // quando `$dados` ficou vazio (reenvio de "iniciar" já processado),
        // porque `inicio_registrado_em`/`inicio_latitude`/`inicio_longitude`
        // não têm a mesma trava de "só o primeiro" que `start_time` tem: não
        // há regra de negócio pedindo isso, e reenviar o mesmo instante do
        // celular não causa dano.
        $this->localDeExecucaoService->registrarInicio(
            $workOrder,
            $this->coordenadaDoPayload($payload, 'latitude'),
            $this->coordenadaDoPayload($payload, 'longitude'),
            $instante,
        );

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

        // Local do fim e divergência (Task 22.4): roda depois da conclusão já
        // aplicada, nunca antes e nunca impede o fechamento. `registrarFim()`
        // não lança exceção; na pior hipótese a divergência fica `null`.
        $this->localDeExecucaoService->registrarFim(
            $workOrder,
            $this->coordenadaDoPayload($payload, 'latitude'),
            $this->coordenadaDoPayload($payload, 'longitude'),
            $instante,
        );

        return $workOrder->fresh();
    }

    /**
     * Lê `latitude`/`longitude` do payload como float, ou `null` quando a
     * chave não veio, veio vazia, ou não é um número válido. Payload
     * malformado no campo de apoio de coordenada nunca vira erro de
     * validação: `LocalDeExecucaoService` trata `null` como "sem
     * autorização", o caso normal de quem recusou a localização no aparelho.
     */
    private function coordenadaDoPayload(array $payload, string $chave): ?float
    {
        $valor = $payload[$chave] ?? null;

        if ($valor === null || $valor === '' || ! is_numeric($valor)) {
            return null;
        }

        return (float) $valor;
    }
}
