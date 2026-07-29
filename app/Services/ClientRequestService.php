<?php

namespace App\Services;

use App\Models\Address;
use App\Models\ClientRequest;
use App\Models\ClientUser;
use App\Models\User;
use App\Support\EventosDeNotificacao;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use InvalidArgumentException;
use RuntimeException;

/**
 * Solicitação de atendimento aberta pelo cliente no portal (Plano 15, Task
 * 15.5): abrir, responder, mudar situação e cancelar, mais as duas consultas
 * do lado do portal.
 *
 * ## Prioridade nunca vem do cliente
 *
 * `abrir()` não aceita `prioridade` em `$dados`, nem se o formulário mandar o
 * campo: toda solicitação nasce `ClientRequest::PRIORIDADE_NORMAL`, e quem
 * decide se ela é mais urgente é a empresa (`mudarSituacao()`/painel), nunca
 * quem abre o pedido. Todo pedido chegaria "alta" e a fila perderia o
 * sentido, exatamente o motivo registrado na Task 15.5.
 *
 * ## Solicitação não vira OS
 *
 * Nenhum método aqui cria `WorkOrder`. Quem decide agendar é a empresa, com
 * base na própria agenda de técnicos - a mesma fronteira do Plano 16.
 *
 * ## Escopo duplo nas consultas do portal
 *
 * `minhas()` e `minha()` filtram por `company_id` **e** por `client_id`,
 * mesmo com o escopo global de `ClientRequest` (`BelongsToCompany`) já ativo:
 * mesmo raciocínio de `PortalService` (Task 15.3), porque o escopo global
 * separa empresas, não dois clientes da mesma empresa. `minha()` lança
 * `ModelNotFoundException` (404, nunca 403) para solicitação inexistente ou
 * de outro cliente - dizer "existe mas não é sua" já vazaria a existência do
 * pedido de outro cliente.
 *
 * As consultas do painel administrativo (`listarParaEmpresa()`) não escopam
 * por cliente: quem lista ali é funcionário da empresa, e qualquer
 * solicitação da própria empresa é dele para ver e responder.
 *
 * ## Teto de 5 solicitações abertas por cliente
 *
 * Conta `situacao` em `aberta`/`em_atendimento` (as duas situações "em
 * aberto", ver `ClientRequest::SITUACOES`) por `client_id`. Ao tentar abrir a
 * sexta, `abrir()` lança `RuntimeException` com mensagem para o cliente
 * acompanhar as existentes, em vez de abrir mais uma.
 */
class ClientRequestService
{
    /**
     * Teto de solicitações abertas (`aberta` ou `em_atendimento`) por
     * cliente. Acima disso o portal viraria canal de despejo e a empresa não
     * conseguiria tratar o que chega (ver a Task 15.5).
     */
    public const LIMITE_ABERTAS = 5;

    private const ITENS_POR_PAGINA = 15;

    public function __construct(
        private readonly NotificationService $notificacoes,
    ) {}

    /**
     * Abre a solicitação em nome do cliente autenticado no portal e
     * enfileira o aviso `solicitacao_recebida` para a empresa.
     *
     * `$dados` espera `assunto`, `descricao` e, opcionalmente, `address_id`.
     * Qualquer `prioridade` presente em `$dados` é ignorada de propósito (ver
     * o cabeçalho da classe): a coluna nasce sempre com
     * `ClientRequest::PRIORIDADE_NORMAL`.
     *
     * @param  array{assunto?: mixed, descricao?: mixed, address_id?: mixed}  $dados
     *
     * @throws InvalidArgumentException Assunto/descrição vazios, ou `address_id` informado que não pertence a este cliente.
     * @throws RuntimeException O cliente já tem {@see self::LIMITE_ABERTAS} solicitações abertas.
     */
    public function abrir(ClientUser $cliente, array $dados): ClientRequest
    {
        $assunto = trim((string) ($dados['assunto'] ?? ''));
        $descricao = trim((string) ($dados['descricao'] ?? ''));

        if ($assunto === '') {
            throw new InvalidArgumentException('Informe o assunto da solicitação.');
        }

        if ($descricao === '') {
            throw new InvalidArgumentException('Descreva o que você precisa.');
        }

        $this->recusarAoAtingirLimite($cliente);

        $solicitacao = ClientRequest::create([
            'client_id' => $cliente->client_id,
            'client_user_id' => $cliente->id,
            'address_id' => $this->enderecoIdDoCliente($cliente, $dados['address_id'] ?? null),
            'assunto' => $assunto,
            'descricao' => $descricao,
            'situacao' => ClientRequest::SITUACAO_ABERTA,
            'prioridade' => ClientRequest::PRIORIDADE_NORMAL,
        ]);

        $this->avisarEmpresa($solicitacao);

        return $solicitacao;
    }

    /**
     * Registra a resposta da empresa e enfileira o aviso
     * `solicitacao_respondida` para o cliente. A resposta fica gravada e
     * visível no portal (`minha()`/`minhas()`), não só por e-mail.
     *
     * Solicitação ainda `aberta` avança para `em_atendimento`: responder já é
     * começar a tratar o pedido, e ele deixa de contar como "só aberta, sem
     * ninguém ter olhado". Solicitação já `em_atendimento`, `resolvida` ou
     * `cancelada` mantém a própria situação - mudar a situação é ação
     * separada (`mudarSituacao()`), e responder não força uma transição para
     * fora dela.
     *
     * @throws InvalidArgumentException Resposta vazia.
     */
    public function responder(ClientRequest $solicitacao, string $resposta, User $funcionario): ClientRequest
    {
        $resposta = trim($resposta);

        if ($resposta === '') {
            throw new InvalidArgumentException('Escreva uma resposta para a solicitação.');
        }

        $atributos = [
            'resposta' => $resposta,
            'respondida_em' => now(),
            'atendida_por' => $funcionario->getKey(),
        ];

        if ($solicitacao->situacao === ClientRequest::SITUACAO_ABERTA) {
            $atributos['situacao'] = ClientRequest::SITUACAO_EM_ATENDIMENTO;
        }

        $solicitacao->update($atributos);

        $solicitacao = $solicitacao->fresh();

        $this->avisarCliente($solicitacao);

        return $solicitacao;
    }

    /**
     * Muda a situação da solicitação, sem enfileirar aviso: o aviso ao
     * cliente é o de `responder()`, e a Task 15.5 não pede um segundo aviso
     * só pela mudança de situação (a resposta é o que ele lê).
     *
     * @throws InvalidArgumentException Situação fora de {@see ClientRequest::SITUACOES}.
     */
    public function mudarSituacao(ClientRequest $solicitacao, string $situacao): ClientRequest
    {
        if (! in_array($situacao, ClientRequest::SITUACOES, true)) {
            throw new InvalidArgumentException(
                "Situação inválida: \"{$situacao}\". Situações válidas: ".implode(', ', ClientRequest::SITUACOES).'.'
            );
        }

        $solicitacao->update(['situacao' => $situacao]);

        return $solicitacao->fresh();
    }

    /**
     * Cancela a solicitação. Atalho semântico para
     * `mudarSituacao(SITUACAO_CANCELADA)`, para quem cancela não precisar
     * conhecer a constante da situação.
     */
    public function cancelar(ClientRequest $solicitacao): ClientRequest
    {
        return $this->mudarSituacao($solicitacao, ClientRequest::SITUACAO_CANCELADA);
    }

    /**
     * Solicitações do cliente autenticado no portal, mais recentes primeiro.
     * Escopo duplo (empresa + cliente), ver o cabeçalho da classe.
     *
     * @return array<int, array<string, mixed>>
     */
    public function minhas(ClientUser $cliente): array
    {
        return $this->consultaDoCliente($cliente)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (ClientRequest $solicitacao): array => $this->paraPortal($solicitacao))
            ->all();
    }

    /**
     * Uma solicitação específica do cliente autenticado.
     *
     * @throws ModelNotFoundException Solicitação inexistente ou de outro cliente/empresa.
     */
    public function minha(ClientUser $cliente, int $id): array
    {
        return $this->paraPortal($this->buscarDoCliente($cliente, $id));
    }

    /**
     * Solicitações da empresa corrente, para o painel administrativo, com
     * filtro opcional por situação. Sem escopo por cliente: qualquer
     * funcionário da empresa vê e responde qualquer solicitação da própria
     * empresa (o escopo global de `ClientRequest` já resolve o isolamento
     * entre empresas).
     */
    public function listarParaEmpresa(?string $situacao = null): LengthAwarePaginator
    {
        $consulta = ClientRequest::query()
            ->with(['client', 'address', 'atendidaPor'])
            ->latest();

        if (filled($situacao)) {
            $consulta->where('situacao', $situacao);
        }

        return $consulta->paginate(self::ITENS_POR_PAGINA)->withQueryString();
    }

    /**
     * Quantidade de solicitações abertas (`aberta` ou `em_atendimento`) da
     * empresa corrente, para o contador do menu do painel
     * (`HandleInertiaRequests`).
     */
    public function contarAbertasDaEmpresa(): int
    {
        return ClientRequest::query()
            ->whereIn('situacao', [ClientRequest::SITUACAO_ABERTA, ClientRequest::SITUACAO_EM_ATENDIMENTO])
            ->count();
    }

    // -----------------------------------------------------------------
    // Consultas escopadas (empresa + cliente, sempre os dois)
    // -----------------------------------------------------------------

    private function consultaDoCliente(ClientUser $cliente): Builder
    {
        return ClientRequest::query()
            ->where('company_id', $cliente->company_id)
            ->where('client_id', $cliente->client_id);
    }

    /**
     * @throws ModelNotFoundException
     */
    private function buscarDoCliente(ClientUser $cliente, int $id): ClientRequest
    {
        return $this->consultaDoCliente($cliente)->find($id)
            ?? throw (new ModelNotFoundException)->setModel(ClientRequest::class, [$id]);
    }

    /**
     * Lista de permissão dos campos que saem para o cliente no portal, no
     * mesmo espírito de `CamposVisiveisAoCliente` (Task 15.3): nenhum campo
     * interno (`client_user_id`, `atendida_por` bruto) sai daqui, só o nome
     * de quem atendeu, quando houver.
     *
     * @return array<string, mixed>
     */
    private function paraPortal(ClientRequest $solicitacao): array
    {
        return [
            'id' => $solicitacao->id,
            'assunto' => $solicitacao->assunto,
            'descricao' => $solicitacao->descricao,
            'situacao' => $solicitacao->situacao,
            'prioridade' => $solicitacao->prioridade,
            'endereco' => $solicitacao->address?->full_address,
            'resposta' => $solicitacao->resposta,
            'respondida_em' => $solicitacao->respondida_em?->toDateTimeString(),
            'atendida_por' => $solicitacao->atendidaPor?->name,
            'criada_em' => $solicitacao->created_at?->toDateTimeString(),
        ];
    }

    // -----------------------------------------------------------------
    // Regras de abertura
    // -----------------------------------------------------------------

    /**
     * @throws RuntimeException
     */
    private function recusarAoAtingirLimite(ClientUser $cliente): void
    {
        $abertas = ClientRequest::query()
            ->where('company_id', $cliente->company_id)
            ->where('client_id', $cliente->client_id)
            ->whereIn('situacao', [ClientRequest::SITUACAO_ABERTA, ClientRequest::SITUACAO_EM_ATENDIMENTO])
            ->count();

        if ($abertas >= self::LIMITE_ABERTAS) {
            throw new RuntimeException(
                'Você já tem '.self::LIMITE_ABERTAS.' solicitações em aberto. '
                .'Acompanhe as existentes antes de abrir uma nova.'
            );
        }
    }

    /**
     * `address_id` informado é escopado a este cliente antes de gravar - o
     * mesmo cuidado da skill de multiempresa para id vindo do corpo da
     * requisição. Em uso normal pelo portal, o `AbrirSolicitacaoRequest` já
     * recusa endereço de outro cliente antes de chegar aqui (`Rule::exists`
     * escopado); esta checagem é a defesa em profundidade para quando o
     * Service for chamado de outro lugar sem passar por aquela validação.
     *
     * @throws InvalidArgumentException Endereço informado que não pertence a este cliente.
     */
    private function enderecoIdDoCliente(ClientUser $cliente, mixed $enderecoId): ?int
    {
        if (blank($enderecoId)) {
            return null;
        }

        $endereco = Address::query()
            ->where('company_id', $cliente->company_id)
            ->where('client_id', $cliente->client_id)
            ->find((int) $enderecoId);

        if ($endereco === null) {
            throw new InvalidArgumentException('O endereço informado não pertence a este cliente.');
        }

        return $endereco->id;
    }

    private function avisarEmpresa(ClientRequest $solicitacao): void
    {
        $this->notificacoes->enfileirar(EventosDeNotificacao::SOLICITACAO_RECEBIDA, $solicitacao, [
            'variaveis' => [
                'solicitacao_numero' => '#'.$solicitacao->getKey(),
                'solicitacao_assunto' => $solicitacao->assunto,
                'solicitacao_descricao' => $solicitacao->descricao,
            ],
        ]);
    }

    private function avisarCliente(ClientRequest $solicitacao): void
    {
        $this->notificacoes->enfileirar(EventosDeNotificacao::SOLICITACAO_RESPONDIDA, $solicitacao, [
            'variaveis' => [
                'solicitacao_numero' => '#'.$solicitacao->getKey(),
                'solicitacao_assunto' => $solicitacao->assunto,
                'solicitacao_resposta' => $solicitacao->resposta,
            ],
        ]);
    }
}
