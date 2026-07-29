<?php

namespace App\Services\Sync;

use App\Models\Device;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderDeviceEvent;
use App\Services\Sync\Concerns\OperacaoDeCampo;
use App\Support\CodigoDeDispositivo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Aplica uma operação de sincronização do tipo `evento_dispositivo` (Plano 13,
 * Task 13.2): o registro que o técnico faz em campo ao inspecionar um ponto de
 * controle (isca, armadilha, etc.), lido pelo código público da etiqueta ou
 * pelo id do dispositivo.
 *
 * Grava em `work_order_device_events` (model `WorkOrderDeviceEvent`), a
 * mesma tabela que `WorkOrderService::createWorkOrder()`/`updateWorkOrder()`
 * já povoam hoje pelo formulário web da OS, e a mesma que
 * `WorkOrderService::preparePdfData()` carrega para o documento oficial da
 * ordem de serviço. O projeto tem uma segunda tabela de nome parecido,
 * `device_events` (model `DeviceEvent`, atrás de `DeviceEventService` e da
 * tela avulsa `/device-events`), mais antiga e sem relação com o PDF da OS;
 * gravar ali deixaria o registro do técnico invisível no documento que o
 * cliente recebe, então este aplicador não a usa.
 *
 * Nenhuma tabela tinha coluna própria para o instante do celular, nem para o
 * consumo de isca e a praga encontrada que a Task 13.2 pede: as três colunas
 * (`registrada_em`, `bait_consumption_status`, `pest_found`) nasceram numa
 * migration aditiva desta mesma task.
 */
class AplicadorDeEventoDeDispositivo implements AplicadorDeOperacao
{
    use OperacaoDeCampo;

    public function tipo(): string
    {
        return 'evento_dispositivo';
    }

    public function aplicar(array $payload, User $usuario): Model
    {
        $workOrder = $this->workOrderDestravada($payload);

        $dispositivo = $this->resolverDispositivo($payload);

        $this->confirmarEnderecoDoDispositivo($dispositivo, $workOrder);

        $instante = $this->instanteDoCelular($payload);

        return WorkOrderDeviceEvent::create([
            'work_order_id' => $workOrder->id,
            'device_id' => $dispositivo->id,
            'event_type_id' => $payload['event_type_id'] ?? null,
            'event_date' => $this->diaNoFusoDoNegocio($instante),
            'event_description' => $payload['event_description'] ?? null,
            'event_observations' => $payload['event_observations'] ?? null,
            'bait_consumption_status' => $payload['bait_consumption_status'] ?? null,
            'pest_found' => $payload['pest_found'] ?? null,
            'registrada_em' => $instante,
        ]);
    }

    /**
     * Resolve o dispositivo pelo `codigo_publico` da etiqueta (preferido,
     * mesmo caminho de leitura do `DeviceScanService`) ou pelo `device_id`
     * quando o código não vier no payload.
     *
     * @throws ValidationException Quando nenhum dos dois resolve a um
     *                             dispositivo existente no escopo da empresa.
     */
    private function resolverDispositivo(array $payload): Device
    {
        $codigoPublico = trim((string) ($payload['codigo_publico'] ?? ''));

        if ($codigoPublico !== '') {
            $normalizado = CodigoDeDispositivo::normalizar($codigoPublico);

            $dispositivo = CodigoDeDispositivo::valido($normalizado)
                ? Device::query()->where('codigo_publico', $normalizado)->first()
                : null;
        } else {
            $deviceId = $payload['device_id'] ?? null;
            $dispositivo = $deviceId !== null ? Device::find($deviceId) : null;
        }

        if ($dispositivo === null) {
            throw ValidationException::withMessages([
                'device' => 'Dispositivo não encontrado. Confira o código da etiqueta ou sincronize novamente '
                    .'a carga do dia antes de tentar de novo.',
            ]);
        }

        return $dispositivo;
    }

    /**
     * O dispositivo lido pertence ao mesmo endereço da ordem de serviço?
     *
     * Recusa com mensagem própria, e não apenas um aviso: o técnico já foi
     * avisado na leitura (`DeviceScanService`, Task 11.4, situação
     * `fora_da_os`) se o dispositivo era de outro endereço, e continuar até
     * aqui significa que o registro está indo para o lugar errado.
     *
     * @throws ValidationException
     */
    private function confirmarEnderecoDoDispositivo(Device $dispositivo, WorkOrder $workOrder): void
    {
        if ((int) $dispositivo->address_id !== (int) $workOrder->address_id) {
            throw ValidationException::withMessages([
                'device' => 'Este dispositivo pertence a outro endereço e não pode ser registrado nesta ordem de serviço.',
            ]);
        }
    }
}
