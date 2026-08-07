<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\ClientUser;
use App\Models\MonitoringReport;
use App\Services\Monitoring\RelatorioPdfService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Relatórios de monitoramento no portal do cliente (Plano 21, Task 21.5).
 *
 * Escopo duplo, a mesma regra de `PortalService`
 * -----------------------------------------------------------------------
 * Toda consulta aqui filtra por `company_id` **e** por `client_id` do
 * `ClientUser` autenticado, explicitamente, mesmo com o escopo global do
 * Plano 4 (`BelongsToCompany`) já ativo em `MonitoringReport`. A redundância
 * é deliberada, ver o cabeçalho de `PortalService` para o porquê completo: o
 * escopo global separa empresas, mas não separa dois clientes da mesma
 * empresa, e o portal é acesso externo.
 *
 * Só publicado aparece, e 404 nunca 403
 * -----------------------------------------------------------------------
 * `consultaDosPublicados()` já filtra `publicado_no_portal = true`: um
 * relatório gerado mas ainda não publicado, ou de outro cliente/empresa, ou
 * inexistente, cai no mesmo `ModelNotFoundException` (404). Nunca é lançada
 * `AuthorizationException`/403 - dizer "existe mas não é seu" já vaza a
 * existência do registro, mesmo critério documentado no cabeçalho de
 * `PortalService`.
 *
 * `dados` conferido antes de sair, não só suposto
 * -----------------------------------------------------------------------
 * Relido, para esta task, o que `ConsolidadorDePeriodo`, `TendenciaService`,
 * `PontosCriticosService`, `MapaDeCalorService` e `OcorrenciaPorEspecieService`
 * realmente devolvem hoje: rótulo/código público de dispositivo, contagens,
 * datas, percentuais e o nome do próprio endereço/cliente. Nenhum dos cinco
 * grava nome completo, e-mail ou telefone de técnico, nem valor monetário -
 * não existe "quem executou" ou "quanto custou" em nenhuma das chaves de
 * `dados`. `paraPortal()` existe mesmo assim, documentado, para não ser um
 * "confia cegamente sem olhar de novo": no dia em que algum desses services
 * ganhar um campo novo (ex.: nome do técnico que fechou a visita), é aqui que
 * a filtragem entra, sem precisar mexer nos services nem no relatório já
 * gravado.
 */
class PortalRelatorioController extends Controller
{
    public function __construct(private readonly RelatorioPdfService $relatorioPdf) {}

    public function index(): InertiaResponse
    {
        $relatorios = $this->consultaDosPublicados()
            ->with('address:id,nickname')
            ->orderByDesc('gerado_em')
            ->paginate(15);

        return Inertia::render('Portal/Relatorios/Index', [
            'relatorios' => $relatorios->through(fn (MonitoringReport $relatorio): array => [
                'id' => $relatorio->id,
                'endereco' => $relatorio->address?->nickname,
                'periodo_inicio' => $relatorio->periodo_inicio?->toDateString(),
                'periodo_fim' => $relatorio->periodo_fim?->toDateString(),
                'gerado_em' => $relatorio->gerado_em?->toIso8601String(),
            ]),
        ]);
    }

    public function show(int $monitoringReport): InertiaResponse
    {
        $relatorio = $this->buscarPublicado($monitoringReport);

        return Inertia::render('Portal/Relatorios/Show', [
            'relatorio' => [
                'id' => $relatorio->id,
                'endereco' => $relatorio->address?->nickname,
                'periodo_inicio' => $relatorio->periodo_inicio?->toDateString(),
                'periodo_fim' => $relatorio->periodo_fim?->toDateString(),
                'gerado_em' => $relatorio->gerado_em?->toIso8601String(),
                'dados' => $this->paraPortal($relatorio->dados),
            ],
        ]);
    }

    /**
     * PDF definitivo do relatório (Plano 21, Task 21.6), reaproveitando
     * `RelatorioPdfService`/`pdf.monitoring-report.blade.php` - mesmo
     * conteúdo do PDF interno (`MonitoringReportController::pdf()`), exceto
     * pelo croqui: aqui sempre sai a versão cliente (numeração e estado do
     * ponto no período, sem o código público do dispositivo), a única
     * diferença que a task pede entre as duas superfícies.
     */
    public function pdf(int $monitoringReport): Response
    {
        $relatorio = $this->buscarPublicado($monitoringReport);

        return $this->relatorioPdf
            ->relatorio($relatorio, RelatorioPdfService::VERSAO_CLIENTE)
            ->download(sprintf(
                'Relatorio-Monitoramento-%s-a-%s.pdf',
                $relatorio->periodo_inicio?->toDateString(),
                $relatorio->periodo_fim?->toDateString()
            ));
    }

    private function consultaDosPublicados(): Builder
    {
        $clienteUser = $this->clienteAutenticado();

        return MonitoringReport::query()
            ->where('company_id', $clienteUser->company_id)
            ->where('client_id', $clienteUser->client_id)
            ->where('publicado_no_portal', true);
    }

    private function buscarPublicado(int $id): MonitoringReport
    {
        return $this->consultaDosPublicados()->find($id)
            ?? throw (new ModelNotFoundException)->setModel(MonitoringReport::class, [$id]);
    }

    /**
     * Ver o docblock da classe: hoje nenhuma chave de `dados` carrega custo,
     * observação interna ou dado pessoal de técnico, então esta filtragem é
     * um pass-through deliberado, não um recorte de campos. Existe como
     * método próprio, e não como `$relatorio->dados` direto no `show()`, para
     * ficar óbvio onde entrar no dia em que algum service novo do Plano 21
     * (ou uma chave nova em algum dos já existentes) precisar ser recortado
     * antes de sair para o cliente.
     *
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    private function paraPortal(array $dados): array
    {
        return $dados;
    }

    private function clienteAutenticado(): ClientUser
    {
        /** @var ClientUser $clientUser */
        $clientUser = Auth::guard('cliente')->user();

        return $clientUser;
    }
}
