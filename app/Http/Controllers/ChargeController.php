<?php

namespace App\Http\Controllers;

use App\Exceptions\CobrancaAtivaExistenteException;
use App\Exceptions\CobrancaJaPagaException;
use App\Exceptions\DadosDeCobrancaInvalidosException;
use App\Exceptions\GatewayIndisponivelException;
use App\Exceptions\GatewayNaoConfiguradoException;
use App\Exceptions\GatewayRecusouException;
use App\Http\Requests\AtualizarConfiguracaoDeCobrancaRequest;
use App\Http\Requests\CancelarCobrancaRequest;
use App\Http\Requests\StoreChargeRequest;
use App\Models\Charge;
use App\Models\Client;
use App\Models\CompanyBillingSetting;
use App\Models\GatewayEvent;
use App\Models\PaymentGatewayConfig;
use App\Models\ReceivableInstallment;
use App\Services\ChargeService;
use App\Services\Payments\ConciliacaoService;
use App\Services\Payments\GatewayPagBank;
use App\Services\Payments\ProcessadorDeEventoDeCobranca;
use App\Services\Payments\ReguaDeCobrancaService;
use App\Services\Payments\ResolvedorDeGateway;
use App\Support\BusinessDate;
use App\Support\TenantAtual;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Cobrança recorrente do tenant ao cliente final (Plano 19, Task 19.6): a
 * listagem com filtros, a emissão individual e em lote, a reemissão, o
 * cancelamento, a conciliação com o gateway e a configuração da credencial e
 * da régua.
 *
 * Controller fino: toda regra de negócio de emissão/reemissão/cancelamento
 * mora em `App\Services\ChargeService` (Task 19.3), e a conciliação em
 * `App\Services\Payments\ConciliacaoService` (esta task). Aqui só a orquestração
 * e a tradução das exceções de domínio em resposta HTTP.
 *
 * ## `GatewayEvent` não tem escopo automático de empresa
 *
 * Diferente de `Charge`/`PaymentGatewayConfig` (`BelongsToCompany`),
 * `App\Models\GatewayEvent` fica deliberadamente fora do escopo por empresa
 * (é a mesma tabela que registra o evento de assinatura da plataforma, sem
 * tenant nenhum - ver o cabeçalho do model). Por isso `reprocessarEvento()`
 * nunca usa route-model binding para `GatewayEvent`: o id chega cru e o
 * `company_id` é conferido à mão antes de qualquer ação, ou o evento seria
 * acessível e reprocessável por qualquer tenant que soubesse o id.
 *
 * ## Credencial nunca sai numa resposta
 *
 * `configuracao()` monta a resposta campo a campo, nunca com
 * `$configuracao->toArray()`: `PaymentGatewayConfig` já esconde `credenciais`
 * via `$hidden` (Task 19.1), mas repetir esse cuidado aqui, explícito, é o
 * que garante que um campo novo no model não vaza por essa rota amanhã sem
 * ninguém perceber.
 */
class ChargeController extends Controller
{
    /**
     * Teto de linhas da listagem, mesmo critério de
     * `App\Http\Controllers\ReceivableController::LIMITE_DE_LINHAS`: tela
     * operacional, não relatório histórico.
     */
    private const LIMITE_DE_LINHAS = 300;

    public function __construct(
        private readonly ChargeService $chargeService,
        private readonly ConciliacaoService $conciliacaoService,
        private readonly ResolvedorDeGateway $resolvedorDeGateway,
        private readonly ProcessadorDeEventoDeCobranca $processadorDeEvento,
    ) {}

    // -----------------------------------------------------------------
    // Listagem
    // -----------------------------------------------------------------

    /**
     * GET /cobrancas
     *
     * Filtros: situação, tipo, período (por vencimento) e cliente.
     */
    public function index(Request $request): Response|JsonResponse
    {
        $filtros = $this->filtros($request);

        $dados = [
            'filtros' => $filtros,
            'cobrancas' => $this->cobrancas($filtros),
            'clientes' => $this->clientesParaFiltro(),
            'situacoes' => Charge::SITUACOES,
            'tipos' => Charge::TIPOS,
            // Task 19.7: a tela precisa saber, sem outra viagem ao servidor,
            // se o gateway ativo está em sandbox - é o que liga o aviso
            // permanente na listagem, onde o usuário efetivamente copia o
            // link e manda para o cliente (o ponto real de risco de mandar
            // cobrança de teste para gente de verdade).
            'ambiente_sandbox' => PaymentGatewayConfig::query()->orderByDesc('updated_at')->value('ambiente') === 'sandbox',
        ];

        if ($request->expectsJson()) {
            return response()->json($dados);
        }

        return Inertia::render('Cobrancas/Index', $dados);
    }

    // -----------------------------------------------------------------
    // Emissão, individual ou em lote
    // -----------------------------------------------------------------

    /**
     * POST /cobrancas
     */
    public function store(StoreChargeRequest $request): JsonResponse
    {
        $tipo = (string) $request->validated()['tipo'];

        if ($request->emLote()) {
            return $this->emitirEmLote($request->idsDasParcelas(), $tipo);
        }

        return $this->emitirIndividual($request->idsDasParcelas()[0], $tipo);
    }

    private function emitirIndividual(int $parcelaId, string $tipo): JsonResponse
    {
        $parcela = ReceivableInstallment::query()->find($parcelaId);

        if (! $parcela instanceof ReceivableInstallment) {
            return $this->recusar('Parcela a receber não encontrada.', 404);
        }

        try {
            $cobranca = $this->chargeService->emitir($parcela, $tipo);
        } catch (Throwable $erro) {
            return $this->recusarErroDeCobranca($erro);
        }

        return response()->json([
            'message' => 'Cobrança emitida.',
            'cobranca' => $cobranca,
        ], 201);
    }

    /**
     * Ids que não resolvem para uma parcela desta empresa (inexistente, ou de
     * outra empresa - o escopo global de `ReceivableInstallment` já filtra
     * isso) entram no resultado com o mesmo formato de erro que
     * `ChargeService::emitirEmLote()` usa para as demais falhas, em vez de
     * simplesmente desaparecer da resposta sem explicação.
     */
    private function emitirEmLote(array $ids, string $tipo): JsonResponse
    {
        $parcelas = ReceivableInstallment::query()->whereKey($ids)->get();
        $encontrados = $parcelas->pluck('id')->all();

        $resultados = $this->chargeService->emitirEmLote($parcelas, $tipo);

        foreach ($ids as $id) {
            if (! in_array($id, $encontrados, true)) {
                $resultados[] = [
                    'receivable_installment_id' => $id,
                    'situacao' => 'erro',
                    'charge_id' => null,
                    'mensagem' => 'Parcela a receber não encontrada.',
                ];
            }
        }

        return response()->json(['message' => 'Lote processado.', 'resultados' => $resultados]);
    }

    // -----------------------------------------------------------------
    // Reemissão e cancelamento
    // -----------------------------------------------------------------

    /**
     * POST /cobrancas/{cobranca}/reemitir
     *
     * `{cobranca}` chega por route-model binding: `Charge` usa
     * `BelongsToCompany`, e cobrança de outra empresa não é encontrada (404
     * automático).
     */
    public function reemitir(Charge $cobranca): JsonResponse
    {
        try {
            $nova = $this->chargeService->reemitir($cobranca);
        } catch (Throwable $erro) {
            return $this->recusarErroDeCobranca($erro);
        }

        return response()->json(['message' => 'Cobrança reemitida.', 'cobranca' => $nova]);
    }

    /**
     * POST /cobrancas/{cobranca}/cancelar
     */
    public function cancelar(CancelarCobrancaRequest $request, Charge $cobranca): JsonResponse
    {
        try {
            $cancelada = $this->chargeService->cancelar($cobranca, (string) $request->validated()['motivo']);
        } catch (Throwable $erro) {
            return $this->recusarErroDeCobranca($erro);
        }

        return response()->json(['message' => 'Cobrança cancelada.', 'cobranca' => $cancelada]);
    }

    // -----------------------------------------------------------------
    // Conciliação (Task 19.6)
    // -----------------------------------------------------------------

    /**
     * GET /cobrancas/conciliacao
     *
     * `de`/`ate` vêm como dia (`Y-m-d`); sem os dois, cai no mês corrente no
     * fuso do negócio, para a tela sempre abrir com algo para mostrar.
     */
    public function conciliacao(Request $request): Response|JsonResponse
    {
        $hoje = BusinessDate::hoje();

        $de = BusinessDate::diaDe($request->query('de')) ?? $hoje->startOfMonth()->toDateString();
        $ate = BusinessDate::diaDe($request->query('ate')) ?? $hoje->toDateString();

        $relatorio = $this->conciliacaoService->relatorio($de, $ate);

        if ($request->expectsJson()) {
            return response()->json($relatorio);
        }

        return Inertia::render('Cobrancas/Conciliacao', $relatorio);
    }

    /**
     * POST /cobrancas/conciliacao/eventos/{evento}/reprocessar
     *
     * `$eventoId` chega cru, nunca por route-model binding (ver o cabeçalho
     * da classe): o pertencimento à empresa corrente é conferido aqui, à mão,
     * antes de qualquer leitura do payload.
     */
    public function reprocessarEvento(int $eventoId): JsonResponse
    {
        $empresaId = TenantAtual::id();
        $evento = GatewayEvent::query()->find($eventoId);

        if (! $evento instanceof GatewayEvent || $empresaId === null || (int) $evento->company_id !== $empresaId) {
            return $this->recusar('Evento não encontrado.', 404);
        }

        if ($evento->processado_em !== null) {
            return $this->recusar('Este evento já foi processado.', 422);
        }

        // Instância transitória, nunca persistida: só serve para
        // `ResolvedorDeGateway::paraConfiguracao()` escolher a implementação
        // pelo nome do gateway gravado no próprio evento
        // (`GatewayEvent.gateway`), sem depender de a credencial do tenant
        // continuar cadastrada e ativa hoje - o evento é histórico e precisa
        // poder ser reprocessado mesmo que o gateway tenha sido reconfigurado
        // depois. `forceFill()`, e não o construtor: `company_id` não está em
        // `PaymentGatewayConfig::$fillable` (não é um dado que a tela de
        // configuração deixa o usuário escolher), mas
        // `ProcessadorDeEventoDeCobranca::processar()` lê exatamente esse
        // campo para saber em qual tenant aplicar o evento
        // (`TenantAtual::comTenant((int) $configuracao->company_id, ...)`) -
        // sem ele, o processamento rodaria fora de qualquer tenant e não
        // encontraria a cobrança de ninguém.
        $configuracaoTransitoria = (new PaymentGatewayConfig())->forceFill([
            'gateway' => $evento->gateway,
            'company_id' => $evento->company_id,
        ]);

        try {
            $gateway = $this->resolvedorDeGateway->paraConfiguracao($configuracaoTransitoria);
            $eventoDeCobranca = $gateway->interpretarWebhook((array) $evento->payload);
        } catch (Throwable $erro) {
            return $this->recusar('Não foi possível interpretar o evento: '.$erro->getMessage(), 422);
        }

        $this->processadorDeEvento->processar($evento, $eventoDeCobranca, $configuracaoTransitoria);

        return response()->json([
            'message' => $evento->fresh()->processado_em !== null
                ? 'Evento reprocessado com sucesso.'
                : 'O reprocessamento falhou de novo; veja o motivo no próprio evento.',
            'evento' => $evento->fresh(),
        ]);
    }

    // -----------------------------------------------------------------
    // Configuração: credencial do gateway e régua de cobrança
    // -----------------------------------------------------------------

    /**
     * GET /cobrancas/configuracao
     *
     * Nunca devolve a credencial salva: só a indicação de que existe, o
     * ambiente, se está ativa e a data da última verificação.
     */
    public function configuracao(Request $request): Response|JsonResponse
    {
        $configuracaoGateway = PaymentGatewayConfig::query()->orderByDesc('updated_at')->first();
        $regua = CompanyBillingSetting::atual();

        $dados = [
            'gateway' => [
                'gateway' => $configuracaoGateway?->gateway,
                'ambiente' => $configuracaoGateway?->ambiente,
                'possui_credencial' => $configuracaoGateway !== null,
                'ativo' => $configuracaoGateway?->ativo ?? false,
                'verificado_em' => $configuracaoGateway?->verificado_em?->toIso8601String(),
            ],
            'regua' => [
                'regua_ativa' => $regua->reguaAtiva(),
                'marcos_ativos' => $regua->marcosAtivos(),
                'marcos_disponiveis' => ReguaDeCobrancaService::marcos(),
                'antecedencia_emissao_dias' => $regua->antecedenciaEmissaoDias(),
                'emissao_pelo_cliente_habilitada' => $regua->emissaoPeloClienteHabilitada(),
            ],
        ];

        if ($request->expectsJson()) {
            return response()->json($dados);
        }

        return Inertia::render('Cobrancas/Configuracao', $dados);
    }

    /**
     * PUT /cobrancas/configuracao
     *
     * `AtualizarConfiguracaoDeCobrancaRequest::authorize()` já recusa quem
     * não tem `cobranca-configurar` com 403, além do
     * `permission:cobranca-configurar` da rota - ver o docblock do
     * FormRequest para o porquê das duas barreiras.
     */
    public function configuracaoAtualizar(AtualizarConfiguracaoDeCobrancaRequest $request): JsonResponse
    {
        if ($request->hasAny(['gateway', 'ambiente', 'credenciais', 'ativo'])) {
            $this->salvarCredencial($request);
        }

        if ($request->hasAny(['regua_ativa', 'marcos_ativos', 'antecedencia_emissao_dias', 'emissao_pelo_cliente_habilitada'])) {
            $this->salvarRegua($request);
        }

        return response()->json(['message' => 'Configuração salva.']);
    }

    /**
     * POST /cobrancas/configuracao/validar
     *
     * Confirma a credencial salva junto ao provedor agora, em vez de o
     * tenant só descobrir que está errada na primeira cobrança real (Task
     * 19.7 - "botão de validar credencial com resposta imediata").
     * `ResolvedorDeGateway::validar()` (Task 19.6) já faz a chamada e já
     * grava `verificado_em` quando válida; esta ação só faltava para a tela
     * chegar a ele - o Service foi escrito exatamente para este uso (ver o
     * próprio docblock de `validar()`), mas nenhuma rota chamava ainda.
     */
    public function validarCredencial(): JsonResponse
    {
        $configuracao = PaymentGatewayConfig::query()->orderByDesc('updated_at')->first();

        if (! $configuracao instanceof PaymentGatewayConfig) {
            return $this->recusar('Nenhuma credencial configurada ainda.', 422);
        }

        try {
            $valida = $this->resolvedorDeGateway->validar($configuracao);
        } catch (Throwable $erro) {
            return $this->recusar('Não foi possível validar a credencial agora: '.$erro->getMessage());
        }

        return response()->json([
            'valida' => $valida,
            'message' => $valida
                ? 'Credencial válida.'
                : 'O provedor recusou a credencial. Confira o token e tente de novo.',
            'verificado_em' => $configuracao->fresh()->verificado_em?->toIso8601String(),
        ]);
    }

    private function salvarCredencial(AtualizarConfiguracaoDeCobrancaRequest $request): void
    {
        $gateway = $request->input('gateway') ?: (PaymentGatewayConfig::query()->value('gateway') ?: GatewayPagBank::NOME);

        $configuracao = PaymentGatewayConfig::query()->where('gateway', $gateway)->first()
            ?? new PaymentGatewayConfig(['gateway' => $gateway, 'ambiente' => 'sandbox']);

        if ($request->has('ambiente') && $request->input('ambiente') !== null) {
            $configuracao->ambiente = $request->input('ambiente');
        }

        if ($request->has('credenciais') && $request->input('credenciais') !== []) {
            $configuracao->credenciais = $request->input('credenciais');
            // Credencial nova: a verificação anterior não vale para o token
            // que acabou de entrar.
            $configuracao->verificado_em = null;
        }

        if ($request->has('ativo')) {
            $configuracao->ativo = $request->boolean('ativo');
        }

        $configuracao->save();
    }

    private function salvarRegua(AtualizarConfiguracaoDeCobrancaRequest $request): void
    {
        $regua = CompanyBillingSetting::query()->first() ?? new CompanyBillingSetting();

        if ($request->has('regua_ativa')) {
            $regua->regua_ativa = $request->boolean('regua_ativa');
        }

        if ($request->has('marcos_ativos')) {
            $regua->marcos_ativos = $request->input('marcos_ativos');
        }

        if ($request->has('antecedencia_emissao_dias') && $request->input('antecedencia_emissao_dias') !== null) {
            $regua->antecedencia_emissao_dias = (int) $request->input('antecedencia_emissao_dias');
        }

        if ($request->has('emissao_pelo_cliente_habilitada')) {
            $regua->emissao_pelo_cliente_habilitada = $request->boolean('emissao_pelo_cliente_habilitada');
        }

        $regua->save();
    }

    // -----------------------------------------------------------------
    // Composição da leitura
    // -----------------------------------------------------------------

    /**
     * @return array{situacao: string|null, tipo: string|null, de: string|null, ate: string|null, client_id: int|null}
     */
    private function filtros(Request $request): array
    {
        $situacao = $request->query('situacao');
        $tipo = $request->query('tipo');

        return [
            'situacao' => in_array($situacao, Charge::SITUACOES, true) ? $situacao : null,
            'tipo' => in_array($tipo, Charge::TIPOS, true) ? $tipo : null,
            'de' => BusinessDate::diaDe($request->query('de')),
            'ate' => BusinessDate::diaDe($request->query('ate')),
            'client_id' => $request->integer('client_id') ?: null,
        ];
    }

    /**
     * @param  array{situacao: string|null, tipo: string|null, de: string|null, ate: string|null, client_id: int|null}  $filtros
     * @return array<int, array<string, mixed>>
     */
    private function cobrancas(array $filtros): array
    {
        return Charge::query()
            ->with(['receivableInstallment.receivable.client'])
            ->when($filtros['situacao'], fn ($consulta, $situacao) => $consulta->where('situacao', $situacao))
            ->when($filtros['tipo'], fn ($consulta, $tipo) => $consulta->where('tipo', $tipo))
            ->when($filtros['de'], fn ($consulta, $dia) => $consulta->where('vencimento', '>=', $dia))
            ->when($filtros['ate'], fn ($consulta, $dia) => $consulta->where('vencimento', '<=', $dia))
            ->when(
                $filtros['client_id'],
                fn ($consulta, $id) => $consulta->whereHas(
                    'receivableInstallment.receivable',
                    fn ($titulo) => $titulo->where('client_id', $id)
                )
            )
            ->orderByDesc('vencimento')
            ->orderByDesc('id')
            ->limit(self::LIMITE_DE_LINHAS)
            ->get()
            ->map(fn (Charge $cobranca): array => $this->linhaDaCobranca($cobranca))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function linhaDaCobranca(Charge $cobranca): array
    {
        $parcela = $cobranca->receivableInstallment;
        $titulo = $parcela?->receivable;

        return [
            'id' => (int) $cobranca->getKey(),
            'tipo' => $cobranca->tipo,
            'situacao' => $cobranca->situacao,
            'gateway' => $cobranca->gateway,
            'valor' => (string) $cobranca->valor,
            'valor_pago' => $cobranca->valor_pago === null ? null : (string) $cobranca->valor_pago,
            'vencimento' => BusinessDate::diaDe($cobranca->vencimento),
            'pago_em' => BusinessDate::diaDe($cobranca->pago_em),
            'link_pagamento' => $cobranca->link_pagamento,
            'linha_digitavel' => $cobranca->linha_digitavel,
            'qr_code_pix' => $cobranca->qr_code_pix,
            'erro_mensagem' => $cobranca->erro_mensagem,
            'tentativas' => (int) $cobranca->tentativas,
            'receivable_installment_id' => $parcela?->getKey(),
            'client_id' => $titulo?->client_id === null ? null : (int) $titulo->client_id,
            'cliente' => (string) ($titulo?->client?->name ?? ''),
            // Task 19.7: copiar linha digitável e abrir link já saem só do
            // que está gravado na própria `Charge`; enviar por WhatsApp e por
            // e-mail em um clique precisam do contato do cliente, que não
            // tinha por que estar aqui até a tela existir. `client` já vem
            // carregado por `with(['receivableInstallment.receivable.client'])`
            // acima, então isto não é consulta nova. Mesma regra de
            // resolução de e-mail que `ChargeService::dadosDoCliente()`
            // (Task 19.3) e `ValidadorDeDadosDeCobranca` já usam:
            // `email_notificacao` antes de `email`.
            'cliente_telefone' => $titulo?->client?->phone,
            'cliente_aceita_whatsapp' => (bool) ($titulo?->client?->aceita_whatsapp ?? true),
            'cliente_email' => $titulo?->client?->email_notificacao ?: $titulo?->client?->email,
            'cliente_aceita_email' => (bool) ($titulo?->client?->aceita_email ?? true),
            'charge_anterior_id' => $cobranca->charge_anterior_id,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function clientesParaFiltro(): array
    {
        return Client::query()
            ->whereHas('receivables.installments.charges')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Client $cliente): array => [
                'id' => (int) $cliente->getKey(),
                'nome' => (string) $cliente->name,
            ])
            ->all();
    }

    // -----------------------------------------------------------------
    // Tradução de exceção de domínio em resposta HTTP
    // -----------------------------------------------------------------

    /**
     * `ValidationException` continua subindo: o handler padrão do Laravel já
     * a traduz em 422 com `errors`, mesmo critério do resto do sistema (ver
     * `App\Http\Controllers\ReceivableController`). As demais exceções de
     * domínio da Task 19.3 viram 422 com a mensagem em português que a
     * própria exceção já carrega pronta.
     */
    private function recusarErroDeCobranca(Throwable $erro): JsonResponse
    {
        if ($erro instanceof ValidationException) {
            throw $erro;
        }

        if ($erro instanceof CobrancaAtivaExistenteException
            || $erro instanceof CobrancaJaPagaException
            || $erro instanceof DadosDeCobrancaInvalidosException
            || $erro instanceof GatewayNaoConfiguradoException
            || $erro instanceof GatewayRecusouException
            || $erro instanceof GatewayIndisponivelException) {
            return $this->recusar($erro->getMessage());
        }

        throw $erro;
    }

    private function recusar(string $mensagem, int $status = 422): JsonResponse
    {
        return response()->json(['message' => $mensagem], $status);
    }
}
