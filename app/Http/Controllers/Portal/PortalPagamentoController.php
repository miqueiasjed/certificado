<?php

namespace App\Http\Controllers\Portal;

use App\Exceptions\CobrancaAtivaExistenteException;
use App\Exceptions\CobrancaJaPagaException;
use App\Exceptions\DadosDeCobrancaInvalidosException;
use App\Exceptions\GatewayIndisponivelException;
use App\Exceptions\GatewayNaoConfiguradoException;
use App\Exceptions\GatewayRecusouException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\GerarCobrancaNoPortalRequest;
use App\Models\ClientUser;
use App\Models\CompanyBillingSetting;
use App\Services\ChargeService;
use App\Services\ModuleService;
use App\Services\PortalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Fatura e pagamento no portal do cliente (Plano 19, Task 19.6):
 * `GET /portal/faturas` passa a trazer a cobrança ativa (boleto ou Pix) de
 * cada fatura, com link, linha digitável e QR code, e
 * `POST /portal/faturas/{parcela}/cobranca` deixa o próprio cliente gerar a
 * cobrança, quando a empresa liberou essa opção.
 *
 * Nasce separado de `App\Http\Controllers\Portal\PortalController` (que tinha
 * `faturas()` até esta task) porque acrescenta duas dependências que o resto
 * do portal não tem: `ChargeService` (emite a cobrança) e `ModuleService`
 * (confere o módulo `cobranca_recorrente`). Mesmo critério de
 * `App\Http\Controllers\Portal\PortalDocumentController`, já separado de
 * `PortalController` desde a Task 15.4 por um motivo parecido.
 *
 * ## `GET /portal/faturas` não é bloqueada pelo módulo `cobranca_recorrente`
 *
 * A listagem de faturas é do Plano 15 (`portal_cliente`), anterior a este
 * plano, e continua funcionando para todo tenant, com ou sem o módulo de
 * cobrança recorrente ativo - só o valor de `cobranca` some quando não há
 * nada emitido. É a ação de **gerar** cobrança
 * (`gerarCobranca()`) que exige o módulo ativo: emitir boleto/Pix é a
 * funcionalidade nova, e é ela que fica atrás de `module:cobranca_recorrente`
 * (ver `routes/portal.php`); mostrar a fatura em si nunca esteve atrás dele.
 *
 * ## Nenhuma consulta Eloquent própria a `Charge`/`ReceivableInstallment`
 *
 * Mesma regra de ouro de `PortalController`/`PortalDocumentController`: toda
 * consulta passa por `App\Services\PortalService`
 * (`situacaoDeCobrancaDaFatura()`/`parcelaParaCobranca()`, acrescidos nesta
 * task) - este arquivo nunca monta consulta Eloquent direta a nenhum dos dois
 * models.
 *
 * ## O que o cliente nunca vê
 *
 * Nem aqui, nem em `App\Support\CamposVisiveisAoCliente::cobranca()` (a
 * lista de permissão usada para montar cada cobrança devolvida): gateway,
 * identificador da cobrança no provedor, custo, margem ou qualquer situação
 * interna do título - regra do Plano 15, replicada para o dado novo deste
 * plano.
 */
class PortalPagamentoController extends Controller
{
    private const ITENS_POR_PAGINA = 20;

    private readonly PortalService $portal;

    public function __construct(
        private readonly ChargeService $chargeService,
        private readonly ModuleService $moduleService,
    ) {
        $this->portal = new PortalService($this->clienteAutenticado());
    }

    /**
     * GET /portal/faturas
     */
    public function faturas(Request $request): Response
    {
        $moduloAtivo = $this->moduleService->ativo('cobranca_recorrente');
        $emissaoHabilitada = CompanyBillingSetting::atual()->emissaoPeloClienteHabilitada();

        $faturas = collect($this->portal->faturas())
            ->map(fn (array $fatura): array => $this->comCobranca($fatura, $moduloAtivo, $emissaoHabilitada))
            ->all();

        return Inertia::render('Portal/Faturas/Index', [
            'faturas' => $this->paginar($faturas, $request),
        ]);
    }

    /**
     * POST /portal/faturas/{parcela}/cobranca
     *
     * `$parcela` é o id da fatura (`payment_details.id`, o mesmo `id` que
     * `faturas()` devolve ao cliente) - nunca route-model binding: a mesma
     * cautela de `PortalController::visita()`/`ClientRequestController::show()`,
     * porque só `PortalService` sabe escopar por empresa e cliente ao mesmo
     * tempo.
     */
    public function gerarCobranca(GerarCobrancaNoPortalRequest $request, int $parcela): JsonResponse
    {
        if (! $this->moduleService->ativo('cobranca_recorrente')) {
            return $this->recusar('Este recurso não está disponível no momento.', 403);
        }

        if (! CompanyBillingSetting::atual()->emissaoPeloClienteHabilitada()) {
            return $this->recusar('A empresa não habilitou a geração de cobrança pelo cliente.', 403);
        }

        $parcelaAReceber = $this->portal->parcelaParaCobranca($parcela);

        try {
            $cobranca = $this->chargeService->emitir($parcelaAReceber, (string) $request->validated()['tipo']);
        } catch (ValidationException $erro) {
            throw $erro;
        } catch (CobrancaAtivaExistenteException|CobrancaJaPagaException|DadosDeCobrancaInvalidosException
            |GatewayNaoConfiguradoException|GatewayRecusouException|GatewayIndisponivelException $erro) {
            return $this->recusar($erro->getMessage(), 422);
        } catch (Throwable $erro) {
            throw $erro;
        }

        $situacao = $this->portal->situacaoDeCobrancaDaFatura($parcela);

        return response()->json([
            'message' => 'Cobrança gerada.',
            'cobranca' => $situacao['cobranca'],
        ], 201);
    }

    // -----------------------------------------------------------------
    // Composição
    // -----------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $fatura
     * @return array<string, mixed>
     */
    private function comCobranca(array $fatura, bool $moduloAtivo, bool $emissaoHabilitada): array
    {
        $situacao = $this->portal->situacaoDeCobrancaDaFatura((int) $fatura['id']);

        $fatura['cobranca'] = $situacao['cobranca'];

        // A parcela precisa existir (fatura do Plano 18) e não ter cobrança
        // ativa; sem isso `ChargeService::emitir()` recusaria de qualquer
        // forma (parcela inexistente ou `CobrancaAtivaExistenteException`) -
        // esta flag só evita a tela oferecer um botão que o backend recusaria
        // na hora.
        $fatura['pode_gerar_cobranca'] = $situacao['parcela_existe']
            && $situacao['cobranca'] === null
            && in_array($fatura['payment_status'], ['pending', 'overdue'], true)
            && $moduloAtivo
            && $emissaoHabilitada;

        return $fatura;
    }

    private function clienteAutenticado(): ClientUser
    {
        /** @var ClientUser $clientUser */
        $clientUser = Auth::guard('cliente')->user();

        return $clientUser;
    }

    /**
     * Mesmo critério de `PortalController::paginar()`: pagina aqui, sobre o
     * array já carregado e decorado, com um `LengthAwarePaginator` montado à
     * mão.
     *
     * @param  array<int, array<string, mixed>>  $itens
     */
    private function paginar(array $itens, Request $request): LengthAwarePaginator
    {
        $colecao = collect($itens);
        $pagina = LengthAwarePaginator::resolveCurrentPage();

        return new LengthAwarePaginator(
            $colecao->forPage($pagina, self::ITENS_POR_PAGINA)->values(),
            $colecao->count(),
            self::ITENS_POR_PAGINA,
            $pagina,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );
    }

    private function recusar(string $mensagem, int $status): JsonResponse
    {
        return response()->json(['message' => $mensagem], $status);
    }
}
