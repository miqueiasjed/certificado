<?php

namespace App\Services;

use App\Exceptions\CompromissoNaoPromovelException;
use App\Models\Compromisso;
use App\Models\WorkOrder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo de vida do compromisso avulso: criar, editar, cancelar, concluir e,
 * quando fizer sentido, promover para uma ordem de serviço de verdade (Plano
 * 30, Task 30.2).
 *
 * ## `criado_por` nunca vem de `Auth::` daqui de dentro
 *
 * Mesmo critério de `PpeDeliveryService`/`PpeDeliveryController`: quem
 * resolve o usuário autenticado é quem chama o Service (`Auth::id()` no
 * controller), porque este Service também precisa rodar fora de requisição
 * HTTP (comando artisan futuro, job). `criar()` recebe `$dados['criado_por']`
 * já pronto, nunca o resolve sozinho.
 *
 * ## Promoção é sempre opcional, e depois dela são dois registros
 *
 * A maior parte dos compromissos nunca vira OS, e isso é o estado normal, não
 * uma pendência (ver o cabeçalho de `App\Models\Compromisso`). A partir do
 * momento em que `promoverParaOs()` grava `work_order_id`, compromisso e OS
 * passam a ser dois registros independentes: `atualizar()` nunca toca a OS
 * gerada, e a promoção nunca apaga nem sobrescreve outro campo do compromisso
 * além de `work_order_id`.
 *
 * ## O precedente direto: `AppointmentRequestService::confirmar()`
 *
 * A promoção segue o mesmo desenho de "entidade nullable de OS que promove
 * para uma OS de verdade": trava a linha com `lockForUpdate()` dentro de uma
 * transação antes de checar se ainda pode ser promovida (duas pessoas
 * promovendo o mesmo compromisso ao mesmo tempo é a mesma corrida que o
 * cabeçalho de `AppointmentRequestService` documenta para o pedido de
 * horário), monta os dados da OS a partir do próprio registro mesclados com o
 * que falta, e chama `WorkOrderService::createWorkOrder()` - a única chamada
 * a esse método em todo este Service.
 */
class CompromissoService
{
    /**
     * Origem gravada na OS criada pela promoção, para diferenciar das outras
     * origens já usadas na mesma coluna (`work_orders.origem`, string de até
     * 20 caracteres): `'contrato'` (Plano 9) e `'agendamento_online'` (Plano
     * 16).
     */
    public const ORIGEM_DA_OS = 'compromisso';

    /**
     * Campos que `atualizar()` pode alterar.
     *
     * De propósito, ficam fora desta lista, mesmo sendo `$fillable` no
     * model:
     * - `criado_por`: imutável depois de criado, mesmo critério de
     *   `PpeDelivery::user_id`.
     * - `work_order_id`: só `promoverParaOs()` grava, e só uma vez.
     * - `situacao`: só muda por `cancelar()` ou `concluir()`, nunca por uma
     *   edição solta de campos que poderia esconder uma mudança de estado
     *   dentro de uma atualização genérica.
     *
     * @var array<int, string>
     */
    private const CAMPOS_EDITAVEIS = [
        'tipo',
        'titulo',
        'observacoes',
        'client_id',
        'address_id',
        'endereco_texto',
        'technician_id',
        'data',
        'hora_inicio',
        'hora_fim',
    ];

    public function __construct(
        private readonly WorkOrderService $workOrders,
    ) {}

    /**
     * Cria o compromisso.
     *
     * `$dados['criado_por']` já vem resolvido por quem chama (ver o
     * cabeçalho da classe). `work_order_id` nunca nasce preenchido: a única
     * porta de promoção é `promoverParaOs()`.
     *
     * `situacao` sai explícita em `'agendado'` quando `$dados` não a informa.
     * A coluna já tem esse valor como default no banco, mas um default de
     * banco não volta para o objeto Eloquent recém-criado sem um `fresh()` a
     * mais; deixar explícito aqui evita devolver um `Compromisso` com
     * `situacao` vazia para quem acabou de criar o registro (a resposta
     * imediata do controller, por exemplo).
     *
     * @param  array<string, mixed>  $dados
     */
    public function criar(array $dados): Compromisso
    {
        $dados = array_merge(['situacao' => 'agendado'], Arr::except($dados, ['work_order_id']));

        return Compromisso::create($dados);
    }

    /**
     * Atualiza os campos editáveis do compromisso.
     *
     * Compromisso já promovido continua editável: editar o compromisso não
     * mexe na OS gerada, são dois registros independentes a partir da
     * promoção (ver o cabeçalho da classe e o de `App\Models\Compromisso`).
     *
     * @param  array<string, mixed>  $dados
     */
    public function atualizar(Compromisso $compromisso, array $dados): Compromisso
    {
        $compromisso->update(Arr::only($dados, self::CAMPOS_EDITAVEIS));

        return $compromisso;
    }

    /**
     * Cancela o compromisso.
     *
     * Cancelar um compromisso já concluído é permitido: cancelada nunca
     * some, só muda de situação, mesmo espírito já usado em outras partes do
     * domínio. Nenhum bloqueio extra que a Task 30.2 não pediu.
     */
    public function cancelar(Compromisso $compromisso): Compromisso
    {
        $compromisso->update(['situacao' => 'cancelado']);

        return $compromisso;
    }

    /**
     * Conclui o compromisso.
     */
    public function concluir(Compromisso $compromisso): Compromisso
    {
        $compromisso->update(['situacao' => 'concluido']);

        return $compromisso;
    }

    /**
     * Promove o compromisso para uma ordem de serviço de verdade.
     *
     * Monta os dados da OS a partir do próprio compromisso (cliente,
     * endereço, técnico, data) mesclados com `$dadosAdicionais` - o que falta
     * para uma OS válida e o compromisso não tem (ex.: `service_id`).
     * `$dadosAdicionais` vence em caso de conflito, mesmo critério de
     * `AppointmentRequestService::confirmar()`: quem promove pode escolher
     * técnico, data ou cliente diferentes dos que o compromisso guarda,
     * contanto que informe explicitamente.
     *
     * Depois de criada a OS, grava `work_order_id` no compromisso sem tocar
     * em nenhum outro campo: os próprios dados do compromisso (tipo, título,
     * observações, cliente, endereço, técnico, data) continuam intactos.
     *
     * @param  array<string, mixed>  $dadosAdicionais
     *
     * @throws CompromissoNaoPromovelException Compromisso já promovido ou cancelado.
     */
    public function promoverParaOs(Compromisso $compromisso, array $dadosAdicionais = []): WorkOrder
    {
        return DB::transaction(function () use ($compromisso, $dadosAdicionais): WorkOrder {
            $atual = $this->travarCompromisso($compromisso);

            $this->garantirPromovel($atual);

            $workOrder = $this->workOrders->createWorkOrder(
                $this->dadosDaOs($atual, $dadosAdicionais)
            );

            $atual->update(['work_order_id' => $workOrder->id]);

            return $workOrder;
        });
    }

    // -----------------------------------------------------------------
    // promoverParaOs(): passos internos
    // -----------------------------------------------------------------

    /**
     * Relê o compromisso sob `lockForUpdate()`, dentro da transação
     * corrente. Duas pessoas promovendo o mesmo compromisso ao mesmo tempo
     * não podem gerar duas ordens de serviço para o mesmo registro; mesmo
     * cuidado de `AppointmentRequestService::travarPedido()`.
     */
    private function travarCompromisso(Compromisso $compromisso): Compromisso
    {
        return Compromisso::query()
            ->whereKey($compromisso->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * @throws CompromissoNaoPromovelException
     */
    private function garantirPromovel(Compromisso $compromisso): void
    {
        if (filled($compromisso->work_order_id)) {
            throw CompromissoNaoPromovelException::jaPromovido($compromisso);
        }

        if ($compromisso->situacao === 'cancelado') {
            throw CompromissoNaoPromovelException::cancelado($compromisso);
        }
    }

    /**
     * @param  array<string, mixed>  $dadosAdicionais
     * @return array<string, mixed>
     */
    private function dadosDaOs(Compromisso $compromisso, array $dadosAdicionais): array
    {
        $dados = array_merge([
            'client_id' => $compromisso->client_id,
            'address_id' => $compromisso->address_id,
            'technician_id' => $compromisso->technician_id,
            'scheduled_date' => $compromisso->data?->toDateString(),
            'status' => 'scheduled',
            'priority_level' => 'medium',
            'origem' => self::ORIGEM_DA_OS,
            'description' => $this->descricaoDaOs($compromisso),
        ], $dadosAdicionais);

        if (! array_key_exists('technicians', $dados) && filled($dados['technician_id'] ?? null)) {
            $dados['technicians'] = [$dados['technician_id']];
        }

        if (! array_key_exists('order_number', $dados)) {
            $dados['order_number'] = $this->workOrders->generateOrderNumber();
        }

        return $dados;
    }

    private function descricaoDaOs(Compromisso $compromisso): string
    {
        $partes = [
            sprintf('Gerada a partir do compromisso #%d ("%s").', $compromisso->id, $compromisso->titulo),
        ];

        if (filled($compromisso->observacoes)) {
            $partes[] = "Observações do compromisso: {$compromisso->observacoes}";
        }

        return implode(' ', $partes);
    }
}
