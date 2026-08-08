<?php

namespace App\Services\Sync;

use App\Models\Room;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Compliance\ConformidadeDaExecucaoService;
use App\Services\PestSightingService;
use App\Services\Sync\Concerns\OperacaoDeCampo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Aplica uma operação de sincronização do tipo `avistamento` (Plano 13, Task
 * 13.2): o avistamento de praga que o técnico registra em campo, por cômodo.
 *
 * A gravação em si é delegada a `PestSightingService::createPestSighting()`
 * (regra atual de avistamento), sem repetir nenhuma validação de domínio dela
 * aqui. O que este aplicador acrescenta é só o que é próprio do caminho de
 * sincronização: resolver o endereço e o cômodo a partir da ordem de serviço
 * (o técnico não escolhe um endereço à parte, é sempre o da OS que está
 * executando) e o instante do celular.
 *
 * `pest_sightings` guarda o avistamento por endereço (`address_id`), sem
 * coluna de cômodo própria. Quando o payload informa `room_id`, o nome do
 * cômodo entra em `location_description` (conferido antes contra o endereço
 * da OS, para um cômodo de outro endereço nunca aparecer como se fosse deste
 * atendimento); sem `room_id`, `location_description` vem direto do payload.
 *
 * Por que a baixa de estoque não é chamada aqui (Plano 17, Task 17.4)
 * -------------------------------------------------------------------
 * Este aplicador roda a cada avistamento registrado, várias vezes na mesma
 * visita, e o técnico ainda vai corrigir a quantidade aplicada antes de
 * fechar. Baixar estoque aqui geraria uma dezena de movimentos de saída e de
 * correção por OS, e o extrato do razão viraria ruído justamente para quem
 * precisa auditá-lo. `applied_product` e `applied_product_quantity` desta
 * tabela também são texto livre, sem vínculo com `products`: não dá para
 * saber de que produto do catálogo se trata.
 *
 * A baixa acontece no fechamento da OS, pelo pivot `work_order_product` (que
 * tem `product_id` de verdade e quantidade estabilizada), em duas portas
 * possíveis: `WorkOrderService::markAsCompleted()` (aplicativo do técnico, via
 * `AplicadorDeExecucao`, ação `concluir`) e `WorkOrderService::updateWorkOrder()`
 * (edição da OS pelo painel, quando o formulário marca `status = completed`).
 * As duas chamam o mesmo `baixarEstoqueDaOs()`, sem duplicar a regra.
 */
class AplicadorDeAvistamento implements AplicadorDeOperacao, AplicadorQueAvisa
{
    use OperacaoDeCampo;

    /**
     * Mesmo default do formulário web (`WorkOrderAdequationRequest` não se
     * aplica aqui, mas o mesmo raciocínio vale): o aplicativo do técnico nem
     * sempre expõe um seletor de severidade, e a ausência do campo não pode
     * impedir o registro do avistamento em si.
     */
    private const SEVERIDADE_PADRAO = 'medium';

    public function __construct(
        private readonly PestSightingService $pestSightingService,
        private readonly ConformidadeDaExecucaoService $conformidade,
    ) {}

    /**
     * Avisos da última chamada a `aplicar()`, zerados no começo de cada uma.
     *
     * @var array<int, array{item: string, rotulo: string, detalhe: string, exigencia: string}>
     */
    private array $avisos = [];

    public function tipo(): string
    {
        return 'avistamento';
    }

    /**
     * @return array<int, array{item: string, rotulo: string, detalhe: string, exigencia: string}>
     */
    public function avisosDaUltimaAplicacao(): array
    {
        return $this->avisos;
    }

    public function aplicar(array $payload, User $usuario): Model
    {
        $this->avisos = [];

        $workOrder = $this->workOrderDestravada($payload);

        $pestType = trim((string) ($payload['pest_type'] ?? ''));

        if ($pestType === '') {
            throw ValidationException::withMessages([
                'pest_type' => 'O tipo de praga é obrigatório para registrar o avistamento.',
            ]);
        }

        $localizacao = $this->localizacaoDoAvistamento($payload, $workOrder);
        $instante = $this->instanteDoCelular($payload);

        $dados = [
            'address_id' => $workOrder->address_id,
            'work_order_id' => $workOrder->id,
            'sighting_date' => $instante,
            'pest_type' => $pestType,
            'severity_level' => $payload['severity_level'] ?? self::SEVERIDADE_PADRAO,
            'location_description' => $localizacao,
            'description' => $payload['description'] ?? null,
            'observations' => $payload['observations'] ?? null,
            'environmental_conditions' => $payload['environmental_conditions'] ?? null,
            'control_measures_applied' => $payload['control_measures_applied'] ?? null,
            'technician_notes' => $payload['technician_notes'] ?? null,
            'estimated_quantity' => $payload['estimated_quantity'] ?? null,
            'applied_product' => $payload['applied_product'] ?? null,
            'applied_product_quantity' => $payload['applied_product_quantity'] ?? null,
            'registrada_em' => $instante,
        ];

        $avistamento = $this->pestSightingService->createPestSighting($dados);

        $this->avisarSeORegistroEstiverIrregular($payload, $instante);

        return $avistamento;
    }

    /**
     * Avisa quando o produto aplicado está com registro vencido ou cancelado
     * na Anvisa (Plano 24, Task 24.4).
     *
     * **Depois** de gravar, e nunca antes: o aviso não pode impedir o registro
     * de um serviço que já aconteceu no mundo real. O plano é explícito —
     * aviso primeiro, bloqueio depois, e só com o cliente ciente. Se o aviso
     * bloqueasse, o técnico ficaria com a visita executada e sem como
     * registrá-la, que é o pior desfecho possível.
     *
     * A data comparada é `$instante`, o momento do celular em que o técnico
     * registrou a aplicação, e nunca o momento em que o aparelho conseguiu
     * sincronizar. Um técnico que aplica na sexta e sincroniza na segunda não
     * pode ver a aplicação julgada pela segunda-feira. Mesma regra do lote
     * vencido do Plano 17.
     *
     * `applied_product` é texto livre, sem vínculo com `products`; o casamento
     * pelo nome e o silêncio quando ele não casa estão explicados em
     * `ConformidadeDaExecucaoService::avisoDoProdutoPorNome()`.
     */
    private function avisarSeORegistroEstiverIrregular(array $payload, mixed $instante): void
    {
        $aviso = $this->conformidade->avisoDoProdutoPorNome(
            $payload['applied_product'] ?? null,
            $instante
        );

        if ($aviso !== null) {
            $this->avisos[] = $aviso;
        }
    }

    /**
     * @throws ValidationException Quando o payload não informa cômodo nem
     *                             descrição de localização, ou quando o
     *                             cômodo informado pertence a outro endereço.
     */
    private function localizacaoDoAvistamento(array $payload, WorkOrder $workOrder): string
    {
        $roomId = $payload['room_id'] ?? null;
        $descricaoInformada = trim((string) ($payload['location_description'] ?? ''));

        if ($roomId === null) {
            if ($descricaoInformada === '') {
                throw ValidationException::withMessages([
                    'location_description' => 'Informe o cômodo ou a descrição da localização do avistamento.',
                ]);
            }

            return $descricaoInformada;
        }

        $comodo = Room::find($roomId);

        if ($comodo === null || (int) $comodo->address_id !== (int) $workOrder->address_id) {
            throw ValidationException::withMessages([
                'room_id' => 'Este cômodo pertence a outro endereço e não pode ser usado nesta ordem de serviço.',
            ]);
        }

        return $descricaoInformada !== '' ? $descricaoInformada : (string) $comodo->name;
    }
}
