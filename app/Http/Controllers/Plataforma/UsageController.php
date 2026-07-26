<?php

namespace App\Http\Controllers\Plataforma;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\TenantUsage;
use App\Services\TenantUsageService;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Uso de um tenant específico: mês corrente ao vivo, histórico apurado e
 * comparação com o teto do plano contratado.
 *
 * Controller fino: a contagem em si (`usoAtual`) é toda de
 * `TenantUsageService`, criado na Task 5.6, e o histórico é uma consulta
 * simples em `TenantUsage`. O cálculo de percentual do teto fica aqui de
 * propósito (e não em Service): é uma regra mecânica de apresentação —
 * mapear limite nulo do plano para "ilimitado" — não uma regra de domínio
 * sobre o que o tenant pode ou não fazer.
 */
class UsageController extends Controller
{
    /**
     * Métricas de `TenantUsageService::usoAtual()` que têm um limite
     * correspondente em `Plan`, mapeadas para o respectivo campo de limite.
     *
     * `certificados` fica de fora de propósito: não existe
     * `Plan::limite_certificados`, só `limite_usuarios`, `limite_clientes`,
     * `limite_os_mes` e `limite_armazenamento_mb`. O número de certificados
     * continua em `usoAtual`/`historico`, só não entra em `percentuais`.
     *
     * @var array<string, string>
     */
    private const METRICA_PARA_LIMITE = [
        'usuarios' => 'limite_usuarios',
        'clientes' => 'limite_clientes',
        'ordens_servico' => 'limite_os_mes',
        'armazenamento_mb' => 'limite_armazenamento_mb',
    ];

    /**
     * Histórico de meses trazido pela Task 5.8: os registros mais recentes
     * de `tenant_usages` para este tenant.
     */
    private const MESES_DE_HISTORICO = 12;

    public function __construct(
        private readonly TenantUsageService $tenantUsageService,
    ) {}

    public function show(Company $company): Response
    {
        $company->loadMissing('plan');

        $usoAtual = $this->tenantUsageService->usoAtual($company);

        return Inertia::render('Plataforma/Tenants/Uso', [
            'tenant' => $company,
            'usoAtual' => $usoAtual,
            'historico' => TenantUsage::query()
                ->where('company_id', $company->id)
                ->orderByDesc('referencia')
                ->limit(self::MESES_DE_HISTORICO)
                ->get(),
            'percentuais' => $this->percentuaisDoTeto($company, $usoAtual),
        ]);
    }

    /**
     * Percentual de cada métrica de `METRICA_PARA_LIMITE` contra o limite
     * do plano vinculado.
     *
     * Limite nulo — porque o campo em `Plan` é nulo (o plano libera a
     * métrica, ver o cabeçalho do model `Plan`) ou porque o tenant não tem
     * plano vinculado (`plan_id` nulo, `$company->plan` então também nulo)
     * — sempre vira `null` aqui, nunca `0` nem erro de divisão por zero. O
     * frontend (Task 5.11) decide como desenhar "ilimitado" a partir de
     * `null`.
     *
     * @param  array{usuarios: int, clientes: int, ordens_servico: int, certificados: int, armazenamento_mb: int}  $usoAtual
     * @return array<string, int|null>
     */
    private function percentuaisDoTeto(Company $company, array $usoAtual): array
    {
        $plano = $company->plan;

        $percentuais = [];

        foreach (self::METRICA_PARA_LIMITE as $metrica => $campoDoLimite) {
            $limite = $plano?->{$campoDoLimite};

            // `max($limite, 1)` é só rede de segurança contra um eventual
            // limite gravado como 0 (não deveria acontecer, mas dividir por
            // zero derrubaria a página inteira por um dado de cadastro).
            $percentuais[$metrica] = $limite === null
                ? null
                : (int) round(($usoAtual[$metrica] / max($limite, 1)) * 100);
        }

        return $percentuais;
    }
}
