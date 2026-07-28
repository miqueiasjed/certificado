<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\FinancialEntry;
use App\Models\Module;
use App\Models\Plan;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\ModuleService;
use App\Services\TenantService;
use App\Support\BusinessDate;
use App\Support\TenantAtual;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Task 6.9 do Plano 6: os dois entregáveis mais importantes do plano, testados
 * por código.
 *
 * 1. Módulo bloqueado é inacessível por URL direta, mesmo escondido do menu
 *    (Task 6.8) e mesmo para quem tem a permissão do Plano 2
 *    (`EnsureModuleIsActive`, Task 6.4).
 * 2. Rebaixar plano ou bloquear módulo nunca apaga dado de domínio: o que
 *    muda é só a visibilidade (docblock de `ModuleService`).
 *
 * O caso 1 percorre `Route::getRoutes()` filtrando pelo middleware
 * `module:financeiro`, no mesmo critério já usado por
 * `AcessoFinanceiroTest::test_toda_rota_financeira_registrada_tem_middleware_de_autorizacao()`
 * e por `VazamentoEntreEmpresasTest::rotasDeVarredura()`: rota nova com esse
 * middleware entra na varredura sozinha, sem lista escrita à mão.
 *
 * Cuidado de teste que não existe em produção: `ModuleService::$cache` é uma
 * propriedade estática, memoizada por tenant durante o ciclo de uma única
 * requisição HTTP real (processo novo a cada request). Esta suíte simula
 * várias "requisições" no mesmo processo PHP, então uma alteração feita fora
 * dos três métodos que já chamam `esquecerCache()` sozinhos (`liberarPara`,
 * `bloquearPara`, `removerRegraPontual`) precisa disparar
 * `esquecerCache()` manualmente aqui para o próximo `ativo()` enxergar a
 * mudança. É o caso de `TenantService::trocarPlano()`, que só troca o
 * vínculo e não mexe em módulo: em produção o próximo request já nasce com o
 * cache vazio, mas dentro de um teste isso precisa ser feito à mão.
 */
class ModuloBloqueadoTest extends TestCase
{
    use RefreshDatabase;

    private ModuleService $moduleService;

    private TenantService $tenantService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        // Semeia o catálogo de módulos (chave `financeiro`, os `sempre_ativo`
        // etc.) e vincula o tenant fundador (id 1) ao Plano Interno. Os
        // tenants desta suíte nascem à parte, via `TenantService::criar()`,
        // sem esse plano.
        $this->seed(ModulesSeeder::class);

        $this->moduleService = app(ModuleService::class);
        $this->tenantService = app(TenantService::class);
    }

    // -----------------------------------------------------------------
    // Caso 1: cada rota financeira, achada pela coleção de rotas
    // -----------------------------------------------------------------

    /**
     * A descoberta de rotas aqui é por padrão de URL (`financial`,
     * `cash-flow`, `payment`), o mesmo critério e a mesma regra de
     * `AcessoFinanceiroTest::test_toda_rota_financeira_registrada_tem_middleware_de_autorizacao()`,
     * e não pelo middleware `module:financeiro` em si. A diferença importa: se
     * a varredura filtrasse pelo próprio middleware que este teste cobra,
     * tirar `module:financeiro` de uma única rota faria essa rota desaparecer
     * da varredura, sem derrubar teste nenhum, o oposto do que a task pede
     * ("remover o middleware de uma rota financeira faz o caso 1 falhar").
     * Descobrindo por URL, a rota continua na lista e a asserção estrutural
     * (`gatherMiddleware()`) falha exatamente para ela.
     */
    public function test_tenant_sem_modulo_financeiro_recebe_pagina_de_indisponivel_em_cada_rota_financeira(): void
    {
        [, $administrador] = $this->criarTenantSemFinanceiro();

        $rotasFinanceiras = collect(Route::getRoutes())
            ->filter(fn ($rota): bool => (bool) preg_match('/financial|cash-flow|payment/i', $rota->uri()));

        $this->assertGreaterThan(
            0,
            $rotasFinanceiras->count(),
            'nenhuma rota financeira foi encontrada: o filtro por padrão de URL pode ter quebrado'
        );

        $destino = route('modulo-indisponivel', ['modulo' => 'financeiro']);

        foreach ($rotasFinanceiras as $rota) {
            // Estrutural: toda rota financeira precisa carregar o middleware
            // de módulo. É esta linha que falha quando alguém tira
            // `module:financeiro` de uma rota só, sem tirar da varredura.
            $this->assertContains(
                'module:financeiro',
                $rota->gatherMiddleware(),
                "a rota '{$rota->uri()}' [".implode(',', $rota->methods())."] não tem o middleware module:financeiro"
            );

            // Funcional: nas rotas GET sem parâmetro, a URL direta também
            // precisa devolver a página de indisponível de fato, não só ter
            // o middleware declarado.
            if (in_array('GET', $rota->methods(), true) && ! str_contains($rota->uri(), '{')) {
                $uri = '/'.ltrim($rota->uri(), '/');

                $resposta = $this->actingAs($administrador)->get($uri);

                $resposta->assertRedirect($destino);
            }
        }
    }

    // -----------------------------------------------------------------
    // Caso 2: 403 JSON na rota que a própria SPA busca por fetch
    // -----------------------------------------------------------------

    public function test_tenant_sem_modulo_financeiro_recebe_403_json_em_rota_que_devolve_json(): void
    {
        [, $administrador] = $this->criarTenantSemFinanceiro();

        $resposta = $this->actingAs($administrador)->getJson('/financial-entries/stats');

        $resposta->assertStatus(403);
        $resposta->assertJson(['modulo' => 'financeiro']);
        $this->assertStringContainsString('Financeiro', (string) $resposta->json('message'));
    }

    // -----------------------------------------------------------------
    // Caso 3: tenant com o módulo acessa normalmente
    // -----------------------------------------------------------------

    public function test_tenant_com_modulo_financeiro_acessa_normalmente(): void
    {
        $moduloFinanceiro = $this->moduloFinanceiro();
        $plano = $this->criarPlano('Plano Com Financeiro Caso 3', [$moduloFinanceiro->id]);

        [, $administrador] = $this->criarTenant('Tenant Com Financeiro', 'admin-com-financeiro@exemplo.test', $plano);

        $destino = route('modulo-indisponivel', ['modulo' => 'financeiro']);

        foreach ($this->rotasDoModuloFinanceiro() as $rota) {
            $uri = '/'.ltrim($rota->uri(), '/');

            $resposta = $this->actingAs($administrador)->get($uri);

            $this->assertContains(
                $resposta->status(),
                [200, 302],
                "a rota GET {$uri} devolveu status inesperado {$resposta->status()} com o módulo financeiro ativo"
            );

            if ($resposta->status() === 302) {
                $this->assertNotSame(
                    $destino,
                    $resposta->headers->get('Location'),
                    "a rota GET {$uri} redirecionou para o módulo indisponível mesmo com o módulo financeiro ativo"
                );
            }
        }
    }

    // -----------------------------------------------------------------
    // Caso 4: permissão financeiro-ver não supera a falta do módulo
    // -----------------------------------------------------------------

    /**
     * `EnsureModuleIsActive` e o `permission:*` do Plano 2 são checagens
     * independentes: ter a permissão não adianta sem o módulo, e o inverso
     * (Caso 6, bloqueio pontual) também vale.
     */
    public function test_permissao_financeiro_ver_nao_supera_falta_do_modulo(): void
    {
        [, $usuarioFinanceiro] = $this->criarTenantSemFinanceiro('financeiro');

        $resposta = $this->actingAs($usuarioFinanceiro)->get('/financial-dashboard');

        $resposta->assertRedirect(route('modulo-indisponivel', ['modulo' => 'financeiro']));
    }

    // -----------------------------------------------------------------
    // Caso 5: sempre_ativo em tenant sem plano algum
    // -----------------------------------------------------------------

    /**
     * Nenhuma rota do sistema hoje usa `module:clientes` (os três
     * `sempre_ativo` são o produto mínimo, nunca gated por rota, ver o
     * comentário em `routes/web.php`), então a asserção é direto no Service
     * que decide isso, a mesma peça que o middleware consulta.
     */
    public function test_modulo_sempre_ativo_fica_acessivel_em_tenant_sem_plano_algum(): void
    {
        [$empresa] = $this->criarTenant('Tenant Sem Plano Algum', 'admin-sem-plano-algum@exemplo.test', null);

        foreach (['clientes', 'ordens_servico', 'certificados'] as $chave) {
            $this->assertTrue(
                $this->moduleService->ativo($chave, $empresa),
                "o módulo sempre_ativo '{$chave}' deveria estar acessível mesmo sem plano nenhum"
            );
        }

        $this->assertFalse(
            $this->moduleService->ativo('financeiro', $empresa),
            'o módulo financeiro não é sempre_ativo e não deveria estar ativo sem plano'
        );
    }

    // -----------------------------------------------------------------
    // Caso 6: bloqueio pontual vence o plano que libera
    // -----------------------------------------------------------------

    public function test_bloqueio_pontual_vence_o_plano_que_libera(): void
    {
        $moduloFinanceiro = $this->moduloFinanceiro();
        $plano = $this->criarPlano('Plano Com Financeiro Caso 6', [$moduloFinanceiro->id]);

        [$empresa, $administrador] = $this->criarTenant('Tenant Bloqueio Pontual', 'admin-bloqueio-pontual@exemplo.test', $plano);

        $this->assertTrue(
            $this->moduleService->ativo('financeiro', $empresa),
            'pré-condição: o plano deveria liberar o financeiro antes do bloqueio pontual'
        );

        $this->moduleService->bloquearPara($empresa, $moduloFinanceiro, 'Inadimplência para o teste');

        $this->assertFalse(
            $this->moduleService->ativo('financeiro', $empresa),
            'o bloqueio pontual deveria vencer o plano que libera o módulo'
        );

        $resposta = $this->actingAs($administrador)->get('/financial-dashboard');
        $resposta->assertRedirect(route('modulo-indisponivel', ['modulo' => 'financeiro']));
    }

    // -----------------------------------------------------------------
    // Caso 7: liberado_ate de ontem não libera, de hoje libera
    // -----------------------------------------------------------------

    public function test_liberacao_pontual_vencida_nao_libera_e_vigente_libera(): void
    {
        $moduloFinanceiro = $this->moduloFinanceiro();

        [$empresa] = $this->criarTenant('Tenant Liberacao Pontual', 'admin-liberacao-pontual@exemplo.test', null);

        $ontem = BusinessDate::hoje()->subDay()->toDateString();
        $hoje = BusinessDate::hoje()->toDateString();

        $this->moduleService->liberarPara($empresa, $moduloFinanceiro, 'Demonstração vencida', $ontem);

        $this->assertFalse(
            $this->moduleService->ativo('financeiro', $empresa),
            'liberação pontual com liberado_ate de ontem não deveria liberar o módulo'
        );

        $this->moduleService->liberarPara($empresa, $moduloFinanceiro, 'Demonstração vigente', $hoje);

        $this->assertTrue(
            $this->moduleService->ativo('financeiro', $empresa),
            'liberação pontual com liberado_ate de hoje deveria liberar o módulo'
        );
    }

    // -----------------------------------------------------------------
    // Caso 8: o caso mais importante do plano. Rebaixar plano não apaga dado.
    // -----------------------------------------------------------------

    /**
     * Cria dado de domínio dentro do financeiro enquanto o plano libera o
     * módulo, rebaixa o tenant para um plano sem o financeiro, e prova duas
     * coisas ao mesmo tempo: o módulo fica inacessível pela URL, e nenhum
     * lançamento financeiro criado antes desaparece. A contagem antes e
     * depois do rebaixamento tem que bater, e não pode ser zero.
     */
    public function test_rebaixar_plano_nao_apaga_dado_do_modulo_bloqueado(): void
    {
        $moduloFinanceiro = $this->moduloFinanceiro();
        $planoComFinanceiro = $this->criarPlano('Plano Com Financeiro Rebaixamento', [$moduloFinanceiro->id]);
        $planoSemFinanceiro = $this->criarPlano('Plano Sem Financeiro Rebaixamento', []);

        [$empresa, $administrador] = $this->criarTenant('Tenant Rebaixado', 'admin-rebaixado@exemplo.test', $planoComFinanceiro);

        TenantAtual::comTenant($empresa->id, function () use ($administrador): void {
            foreach ([100, 200, 300] as $valor) {
                FinancialEntry::create([
                    'source' => 'manual',
                    'amount' => $valor,
                    'description' => "Entrada financeira de {$valor} antes do rebaixamento",
                    'entry_date' => now()->toDateString(),
                    'status' => 'confirmed',
                    'created_by' => $administrador->id,
                ]);
            }
        });

        $antes = TenantAtual::comTenant($empresa->id, fn () => FinancialEntry::query()->count());
        $this->assertSame(3, $antes, 'o cenário precisa nascer com os três lançamentos financeiros');

        // Rebaixamento de verdade: o mesmo método que a área da plataforma usa.
        $this->tenantService->trocarPlano($empresa, $planoSemFinanceiro);

        // Ver o docblock da classe: `trocarPlano()` não mexe em módulo, então
        // não zera o cache sozinho. Fora de teste isso nunca é um problema.
        $this->moduleService->esquecerCache();

        $this->assertFalse(
            $this->moduleService->ativo('financeiro', $empresa->fresh()),
            'o módulo financeiro deveria ficar indisponível depois do rebaixamento de plano'
        );

        $depois = TenantAtual::comTenant($empresa->id, fn () => FinancialEntry::query()->count());

        $this->assertSame(
            $antes,
            $depois,
            'rebaixar o plano não pode apagar nenhum lançamento financeiro já existente'
        );

        // A URL direta barra o caminho, mas o dado, provado acima, continua inteiro.
        $resposta = $this->actingAs($administrador)->get('/financial-entries');
        $resposta->assertRedirect(route('modulo-indisponivel', ['modulo' => 'financeiro']));
    }

    // -----------------------------------------------------------------
    // Caso 9: reativar devolve o acesso e o dado continua lá
    // -----------------------------------------------------------------

    public function test_reativar_o_modulo_devolve_o_acesso_e_o_dado_continua_la(): void
    {
        $moduloFinanceiro = $this->moduloFinanceiro();
        $planoSemFinanceiro = $this->criarPlano('Plano Sem Financeiro Reativacao', []);

        [$empresa, $administrador] = $this->criarTenant('Tenant Reativacao', 'admin-reativacao@exemplo.test', $planoSemFinanceiro);

        TenantAtual::comTenant($empresa->id, function () use ($administrador): void {
            FinancialEntry::create([
                'source' => 'manual',
                'amount' => 250,
                'description' => 'Entrada financeira antes da reativação',
                'entry_date' => now()->toDateString(),
                'status' => 'confirmed',
                'created_by' => $administrador->id,
            ]);
        });

        $this->assertFalse(
            $this->moduleService->ativo('financeiro', $empresa),
            'pré-condição: o módulo deveria começar bloqueado, sem plano que o libere'
        );

        $respostaAntes = $this->actingAs($administrador)->get('/financial-entries');
        $respostaAntes->assertRedirect(route('modulo-indisponivel', ['modulo' => 'financeiro']));

        // Reativação pontual: o super admin libera o módulo para este tenant
        // específico, sem trocar o plano dele. `liberarPara()` já esquece o
        // cache sozinho.
        $this->moduleService->liberarPara($empresa, $moduloFinanceiro, 'Reativado manualmente para o cliente', null);

        $this->assertTrue(
            $this->moduleService->ativo('financeiro', $empresa->fresh()),
            'o módulo deveria voltar a ficar ativo depois da liberação pontual'
        );

        $respostaDepois = $this->actingAs($administrador)->get('/financial-entries');
        $respostaDepois->assertOk();

        $quantidade = TenantAtual::comTenant($empresa->id, fn () => FinancialEntry::query()->count());

        $this->assertSame(
            1,
            $quantidade,
            'o lançamento financeiro criado antes do bloqueio deveria continuar existindo depois da reativação'
        );
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function moduloFinanceiro(): Module
    {
        return Module::query()->where('chave', 'financeiro')->firstOrFail();
    }

    /**
     * Rotas GET, sem parâmetro na URL, que acumulam o middleware
     * `module:financeiro`. Sai da coleção de rotas registradas, não de uma
     * lista escrita à mão: rota financeira nova com este middleware entra
     * sozinha na próxima execução.
     *
     * @return \Illuminate\Support\Collection<int, \Illuminate\Routing\Route>
     */
    private function rotasDoModuloFinanceiro(): \Illuminate\Support\Collection
    {
        return collect(Route::getRoutes())
            ->filter(fn ($rota): bool => in_array('module:financeiro', $rota->gatherMiddleware(), true))
            ->filter(fn ($rota): bool => in_array('GET', $rota->methods(), true))
            ->filter(fn ($rota): bool => ! str_contains($rota->uri(), '{'))
            ->values();
    }

    /**
     * Plano comercial mínimo, com os módulos informados vinculados via
     * `plan_module`. Sem `chave` de módulo nenhuma, o plano libera só o que
     * é `sempre_ativo`.
     *
     * @param  array<int, int>  $moduloIds
     */
    private function criarPlano(string $nome, array $moduloIds): Plan
    {
        $plano = Plan::create([
            'nome' => $nome,
            'slug' => Str::slug($nome),
            'valor' => 100,
            'periodicidade' => 'mensal',
            'ativo' => true,
        ]);

        if ($moduloIds !== []) {
            $plano->modules()->attach($moduloIds);
        }

        return $plano;
    }

    /**
     * Tenant novo, com administrador, no plano informado (ou sem plano
     * nenhum quando `null`). Quando `$papel` diverge de `administrador`, o
     * usuário criado troca de papel, mantendo só a permissão daquele papel
     * (útil para o Caso 4, onde importa que só `financeiro-ver` esteja
     * presente, sem o resto do que `administrador` teria).
     *
     * @return array{0: Company, 1: User}
     */
    private function criarTenant(string $nome, string $emailAdministrador, ?Plan $plano, string $papel = 'administrador'): array
    {
        $empresa = $this->tenantService->criar([
            'name' => $nome,
            'plan_id' => $plano?->id,
            'administrador_nome' => 'Administrador de '.$nome,
            'administrador_email' => $emailAdministrador,
        ]);

        $usuario = User::where('email', $emailAdministrador)->firstOrFail();

        if ($papel !== 'administrador') {
            $usuario->syncRoles([$papel]);
        }

        return [$empresa->fresh(), $usuario->fresh()];
    }

    /**
     * Tenant sem plano nenhum, portanto sem o módulo financeiro por nenhuma
     * via (nem plano, nem liberação pontual).
     *
     * @return array{0: Company, 1: User}
     */
    private function criarTenantSemFinanceiro(string $papel = 'administrador'): array
    {
        return $this->criarTenant(
            "Tenant Sem Financeiro ({$papel})",
            "admin-sem-financeiro-{$papel}@exemplo.test",
            null,
            $papel
        );
    }
}
