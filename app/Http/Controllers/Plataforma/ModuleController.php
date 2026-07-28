<?php

namespace App\Http\Controllers\Plataforma;

use App\Http\Controllers\Controller;
use App\Http\Requests\Plataforma\ModuleAssignmentRequest;
use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\Module;
use App\Models\Plan;
use App\Services\ModuleService;
use App\Support\BusinessDate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Gestão dos módulos controláveis do produto (Plano 6), na área da
 * plataforma: o que cada plano libera e a exceção pontual de cada tenant.
 *
 * Controller fino: toda a decisão de precedência (sempre_ativo > bloqueio
 * pontual > liberação pontual > plano) já vive em `ModuleService` (Task 6.3).
 * Aqui só há a validação (`ModuleAssignmentRequest`), duas recusas de negócio
 * que a Task 6.7 pede explicitamente (módulo `sempre_ativo` não sai de plano
 * nem é bloqueado em tenant algum) e a montagem da tela/resposta.
 *
 * Nenhum método daqui apaga dado de domínio: alterar `plan_module` ou
 * `company_modules` só muda a visibilidade do módulo, nunca cliente, OS ou
 * certificado (ver o cabeçalho de `ModuleService`).
 */
class ModuleController extends Controller
{
    /**
     * Origem de um módulo ativo, na mesma ordem de precedência de
     * `ModuleService::calcularAtivos()`. Duplicada aqui de propósito: é rótulo
     * de exibição para `porTenant()`, e `ModuleService` não expõe a origem,
     * só o booleano final (`ativo()`). Mudar a ordem de precedência exige
     * mudar as duas classes juntas.
     */
    private const ORIGEM_SEMPRE_ATIVO = 'sempre_ativo';

    private const ORIGEM_BLOQUEIO_PONTUAL = 'bloqueio_pontual';

    private const ORIGEM_LIBERACAO_PONTUAL = 'liberacao_pontual';

    private const ORIGEM_PLANO = 'plano';

    private const ORIGEM_NENHUM = 'nenhum';

    public function __construct(
        private readonly ModuleService $moduleService,
    ) {}

    /**
     * Catálogo de módulos com quantos planos e quantos tenants usam cada um.
     *
     * "Quantos tenants usam" é calculado com `ModuleService::ativosPara()`,
     * tenant por tenant, e não por uma contagem direta da pivot: um módulo
     * `sempre_ativo` não tem linha em `plan_module` nem em `company_modules`,
     * e mesmo assim está ativo para todo mundo. Só o Service sabe disso.
     * Custo aceitável aqui: tela de administração da plataforma, não caminho
     * de requisição de tenant.
     */
    public function index(): Response
    {
        $modulos = Module::query()->withCount('plans')->orderBy('ordem')->get();

        $tenantsPorModulo = array_fill_keys($modulos->pluck('chave')->all(), 0);

        Company::query()->select(['id', 'plan_id'])->each(function (Company $empresa) use (&$tenantsPorModulo): void {
            foreach ($this->moduleService->ativosPara($empresa) as $chave) {
                $tenantsPorModulo[$chave] = ($tenantsPorModulo[$chave] ?? 0) + 1;
            }
        });

        return Inertia::render('Plataforma/Modulos/Index', [
            'modulos' => $modulos->map(fn (Module $modulo): array => [
                'id' => $modulo->id,
                'chave' => $modulo->chave,
                'nome' => $modulo->nome,
                'descricao' => $modulo->descricao,
                'sempre_ativo' => $modulo->sempre_ativo,
                'planos_count' => $modulo->plans_count,
                'tenants_count' => $tenantsPorModulo[$modulo->chave] ?? 0,
            ])->values(),
        ]);
    }

    /**
     * Tela de edição dos módulos de um plano: o catálogo inteiro, com
     * `liberado = true` para quem já está vinculado (mais os `sempre_ativo`,
     * sempre marcados e desabilitados no frontend, ver Task 6.8).
     */
    public function porPlano(Plan $plano): Response
    {
        $moduloIdsDoPlano = $plano->modules()->pluck('modules.id')->all();

        return Inertia::render('Plataforma/Modulos/PorPlano', [
            // `loadCount`, não uma contagem solta: `Planos/Index.vue` (Task 5.7)
            // já lê `companies_count` do mesmo jeito (via `withCount('companies')`
            // em `PlanController::index()`). A Task 6.8 precisa saber quantos
            // tenants serão afetados *antes* de salvar, para o modal de
            // confirmação, e reusar o nome já estabelecido evita inventar um
            // segundo formato para a mesma contagem.
            'plano' => $plano->loadCount('companies'),
            'modulos' => Module::query()->orderBy('ordem')->get()->map(fn (Module $modulo): array => [
                'id' => $modulo->id,
                'chave' => $modulo->chave,
                'nome' => $modulo->nome,
                'descricao' => $modulo->descricao,
                'sempre_ativo' => $modulo->sempre_ativo,
                'liberado' => $modulo->sempre_ativo || in_array($modulo->id, $moduloIdsDoPlano, true),
            ])->values(),
        ]);
    }

    /**
     * Sincroniza os módulos liberados pelo plano.
     *
     * Recusa em bloco (nenhuma alteração é aplicada) quando algum módulo
     * `sempre_ativo` está faltando na lista enviada: é a regra explícita da
     * Task 6.7, "recusar com mensagem clara", e não "manter o sempre_ativo
     * ligado por baixo dos panos" enquanto finge aceitar o pedido.
     *
     * A alteração vale para todos os tenants do plano imediatamente (mesmo
     * critério de `ModuleService`), por isso a mensagem devolve quantos
     * tenants foram afetados: sem isso a ação seria tomada às cegas.
     */
    public function salvarPlano(ModuleAssignmentRequest $request, Plan $plano): RedirectResponse
    {
        $moduloIdsSolicitados = array_map('intval', $request->validated('module_ids', []));

        $modulosSempreAtivosFaltando = Module::query()
            ->where('sempre_ativo', true)
            ->whereNotIn('id', $moduloIdsSolicitados)
            ->pluck('nome');

        if ($modulosSempreAtivosFaltando->isNotEmpty()) {
            return back()->with(
                'error',
                sprintf(
                    'Os módulos %s são sempre ativos e não podem ser desmarcados de plano nenhum.',
                    $modulosSempreAtivosFaltando->implode(', ')
                )
            );
        }

        $plano->modules()->sync($moduloIdsSolicitados);

        // `sync()` grava direto na pivot, sem passar por `ModuleService`: quem
        // esquece o cache aqui é o controller, não o Service (Task 6.7 pede
        // isso explicitamente após qualquer alteração).
        $this->moduleService->esquecerCache();

        $tenantsAfetados = Company::query()->where('plan_id', $plano->id)->count();

        return back()->with(
            'success',
            "Módulos do plano \"{$plano->nome}\" atualizados. Isso afeta {$tenantsAfetados} tenant(s) imediatamente."
        );
    }

    /**
     * Módulos de um tenant específico: se está ativo e de onde vem a decisão
     * (sempre ativo, bloqueio pontual, liberação pontual ou plano), na mesma
     * ordem de precedência que `ModuleService` aplica de verdade.
     */
    public function porTenant(Company $empresa): Response
    {
        $regrasPontuais = CompanyModule::query()
            ->where('company_id', $empresa->id)
            ->get()
            ->keyBy('module_id');

        $moduloIdsDoPlano = $empresa->plan_id !== null
            ? DB::table('plan_module')->where('plan_id', $empresa->plan_id)->pluck('module_id')->all()
            : [];

        $modulos = Module::query()->orderBy('ordem')->get()->map(function (Module $modulo) use ($regrasPontuais, $moduloIdsDoPlano): array {
            /** @var CompanyModule|null $regra */
            $regra = $regrasPontuais->get($modulo->id);

            [$ativo, $origem] = $this->resolverOrigem($modulo, $regra, $moduloIdsDoPlano);

            return [
                'id' => $modulo->id,
                'chave' => $modulo->chave,
                'nome' => $modulo->nome,
                'descricao' => $modulo->descricao,
                'sempre_ativo' => $modulo->sempre_ativo,
                'ativo' => $ativo,
                'origem' => $origem,
                'motivo' => $regra?->motivo,
                'liberado_ate' => $regra?->liberado_ate?->toDateString(),
            ];
        })->values();

        return Inertia::render('Plataforma/Modulos/PorTenant', [
            'tenant' => $empresa->load('plan:id,nome'),
            'modulos' => $modulos,
        ]);
    }

    /**
     * Libera um módulo pontualmente para o tenant, fora do que o plano dele
     * define. Ativa imediatamente: `ModuleService::liberarPara()` já esquece o
     * cache sozinho.
     */
    public function liberarNoTenant(ModuleAssignmentRequest $request, Company $empresa): RedirectResponse
    {
        $modulo = Module::findOrFail($request->validated('module_id'));

        $this->moduleService->liberarPara(
            $empresa,
            $modulo,
            $request->validated('motivo'),
            $request->validated('liberado_ate')
        );

        return back()->with('success', "Módulo \"{$modulo->nome}\" liberado para \"{$empresa->name}\".");
    }

    /**
     * Bloqueia um módulo pontualmente para o tenant, mesmo que o plano dele o
     * libere. Recusa módulo `sempre_ativo`, de acordo com a regra da Task 6.7:
     * clientes, ordens de serviço e certificados nunca ficam indisponíveis
     * para um tenant.
     */
    public function bloquearNoTenant(ModuleAssignmentRequest $request, Company $empresa): RedirectResponse
    {
        $modulo = Module::findOrFail($request->validated('module_id'));

        if ($modulo->sempre_ativo) {
            return back()->with(
                'error',
                "O módulo \"{$modulo->nome}\" é sempre ativo e não pode ser bloqueado."
            );
        }

        $this->moduleService->bloquearPara($empresa, $modulo, $request->validated('motivo'));

        return back()->with('success', "Módulo \"{$modulo->nome}\" bloqueado para \"{$empresa->name}\".");
    }

    /**
     * Remove a regra pontual (liberação ou bloqueio) do tenant para o módulo,
     * e o acesso volta a ser decidido só pelo plano. Não apaga dado de
     * domínio, só a exceção pontual (ver o cabeçalho de
     * `ModuleService::removerRegraPontual()`).
     */
    public function removerRegraNoTenant(ModuleAssignmentRequest $request, Company $empresa): RedirectResponse
    {
        $modulo = Module::findOrFail($request->validated('module_id'));

        $this->moduleService->removerRegraPontual($empresa, $modulo);

        return back()->with(
            'success',
            "Regra pontual do módulo \"{$modulo->nome}\" removida de \"{$empresa->name}\". O acesso volta a valer pelo plano."
        );
    }

    /**
     * Ativo e origem de um módulo para o tenant, na ordem de precedência de
     * `ModuleService::calcularAtivos()`: sempre_ativo, bloqueio pontual,
     * liberação pontual (não vencida) e por fim o plano.
     *
     * @param  array<int, int>  $moduloIdsDoPlano
     * @return array{0: bool, 1: string}
     */
    private function resolverOrigem(Module $modulo, ?CompanyModule $regra, array $moduloIdsDoPlano): array
    {
        if ($modulo->sempre_ativo) {
            return [true, self::ORIGEM_SEMPRE_ATIVO];
        }

        if ($regra !== null && $regra->liberado === false) {
            return [false, self::ORIGEM_BLOQUEIO_PONTUAL];
        }

        if ($regra !== null && $regra->liberado === true && ! BusinessDate::estaVencido($regra->liberado_ate)) {
            return [true, self::ORIGEM_LIBERACAO_PONTUAL];
        }

        if (in_array($modulo->getKey(), $moduloIdsDoPlano, true)) {
            return [true, self::ORIGEM_PLANO];
        }

        return [false, self::ORIGEM_NENHUM];
    }
}
