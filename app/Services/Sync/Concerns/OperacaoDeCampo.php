<?php

namespace App\Services\Sync\Concerns;

use App\Models\WorkOrder;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Comportamento comum aos aplicadores de operação de campo do Plano 13
 * (Task 13.2): resolver a ordem de serviço do payload, recusar quando ela
 * estiver travada e ler o instante do celular.
 *
 * A checagem de `estaTravada()` mora aqui, e não só em
 * `AppSyncService::osEstaTravada()`, porque o caminho de resolução de
 * conflito (`AppSyncService::resolver()` -> `aplicarLadoDoAplicativo()`)
 * chama `AplicadorDeOperacao::aplicar()` direto, sem repetir a checagem do
 * caminho principal de `aplicar()`. Sem esta trava aqui, uma OS assinada
 * entre a abertura de um conflito e a resolução dele aceitaria o lado do
 * aplicativo mesmo assim.
 */
trait OperacaoDeCampo
{
    /**
     * Ordem de serviço do payload, já conferida quanto ao travamento por
     * assinatura.
     *
     * @throws ValidationException Quando a operação não traz uma ordem de
     *                             serviço válida, ou quando ela já está
     *                             travada (`os_travada`).
     */
    private function workOrderDestravada(array $payload): WorkOrder
    {
        $workOrder = $this->workOrderDoPayload($payload);

        if ($workOrder->estaTravada()) {
            throw ValidationException::withMessages([
                'work_order' => 'Esta ordem de serviço já foi assinada e não aceita mais alterações de campo.',
            ]);
        }

        return $workOrder;
    }

    /**
     * Ordem de serviço do payload, sem checagem de travamento.
     *
     * `AppSyncService::processar()` já resolveu e injetou `work_order_id` no
     * payload a partir de uma OS existente e autorizada para o usuário; esta
     * consulta só a recarrega, dentro do escopo global da empresa corrente.
     *
     * @throws ValidationException Quando a operação não informa uma ordem de
     *                             serviço, ou ela não existe mais.
     */
    private function workOrderDoPayload(array $payload): WorkOrder
    {
        $workOrderId = $payload['work_order_id'] ?? null;

        $workOrder = $workOrderId !== null ? WorkOrder::find($workOrderId) : null;

        if ($workOrder === null) {
            throw ValidationException::withMessages([
                'work_order' => 'Esta operação não está vinculada a uma ordem de serviço válida.',
            ]);
        }

        return $workOrder;
    }

    /**
     * Instante do celular em que o técnico registrou a informação.
     *
     * `AppSyncService` injeta `registrada_em` no payload (mesmo instante
     * gravado em `sync_operations.registrada_em`), tanto no caminho principal
     * de `aplicar()` quanto no valor guardado de um conflito. Sem o valor
     * (payload malformado ou aplicador chamado fora do fluxo de
     * sincronização), cai no instante do servidor: é a única saída segura,
     * nunca uma exceção por causa de um campo de apoio.
     */
    private function instanteDoCelular(array $payload): Carbon
    {
        $valor = $payload['registrada_em'] ?? null;

        if ($valor === null || $valor === '') {
            return Carbon::now();
        }

        try {
            return Carbon::parse($valor);
        } catch (Throwable) {
            return Carbon::now();
        }
    }

    /**
     * Dia, no fuso do negócio (`America/Sao_Paulo`), de um instante gravado
     * em UTC.
     *
     * Usado para preencher campos `date` (sem hora relevante, como
     * `work_order_device_events.event_date`) a partir de um instante: nunca se
     * atribui o instante direto a uma coluna `date`, porque a conversão de
     * fuso em cima de um valor sem hora é o que produz o erro clássico de "um
     * dia a menos" perto da meia-noite.
     */
    private function diaNoFusoDoNegocio(Carbon $instante): string
    {
        return $instante->clone()->setTimezone('America/Sao_Paulo')->toDateString();
    }
}
