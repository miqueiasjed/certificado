<?php

namespace App\Services;

use App\Exceptions\PedidoDeHorarioJaRespondidoException;
use App\Models\Address;
use App\Models\AppointmentRequest;
use App\Models\Client;
use App\Models\Company;
use App\Models\Technician;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\BusinessDate;
use App\Support\EventosDeNotificacao;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Resposta da empresa a um pedido de horário do agendamento online (Plano
 * 16, Task 16.4): confirmar (gera ordem de serviço) ou recusar (com motivo),
 * sempre avisando o solicitante.
 *
 * ## A disponibilidade é reconferida na confirmação, nunca reaproveitada
 *
 * Entre o pedido e a resposta da empresa a agenda muda: outro pedido pode ter
 * sido confirmado, ou uma OS pode ter sido criada por fora deste fluxo.
 * `confirmar()` chama `AvailabilityService::podeAceitar()` de novo, na hora,
 * e nunca confia no que a grade mostrou quando o pedido foi aberto (Task
 * 16.3). Período que lotou nesse meio-tempo joga `RuntimeException`.
 *
 * ## Criar cliente é decisão da empresa, nunca efeito automático do pedido
 *
 * O pedido pode vir de quem ainda não é cliente cadastrado (página pública,
 * Task 16.3). `confirmar()` só cria `Client`/`Address` quando o pedido não
 * chega com `client_id` e a empresa manda os dados em `$dados['cliente']`/
 * `$dados['endereco']` explicitamente. Nenhum cadastro nasce sozinho: sem
 * esses dados, e sem cliente já vinculado, a confirmação recusa com
 * `InvalidArgumentException` em vez de adivinhar.
 *
 * ## Pedido já respondido: checagem atômica, não "olha e depois grava"
 *
 * `confirmar()` e `recusar()` leem o pedido de novo com `lockForUpdate()`
 * dentro de uma transação antes de checar a situação. Dois atendentes abrindo
 * o mesmo pedido ao mesmo tempo é o caso concreto que a Task 16.4 cita: sem o
 * lock, as duas requisições poderiam passar pela checagem de "está pendente"
 * antes de qualquer uma delas gravar, e o pedido acabaria confirmado e
 * recusado ao mesmo tempo (ou duas OS para o mesmo pedido). Com o lock, a
 * segunda requisição espera a primeira terminar e enxerga a situação já
 * gravada por ela, resultando em `PedidoDeHorarioJaRespondidoException` com a
 * situação real, não a que a tela do segundo atendente ainda mostrava.
 *
 * ## A quem a resposta é avisada
 *
 * Sempre ao contato que a pessoa informou *neste* pedido (`email`/`telefone`
 * de `AppointmentRequest`), nunca ao e-mail geral de notificação do cadastro
 * do cliente: quem preenche o formulário público pode não ser a mesma pessoa
 * do cadastro, e a recusa em especial pode não ter cliente nenhum vinculado.
 * Ver o cabeçalho dos dois eventos em `EventosDeNotificacao`.
 */
class AppointmentRequestService
{
    private const ITENS_POR_PAGINA = 20;

    /**
     * Origem gravada na OS criada por este Service, para a empresa medir
     * quanto o agendamento online traz (Task 16.4). Cabe nos 20 caracteres de
     * `work_orders.origem` (mesma coluna que já guarda `'contrato'` para
     * visita gerada pelo Plano 9) e não colide com nenhum valor existente.
     */
    public const ORIGEM_DA_OS = 'agendamento_online';

    /**
     * Motivos prontos que a tela de resposta oferece antes do texto livre
     * (Task 16.4: "sem disponibilidade na data", "fora da área de
     * atendimento", "outro"). Puramente sugestão de interface: o texto final
     * chega em `recusar()` como string livre, nunca validado contra esta
     * lista - "outro" já é, por definição, qualquer coisa que não está aqui.
     *
     * @var array<int, string>
     */
    public const MOTIVOS_PRONTOS_DE_RECUSA = [
        'Sem disponibilidade na data e período pedidos.',
        'Fora da área de atendimento.',
    ];

    public function __construct(
        private readonly AvailabilityService $disponibilidade,
        private readonly WorkOrderService $workOrders,
        private readonly ClientService $clientes,
        private readonly AddressService $enderecos,
        private readonly NotificationService $notificacoes,
    ) {}

    /**
     * Pedidos da empresa corrente, para o painel administrativo, com filtro
     * opcional por situação. Sem escopo por técnico ou por cliente: qualquer
     * funcionário com a permissão vê e responde qualquer pedido da própria
     * empresa (o escopo global de `AppointmentRequest` já resolve o
     * isolamento entre empresas), mesmo critério de
     * `ClientRequestService::listarParaEmpresa()`.
     */
    public function listarParaEmpresa(?string $situacao = null): LengthAwarePaginator
    {
        $consulta = AppointmentRequest::query()
            ->with(['client', 'address', 'serviceType', 'workOrder.technician', 'respondidaPor'])
            ->latest();

        if (filled($situacao)) {
            $consulta->where('situacao', $situacao);
        }

        return $consulta->paginate(self::ITENS_POR_PAGINA)->withQueryString();
    }

    /**
     * Quantidade de pedidos pendentes da empresa corrente, para o contador do
     * menu do painel (`HandleInertiaRequests`), mesmo padrão de
     * `ClientRequestService::contarAbertasDaEmpresa()`.
     */
    public function contarPendentesDaEmpresa(): int
    {
        return AppointmentRequest::query()
            ->where('situacao', AppointmentRequest::SITUACAO_PENDENTE)
            ->count();
    }

    /**
     * Confirma o pedido: reconfere a disponibilidade, resolve (ou cria)
     * cliente e endereço, cria a ordem de serviço e avisa o solicitante.
     *
     * `$dados` aceita:
     * - `technician_id` (obrigatório): técnico escolhido pela empresa.
     * - `data`, `periodo` (opcionais): quando ausentes, valem os do pedido
     *   (`data_preferida`/`periodo`). A empresa pode confirmar em data ou
     *   período diferentes do pedido original, desde que haja vaga.
     * - `client_id` (opcional): cliente já cadastrado escolhido pela empresa,
     *   acima do que o pedido já trouxer vinculado.
     * - `cliente` (opcional, `['name' => ..., 'email' => ..., 'phone' => ...]`):
     *   dados para cadastrar um cliente novo, usados só quando nem o pedido
     *   nem `client_id` resolvem um cliente existente.
     * - `address_id` (opcional): endereço já cadastrado do cliente resolvido.
     * - `endereco` (opcional, `['street' => ..., 'number' => ...,
     *   'district' => ..., 'city' => ..., 'state' => ..., 'zip' => ...]`):
     *   dados para cadastrar um endereço novo, usados só quando nem o pedido
     *   nem `address_id` resolvem um endereço existente do cliente.
     * - `observacoes` (opcional): texto livre acrescentado à descrição da OS.
     *
     * @param  array<string, mixed>  $dados
     *
     * @throws PedidoDeHorarioJaRespondidoException Pedido que não está mais pendente.
     * @throws InvalidArgumentException Técnico inválido, ou cliente/endereço não resolvíveis.
     * @throws RuntimeException Período sem disponibilidade no momento da confirmação.
     */
    public function confirmar(AppointmentRequest $pedido, array $dados, User $funcionario): AppointmentRequest
    {
        return DB::transaction(function () use ($pedido, $dados, $funcionario): AppointmentRequest {
            $pedidoAtual = $this->travarPedido($pedido);

            $this->garantirPendente($pedidoAtual);

            $empresa = Company::current();
            $data = $this->dataEscolhida($pedidoAtual, $dados);
            $periodo = $this->periodoEscolhido($pedidoAtual, $dados);

            if (! $this->disponibilidade->podeAceitar($empresa, $data, $periodo)) {
                throw new RuntimeException(
                    'Não há mais disponibilidade para essa data e período. A agenda mudou desde o pedido: '
                    .'escolha outra data, período ou técnico.'
                );
            }

            $technicianId = $this->tecnicoValido($dados);
            $clientId = $this->resolverOuCriarCliente($pedidoAtual, $dados);
            $addressId = $this->resolverOuCriarEndereco($pedidoAtual, $dados, $clientId);

            $workOrder = $this->workOrders->createWorkOrder([
                'client_id' => $clientId,
                'address_id' => $addressId,
                'technician_id' => $technicianId,
                'technicians' => [$technicianId],
                'order_number' => $this->workOrders->generateOrderNumber(),
                'scheduled_date' => $data,
                'status' => 'scheduled',
                'priority_level' => 'medium',
                'origem' => self::ORIGEM_DA_OS,
                'description' => $this->descricaoDaOs($pedidoAtual, $dados),
            ]);

            $pedidoAtual->update([
                'situacao' => AppointmentRequest::SITUACAO_CONFIRMADA,
                'work_order_id' => $workOrder->id,
                'client_id' => $clientId,
                'address_id' => $addressId,
                'respondida_por' => $funcionario->getKey(),
                'respondida_em' => now(),
            ]);

            $pedidoAtual = $pedidoAtual->fresh(['client', 'address']);

            $this->avisarConfirmacao($pedidoAtual, $workOrder, $periodo);

            return $pedidoAtual;
        });
    }

    /**
     * Recusa o pedido com motivo e avisa o solicitante. Nunca cria cliente
     * nem endereço: a recusa é o caminho de quem a empresa decidiu não
     * atender, ao contrário da confirmação.
     *
     * @throws PedidoDeHorarioJaRespondidoException Pedido que não está mais pendente.
     * @throws InvalidArgumentException Motivo vazio.
     */
    public function recusar(AppointmentRequest $pedido, string $motivo, User $funcionario): AppointmentRequest
    {
        $motivo = trim($motivo);

        if ($motivo === '') {
            throw new InvalidArgumentException('Informe o motivo da recusa.');
        }

        return DB::transaction(function () use ($pedido, $motivo, $funcionario): AppointmentRequest {
            $pedidoAtual = $this->travarPedido($pedido);

            $this->garantirPendente($pedidoAtual);

            $pedidoAtual->update([
                'situacao' => AppointmentRequest::SITUACAO_RECUSADA,
                'motivo_recusa' => $motivo,
                'respondida_por' => $funcionario->getKey(),
                'respondida_em' => now(),
            ]);

            $pedidoAtual = $pedidoAtual->fresh();

            $this->avisarRecusa($pedidoAtual);

            return $pedidoAtual;
        });
    }

    // -----------------------------------------------------------------
    // Situação e trava
    // -----------------------------------------------------------------

    /**
     * Relê o pedido sob `lockForUpdate()`, dentro da transação corrente. Ver
     * o cabeçalho da classe para o motivo de não confiar no `$pedido` que o
     * controller passou (route model binding aconteceu antes da transação e
     * antes do lock).
     */
    private function travarPedido(AppointmentRequest $pedido): AppointmentRequest
    {
        return AppointmentRequest::query()
            ->whereKey($pedido->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * @throws PedidoDeHorarioJaRespondidoException
     */
    private function garantirPendente(AppointmentRequest $pedido): void
    {
        if ($pedido->situacao !== AppointmentRequest::SITUACAO_PENDENTE) {
            throw PedidoDeHorarioJaRespondidoException::paraPedido($pedido);
        }
    }

    // -----------------------------------------------------------------
    // Data, período e técnico da confirmação
    // -----------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $dados
     */
    private function dataEscolhida(AppointmentRequest $pedido, array $dados): string
    {
        $data = $dados['data'] ?? null;

        if (blank($data)) {
            return $pedido->data_preferida->toDateString();
        }

        $dia = BusinessDate::paraFusoNegocio((string) $data);

        if ($dia === null) {
            throw new InvalidArgumentException('Data inválida para a confirmação.');
        }

        return $dia->toDateString();
    }

    /**
     * @param  array<string, mixed>  $dados
     */
    private function periodoEscolhido(AppointmentRequest $pedido, array $dados): string
    {
        $periodo = $dados['periodo'] ?? null;

        if (blank($periodo)) {
            return $pedido->periodo;
        }

        if (! in_array($periodo, AppointmentRequest::PERIODOS, true)) {
            throw new InvalidArgumentException(
                "Período inválido: \"{$periodo}\". Use \"".AppointmentRequest::PERIODO_MANHA.'" ou "'
                .AppointmentRequest::PERIODO_TARDE.'".'
            );
        }

        return $periodo;
    }

    /**
     * @param  array<string, mixed>  $dados
     */
    private function tecnicoValido(array $dados): int
    {
        $id = $dados['technician_id'] ?? null;

        if (blank($id)) {
            throw new InvalidArgumentException('Selecione o técnico responsável pelo atendimento.');
        }

        // Escopado pelo escopo global de `Technician` (`BelongsToCompany`):
        // id de outra empresa simplesmente não existe para esta consulta.
        $tecnico = Technician::query()->active()->find((int) $id);

        if ($tecnico === null) {
            throw new InvalidArgumentException('O técnico selecionado não existe ou está inativo.');
        }

        return (int) $tecnico->id;
    }

    // -----------------------------------------------------------------
    // Cliente e endereço da confirmação
    // -----------------------------------------------------------------

    /**
     * Resolve o cliente da OS, nesta ordem: `client_id` escolhido pela
     * empresa neste ato, cliente já vinculado ao pedido, ou um cliente novo
     * cadastrado a partir de `$dados['cliente']`.
     *
     * @param  array<string, mixed>  $dados
     *
     * @throws InvalidArgumentException `client_id` de outra empresa/inexistente, ou nenhum cliente resolvível.
     */
    private function resolverOuCriarCliente(AppointmentRequest $pedido, array $dados): int
    {
        $clientId = $dados['client_id'] ?? null;

        if (filled($clientId)) {
            $cliente = Client::query()->find((int) $clientId);

            if ($cliente === null) {
                throw new InvalidArgumentException('O cliente selecionado não existe.');
            }

            return (int) $cliente->id;
        }

        if (filled($pedido->client_id)) {
            return (int) $pedido->client_id;
        }

        $dadosCliente = $dados['cliente'] ?? null;

        if (! is_array($dadosCliente) || blank($dadosCliente['name'] ?? null)) {
            throw new InvalidArgumentException(
                'Este pedido não está vinculado a um cliente cadastrado. Informe os dados do cliente '
                .'("cliente.name") para cadastrá-lo ao confirmar, ou escolha um cliente existente.'
            );
        }

        $cliente = $this->clientes->createClient([
            'name' => trim((string) $dadosCliente['name']),
            'email' => filled($dadosCliente['email'] ?? null)
                ? trim((string) $dadosCliente['email'])
                : ($pedido->email ?: null),
            'phone' => filled($dadosCliente['phone'] ?? null)
                ? trim((string) $dadosCliente['phone'])
                : $pedido->telefone,
            // `cnpj` é obrigatório na tabela (sem unique), e o formulário
            // público (Task 16.3) não coleta documento nenhum: vazio é o
            // valor de quem ainda não informou, corrigível depois pela tela
            // de edição de cliente, mesmo padrão do endereço sem apelido.
            'cnpj' => filled($dadosCliente['cnpj'] ?? null) ? trim((string) $dadosCliente['cnpj']) : '',
        ]);

        return (int) $cliente->id;
    }

    /**
     * Resolve o endereço da OS, nesta ordem: `address_id` escolhido pela
     * empresa (precisa pertencer ao cliente resolvido), endereço já vinculado
     * ao pedido (mesma checagem), ou um endereço novo cadastrado a partir de
     * `$dados['endereco']`.
     *
     * @param  array<string, mixed>  $dados
     *
     * @throws InvalidArgumentException `address_id` que não pertence ao cliente, ou nenhum endereço resolvível.
     */
    private function resolverOuCriarEndereco(AppointmentRequest $pedido, array $dados, int $clientId): int
    {
        $addressId = $dados['address_id'] ?? null;

        if (filled($addressId)) {
            $endereco = Address::query()->where('client_id', $clientId)->find((int) $addressId);

            if ($endereco === null) {
                throw new InvalidArgumentException('O endereço selecionado não pertence a este cliente.');
            }

            return (int) $endereco->id;
        }

        if (filled($pedido->address_id)) {
            $endereco = Address::query()->where('client_id', $clientId)->find((int) $pedido->address_id);

            if ($endereco !== null) {
                return (int) $endereco->id;
            }
        }

        $dadosEndereco = $dados['endereco'] ?? null;

        if (! is_array($dadosEndereco) || blank($dadosEndereco['street'] ?? null)) {
            throw new InvalidArgumentException(
                'Este pedido não tem um endereço cadastrado. Informe o endereço ("endereco.street") para '
                .'cadastrá-lo ao confirmar, ou escolha um endereço existente do cliente.'
            );
        }

        $endereco = $this->enderecos->createAddress([
            'client_id' => $clientId,
            // `nickname` é obrigatório na tabela e não faz parte do
            // formulário público (Task 16.3): sem apelido escolhido pela
            // empresa, "Principal" é o mesmo padrão que a tela de cadastro
            // manual de endereço já sugere para o primeiro endereço do
            // cliente.
            'nickname' => filled($dadosEndereco['nickname'] ?? null)
                ? trim((string) $dadosEndereco['nickname'])
                : 'Principal',
            'street' => trim((string) $dadosEndereco['street']),
            'number' => trim((string) ($dadosEndereco['number'] ?? '')),
            'district' => trim((string) ($dadosEndereco['district'] ?? '')),
            'city' => trim((string) ($dadosEndereco['city'] ?? '')),
            'state' => trim((string) ($dadosEndereco['state'] ?? '')),
            'zip' => trim((string) ($dadosEndereco['zip'] ?? '')),
            'active' => true,
        ]);

        return (int) $endereco->id;
    }

    /**
     * @param  array<string, mixed>  $dados
     */
    private function descricaoDaOs(AppointmentRequest $pedido, array $dados): string
    {
        $partes = [
            "Gerada a partir do pedido de horário #{$pedido->id}, feito por {$pedido->nome}.",
        ];

        if (filled($pedido->observacao)) {
            $partes[] = "Observação do solicitante: {$pedido->observacao}";
        }

        $observacoes = $dados['observacoes'] ?? null;

        if (filled($observacoes)) {
            $partes[] = trim((string) $observacoes);
        }

        return implode(' ', $partes);
    }

    // -----------------------------------------------------------------
    // Avisos ao solicitante
    // -----------------------------------------------------------------

    private function avisarConfirmacao(AppointmentRequest $pedido, WorkOrder $workOrder, string $periodo): void
    {
        $this->notificacoes->enfileirar(EventosDeNotificacao::SOLICITACAO_HORARIO_CONFIRMADA, $pedido, [
            'canal' => EventosDeNotificacao::CANAL_EMAIL,
            'destino' => $pedido->email,
            'variaveis' => [
                'solicitante_nome' => $pedido->nome,
                'data_confirmada' => BusinessDate::paraFusoNegocio($workOrder->scheduled_date)?->format('d/m/Y'),
                'periodo_confirmado' => PublicSchedulingService::ROTULOS_DE_PERIODO[$periodo] ?? $periodo,
                'endereco' => $pedido->address?->full_address,
                'tecnico_nome' => $workOrder->technician?->name,
                'os_numero' => $workOrder->order_number,
            ],
        ]);
    }

    private function avisarRecusa(AppointmentRequest $pedido): void
    {
        $this->notificacoes->enfileirar(EventosDeNotificacao::SOLICITACAO_HORARIO_RECUSADA, $pedido, [
            'canal' => EventosDeNotificacao::CANAL_EMAIL,
            'destino' => $pedido->email,
            'variaveis' => [
                'solicitante_nome' => $pedido->nome,
                'data_preferida' => BusinessDate::paraFusoNegocio($pedido->data_preferida)?->format('d/m/Y'),
                'periodo' => PublicSchedulingService::ROTULOS_DE_PERIODO[$pedido->periodo] ?? $pedido->periodo,
                'motivo_recusa' => $pedido->motivo_recusa,
            ],
        ]);
    }
}
