<?php

namespace App\Http\Controllers\Plataforma;

use App\Exceptions\TenantInternoException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Plataforma\TenantRequest;
use App\Http\Requests\Plataforma\TenantSuspenderRequest;
use App\Models\Company;
use App\Models\Plan;
use App\Models\TenantUsage;
use App\Services\TenantService;
use App\Services\TenantUsageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CRUD de tenants e ações de ciclo de vida, na área da plataforma.
 *
 * Controller fino: toda regra de negócio (nascimento do tenant com o
 * administrador, suspensão, reativação) vive em `TenantService`, criado na
 * Task 5.4. Aqui só há validação (via `TenantRequest`/`TenantSuspenderRequest`),
 * a chamada ao Service e a resposta Inertia.
 *
 * `Company` não usa `BelongsToCompany` (não é model de domínio, é a própria
 * tabela de tenants), então toda consulta deste controller já enxerga todos
 * os tenants sem precisar de `TenantAtual::semEscopo()` — o middleware
 * `platform.admin` já ligou isso para a requisição inteira, mas nem seria
 * necessário para este model específico.
 */
class TenantController extends Controller
{
    /**
     * Colunas pelas quais a listagem pode ser ordenada. Lista de permissão
     * explícita: `ordenar_por` vem da query string, e um valor fora daqui
     * cairia direto num `ORDER BY` com nome de coluna vindo do cliente.
     *
     * @var array<int, string>
     */
    private const COLUNAS_DE_ORDENACAO = ['name', 'created_at', 'ultimo_acesso_em'];

    public function __construct(
        private readonly TenantService $tenantService,
        private readonly TenantUsageService $tenantUsageService,
    ) {}

    public function index(Request $request): Response
    {
        $ordenarPor = $request->string('ordenar_por')->toString();
        $ordenarPor = in_array($ordenarPor, self::COLUNAS_DE_ORDENACAO, true) ? $ordenarPor : 'name';
        $direcao = $request->string('direcao')->toString() === 'desc' ? 'desc' : 'asc';

        $tenants = Company::query()
            ->with('plan:id,nome')
            ->when(
                $request->filled('situacao'),
                fn ($query) => $query->where('situacao', $request->string('situacao')->toString())
            )
            ->when(
                $request->filled('plan_id'),
                fn ($query) => $query->where('plan_id', $request->integer('plan_id'))
            )
            ->when($request->filled('busca'), function ($query) use ($request) {
                $busca = $request->string('busca')->toString();

                $query->where(function ($query) use ($busca) {
                    $query->where('name', 'like', "%{$busca}%")
                        ->orWhere('cnpj', 'like', "%{$busca}%");
                });
            })
            ->orderBy($ordenarPor, $direcao)
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Plataforma/Tenants/Index', [
            'tenants' => $tenants,
            'planos' => Plan::query()->orderBy('nome')->get(['id', 'nome']),
            'situacoes' => TenantService::SITUACOES,
            'filtros' => $request->only(['situacao', 'plan_id', 'busca', 'ordenar_por', 'direcao']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Plataforma/Tenants/Create', [
            'planos' => Plan::query()->ativos()->get(['id', 'nome']),
        ]);
    }

    public function store(TenantRequest $request): RedirectResponse
    {
        $empresa = $this->tenantService->criar($request->validated());

        return redirect()->route('plataforma.tenants.show', $empresa)
            ->with('success', "Tenant \"{$empresa->name}\" criado com sucesso.");
    }

    /**
     * Detalhe do tenant: cadastro, uso do mês corrente (Task 5.6) e o
     * histórico de apuração mensal já gravado em `tenant_usages`.
     */
    public function show(Company $company): Response
    {
        return Inertia::render('Plataforma/Tenants/Show', [
            'tenant' => $company->load('plan'),
            'usoAtual' => $this->tenantUsageService->usoAtual($company),
            'historicoDeUso' => TenantUsage::query()
                ->where('company_id', $company->id)
                ->orderByDesc('referencia')
                ->get(),
        ]);
    }

    public function edit(Company $company): Response
    {
        return Inertia::render('Plataforma/Tenants/Edit', [
            'tenant' => $company,
            'planos' => Plan::query()->orderBy('nome')->get(['id', 'nome']),
        ]);
    }

    public function update(TenantRequest $request, Company $company): RedirectResponse
    {
        $this->tenantService->atualizar($company, $request->validated());

        return redirect()->route('plataforma.tenants.show', $company)
            ->with('success', "Tenant \"{$company->name}\" atualizado com sucesso.");
    }

    /**
     * Suspende o tenant. `TenantInternoException` é a recusa de negócio (o
     * tenant interno nunca é suspenso, regra de `TenantService::suspender()`)
     * e vira mensagem clara na tela, sem alterar nada. Qualquer outra exceção
     * sobe normalmente, para o handler global e o log da aplicação.
     */
    public function suspender(TenantSuspenderRequest $request, Company $company): RedirectResponse
    {
        try {
            $this->tenantService->suspender($company, $request->validated('motivo'));
        } catch (TenantInternoException $excecao) {
            return back()->with('error', $excecao->getMessage());
        }

        return back()->with('success', "Tenant \"{$company->name}\" suspenso.");
    }

    public function reativar(Company $company): RedirectResponse
    {
        $this->tenantService->reativar($company);

        return back()->with('success', "Tenant \"{$company->name}\" reativado.");
    }
}
