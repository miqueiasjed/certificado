<?php

namespace App\Observers;

use App\Models\WorkOrder;
use App\Services\NotificationService;
use App\Support\BusinessDate;
use App\Support\EventosDeNotificacao;
use App\Support\TenantAtual;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Os três avisos de ordem de serviço que nascem de uma ação do usuário
 * (Task 14.4): visita agendada, técnico a caminho e OS concluída.
 *
 * Por que observer, e não o `WorkOrderService`
 * --------------------------------------------
 * O status da OS muda por vários caminhos: `updateWorkOrder`, `updateStatus`,
 * `markAsCompleted` e a edição direta do model em outros fluxos. Amarrar o
 * disparo ao Service cobriria uns e deixaria os outros mudos, e o cliente
 * receberia aviso de conclusão dependendo de qual botão a empresa usou. O
 * observer vê a transição do dado, que é o fato que interessa.
 *
 * Gatilho de "técnico a caminho"
 * ------------------------------
 * O plano pede ação explícita do técnico, nunca inferência por horário ou
 * localização: aviso automático errado faz o cliente esperar à toa. Só que não
 * existe hoje no sistema tela, rota nem botão de "iniciar deslocamento", e
 * criá-los está fora do escopo desta task. O gatilho adotado é a transição do
 * status para `in_progress`, que continua sendo ação explícita de um usuário
 * (nenhuma rotina automática coloca OS em andamento; a rotina financeira que
 * altera OS mexe em `payment_status`, e por isso a checagem aqui é
 * `wasChanged('status')`, não `wasChanged()`). Quando a tela de deslocamento
 * existir, o disparo migra para ela e este trecho sai.
 *
 * Nenhuma exceção escapa
 * ----------------------
 * Todo disparo é embrulhado em try/catch. O motivo é concreto:
 * `GeracaoDeVisitasService::gerarPara()` cria as visitas do contrato dentro de
 * uma transação, e este observer roda dentro dela. Uma exceção aqui reverteria a
 * geração inteira das visitas por causa de um e-mail que não pôde ser montado.
 * Falha em notificar é falha de comunicação, nunca de operação.
 */
class WorkOrderNotificationObserver
{
    /**
     * Status que representa o técnico em deslocamento ou em atendimento.
     */
    private const STATUS_EM_ANDAMENTO = 'in_progress';

    /**
     * Status de atendimento encerrado, que gera o aviso com o documento anexo.
     */
    private const STATUS_CONCLUIDA = 'completed';

    public function __construct(private readonly NotificationService $notificacoes)
    {
    }

    /**
     * OS nova com data no futuro avisa o cliente do agendamento.
     *
     * "Futuro" é estritamente maior que hoje, no fuso do negócio: OS lançada
     * para hoje é registro de atendimento em curso, e mandar "sua visita está
     * agendada" para quem já está com o técnico na porta é ruído.
     *
     * Vale também para a visita gerada pela rotina de contratos, que é OS
     * agendada de verdade e o cliente tem interesse em conhecer. A
     * contrapartida é que um contrato de periodicidade curta enfileira vários
     * avisos de uma vez quando a janela de três meses é materializada; se isso
     * incomodar na prática, o lugar de resolver é aqui, com um limite de
     * antecedência, e não desligando o evento.
     */
    public function created(WorkOrder $workOrder): void
    {
        if ($workOrder->status === 'cancelled' || ! $this->estaNoFuturo($workOrder)) {
            return;
        }

        $this->enfileirar(EventosDeNotificacao::VISITA_AGENDADA, $workOrder);
    }

    /**
     * Mudança de status: só ela dispara aviso.
     *
     * Qualquer outra alteração da OS (valor, observação, `payment_status` pela
     * rotina financeira) passa por aqui e não gera nada.
     */
    public function updated(WorkOrder $workOrder): void
    {
        if (! $workOrder->wasChanged('status')) {
            return;
        }

        if ($workOrder->status === self::STATUS_EM_ANDAMENTO) {
            $this->enfileirar(EventosDeNotificacao::TECNICO_A_CAMINHO, $workOrder);

            return;
        }

        if ($workOrder->status === self::STATUS_CONCLUIDA) {
            // O PDF não fica salvo em disco neste sistema: o driver de e-mail
            // (Task 14.3) lê `work_order_id` do contexto e gera o documento na
            // hora do envio, pelo mesmo caminho da tela.
            $this->enfileirar(EventosDeNotificacao::OS_CONCLUIDA, $workOrder, [
                'contexto' => ['work_order_id' => $workOrder->id],
            ]);
        }
    }

    /**
     * A data agendada é posterior ao dia de hoje no fuso do negócio?
     *
     * Comparação por dia, nunca por instante: às 21h de Brasília o servidor já
     * está no dia seguinte em UTC, e uma OS marcada para amanhã deixaria de ser
     * futuro.
     */
    private function estaNoFuturo(WorkOrder $workOrder): bool
    {
        $dia = BusinessDate::paraFusoNegocio($workOrder->scheduled_date);

        return $dia !== null && $dia->startOfDay()->greaterThan(BusinessDate::hoje());
    }

    /**
     * Enfileira o aviso sem deixar nenhuma falha alcançar quem salvou a OS.
     *
     * @param  array<string, mixed>  $opcoes
     */
    private function enfileirar(string $evento, WorkOrder $workOrder, array $opcoes = []): void
    {
        if (TenantAtual::id() === null) {
            // Sem empresa resolvida não há remetente, cabeçalho nem escopo de
            // consulta. Em requisição HTTP e em rotina agendada o tenant sempre
            // existe; cair aqui é sinal de seeder ou script sem contexto, que
            // não é caminho de produção e não deve poluir o log de erro.
            Log::debug('Aviso de ordem de serviço não enfileirado: nenhuma empresa resolvida no contexto.', [
                'evento' => $evento,
                'work_order_id' => $workOrder->id,
            ]);

            return;
        }

        try {
            $this->notificacoes->enfileirar($evento, $workOrder, $opcoes);
        } catch (Throwable $erro) {
            report($erro);

            Log::error('Falha ao enfileirar aviso de ordem de serviço.', [
                'evento' => $evento,
                'work_order_id' => $workOrder->id,
                'mensagem' => $erro->getMessage(),
            ]);
        }
    }
}
