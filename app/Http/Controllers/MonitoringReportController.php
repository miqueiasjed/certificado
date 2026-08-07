<?php

namespace App\Http\Controllers;

use App\Http\Requests\MonitoringReportFilterRequest;
use App\Http\Requests\StoreMonitoringReportRequest;
use App\Models\Address;
use App\Models\Client;
use App\Models\MonitoringReport;
use App\Services\Monitoring\ConsolidadorDePeriodo;
use App\Services\Monitoring\RelatorioPdfService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use InvalidArgumentException;

/**
 * Relatório de monitoramento consolidado por período (Plano 21, Task 21.5).
 *
 * Duas superfícies bem diferentes, de propósito
 * -----------------------------------------------------------------------
 * - `index()` é a VISÃO AO VIVO: chama `ConsolidadorDePeriodo::consolidar()`
 *   a cada requisição, sem gravar nada. A prop `congelado: false` viaja
 *   explícita para o frontend (Task 21.8) nunca deixar o usuário achar que
 *   está olhando um documento entregue ao cliente - a visão recalcula assim
 *   que uma OS nova entra no período, o relatório salvo, não.
 * - `store()` GERA E CONGELA: roda o mesmo consolidador uma vez e grava o
 *   resultado em `monitoring_reports.dados`. Dali em diante `show()`/`pdf()`
 *   leem só o que foi gravado, nunca recalculam - regerar cria outro
 *   registro, nunca sobrescreve (relatório salvo é imutável, regra de
 *   negócio inegociável da Task 21.5).
 *
 * Publicação nunca é automática
 * -----------------------------------------------------------------------
 * Todo relatório nasce com `publicado_no_portal = false`. Só `publicar()`
 * muda isso, atrás da permissão `monitoramento-publicar` (mais restrita que
 * `monitoramento-gerar`, ver a rota): quem gera o relatório não é
 * necessariamente quem decide que ele já pode ir para a auditoria do
 * cliente.
 */
class MonitoringReportController extends Controller
{
    public function __construct(
        private readonly ConsolidadorDePeriodo $consolidador,
        private readonly RelatorioPdfService $relatorioPdf,
    ) {}

    public function index(MonitoringReportFilterRequest $request): InertiaResponse
    {
        $filtros = $request->validated();

        $consolidado = null;

        if (filled($filtros['client_id'] ?? null) && filled($filtros['de'] ?? null) && filled($filtros['ate'] ?? null)) {
            [$cliente, $endereco] = $this->resolverClienteEEndereco((int) $filtros['client_id'], $this->paraInt($filtros['address_id'] ?? null));

            $consolidado = $this->consolidarOuFalhar($cliente, $endereco, $filtros['de'], $filtros['ate']);
        }

        return Inertia::render('Monitoring/Index', [
            'clientes' => Client::query()->orderBy('name')->get(['id', 'name']),
            'filtros' => [
                'client_id' => $filtros['client_id'] ?? null,
                'address_id' => $filtros['address_id'] ?? null,
                'de' => $filtros['de'] ?? null,
                'ate' => $filtros['ate'] ?? null,
            ],
            'consolidado' => $consolidado,
            // Explícito para o frontend (Task 21.8) nunca confundir esta
            // tela com um relatório gerado: aqui é sempre recalculado ao
            // vivo, nada foi gravado.
            'congelado' => false,
        ]);
    }

    public function store(StoreMonitoringReportRequest $request): RedirectResponse
    {
        $dados = $request->validated();

        [$cliente, $endereco] = $this->resolverClienteEEndereco((int) $dados['client_id'], $this->paraInt($dados['address_id'] ?? null));

        $consolidado = $this->consolidarOuFalhar($cliente, $endereco, $dados['de'], $dados['ate']);

        $relatorio = MonitoringReport::query()->create([
            'client_id' => $cliente->id,
            'address_id' => $endereco?->id,
            'periodo_inicio' => $dados['de'],
            'periodo_fim' => $dados['ate'],
            'gerado_em' => now(),
            'gerado_por' => Auth::id(),
            'dados' => $consolidado,
            // Nasce sempre despublicado: publicar é ato deliberado do
            // responsável técnico (ver `publicar()`), nunca automático na
            // geração.
            'publicado_no_portal' => false,
        ]);

        return redirect()
            ->route('monitoramento.relatorios.show', $relatorio)
            ->with('success', 'Relatório de monitoramento gerado com sucesso.');
    }

    /**
     * Lista paginada dos relatórios já gerados, com o mesmo filtro por
     * cliente/endereço/período da visão ao vivo, quando informado.
     */
    public function relatorios(MonitoringReportFilterRequest $request): InertiaResponse
    {
        $filtros = $request->validated();

        $relatorios = MonitoringReport::query()
            ->with(['client:id,name', 'address:id,nickname', 'geradoPor:id,name'])
            ->when(filled($filtros['client_id'] ?? null), fn ($query) => $query->where('client_id', $filtros['client_id']))
            ->when(filled($filtros['address_id'] ?? null), fn ($query) => $query->where('address_id', $filtros['address_id']))
            ->when(filled($filtros['de'] ?? null), fn ($query) => $query->whereDate('periodo_fim', '>=', $filtros['de']))
            ->when(filled($filtros['ate'] ?? null), fn ($query) => $query->whereDate('periodo_inicio', '<=', $filtros['ate']))
            ->orderByDesc('gerado_em')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Monitoring/Relatorios/Index', [
            'relatorios' => $relatorios,
            'filtros' => $filtros,
        ]);
    }

    /**
     * Detalhe do relatório congelado: devolve `dados` exatamente como foi
     * gravado, nunca recalcula.
     */
    public function show(MonitoringReport $monitoringReport): InertiaResponse
    {
        $monitoringReport->load(['client:id,name', 'address:id,nickname', 'geradoPor:id,name']);

        return Inertia::render('Monitoring/Relatorios/Show', [
            'relatorio' => $monitoringReport,
            'congelado' => true,
        ]);
    }

    /**
     * PDF definitivo deste relatório (Plano 21, Task 21.6): capa, resumo do
     * período, evolução em gráfico, ranking dos pontos críticos, mapa de
     * calor, ocorrência por espécie, croqui e adequações em aberto.
     *
     * Sempre a versão técnica do croqui (rótulo completo e código público de
     * cada dispositivo): esta rota é de uso interno, atrás de
     * `monitoramento-ver`. A versão sem código de dispositivo é a que o
     * portal do cliente baixa, em `Portal\PortalRelatorioController::pdf()`.
     */
    public function pdf(MonitoringReport $monitoringReport): Response
    {
        return $this->relatorioPdf
            ->relatorio($monitoringReport, RelatorioPdfService::VERSAO_TECNICO)
            ->download($this->nomeDoArquivo($monitoringReport));
    }

    /**
     * Ato deliberado do responsável técnico: exige `monitoramento-publicar`,
     * mais restrita que `monitoramento-gerar` (ver a rota). O relatório só
     * chega ao portal do cliente depois de alguém decidir isso
     * explicitamente.
     */
    public function publicar(MonitoringReport $monitoringReport): RedirectResponse
    {
        $monitoringReport->update(['publicado_no_portal' => true]);

        return back()->with('success', 'Relatório publicado no portal do cliente.');
    }

    public function despublicar(MonitoringReport $monitoringReport): RedirectResponse
    {
        $monitoringReport->update(['publicado_no_portal' => false]);

        return back()->with('success', 'Relatório removido do portal do cliente.');
    }

    /**
     * Resolve cliente e (opcionalmente) endereço já escopados pela empresa
     * corrente: `Client`/`Address` usam `BelongsToCompany`, então
     * `findOrFail()` aqui é o ponto em que o id vindo da querystring/corpo
     * (nunca da rota) é de fato usado contra o banco, cumprindo a regra de
     * "id vindo do corpo é escopado antes de usar" - id de outra empresa
     * nunca chega a `ConsolidadorDePeriodo`, cai em 404 antes.
     *
     * @return array{0: Client, 1: Address|null}
     */
    private function resolverClienteEEndereco(int $clientId, ?int $addressId): array
    {
        $cliente = Client::query()->findOrFail($clientId);
        $endereco = $addressId === null ? null : Address::query()->findOrFail($addressId);

        return [$cliente, $endereco];
    }

    /**
     * `ConsolidadorDePeriodo::consolidar()` valida data e o vínculo do
     * endereço com o cliente lançando `InvalidArgumentException`; aqui vira
     * erro de validação (422/redirect com erros) em vez de estourar como
     * falha interna (500).
     *
     * @return array<string, mixed>
     */
    private function consolidarOuFalhar(Client $cliente, ?Address $endereco, string $de, string $ate): array
    {
        try {
            return $this->consolidador->consolidar($cliente, $endereco, $de, $ate);
        } catch (InvalidArgumentException $erro) {
            throw ValidationException::withMessages(['periodo' => $erro->getMessage()]);
        }
    }

    private function paraInt(mixed $valor): ?int
    {
        return $valor === null || $valor === '' ? null : (int) $valor;
    }

    private function nomeDoArquivo(MonitoringReport $monitoringReport): string
    {
        return sprintf(
            'Relatorio-Monitoramento-%s-a-%s.pdf',
            $monitoringReport->periodo_inicio?->toDateString(),
            $monitoringReport->periodo_fim?->toDateString()
        );
    }
}
