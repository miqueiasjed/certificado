<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\WorkOrder;
use Carbon\Carbon;
use Database\Factories\WorkOrderFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Task 3.9 do Plano 3: cobre por teste o comportamento da trait Auditavel
 * (Task 3.2), a imutabilidade de AuditLog (Task 3.1), o endpoint de histórico
 * AuditLogController (Task 3.6) e o comando auditoria:purge (Task 3.8) sob o
 * ângulo de quem gera o log pela trait, não pelas asserções de fronteira UTC
 * já cobertas em PurgeAuditLogsTest.
 *
 * O teste 5 (AuditLog::first()->delete() lança exceção e o registro continua
 * no banco) é o que fecha o entregável "não pode ser apagada" do plano e não
 * pode ser afrouxado.
 */
class AuditoriaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    // -----------------------------------------------------------------
    // Seeder de papéis: quem enxerga auditoria e registro de acesso
    // -----------------------------------------------------------------

    /**
     * Caso adicional fora da especificação original da task, vindo de uma
     * correção feita durante o plano: auditoria-ver e acesso-log-ver expõem
     * dado sensível de todos os módulos (inclusive financeiro) e de quem
     * entrou no sistema e de onde, então só administrador pode ler. O papel
     * leitura recebe todas as demais permissões "-ver" do catálogo, mas não
     * estas duas.
     */
    public function test_papel_leitura_nao_recebe_auditoria_ver_nem_acesso_log_ver_e_administrador_recebe_as_duas(): void
    {
        $leitura = Role::findByName('leitura', 'web');
        $administrador = Role::findByName('administrador', 'web');

        $this->assertFalse($leitura->hasPermissionTo('auditoria-ver'), 'papel leitura não deveria receber a permissão auditoria-ver');
        $this->assertFalse($leitura->hasPermissionTo('acesso-log-ver'), 'papel leitura não deveria receber a permissão acesso-log-ver');

        $this->assertTrue($administrador->hasPermissionTo('auditoria-ver'), 'papel administrador deveria receber a permissão auditoria-ver');
        $this->assertTrue($administrador->hasPermissionTo('acesso-log-ver'), 'papel administrador deveria receber a permissão acesso-log-ver');
    }

    // -----------------------------------------------------------------
    // Criação: gera audit log com autor identificado
    // -----------------------------------------------------------------

    public function test_criar_ordem_de_servico_autenticado_gera_audit_log_de_criacao(): void
    {
        $autor = $this->criarUsuarioComPapel('administrador');

        $this->actingAs($autor);
        $ordem = WorkOrderFactory::new()->create();

        $log = AuditLog::query()
            ->where('auditable_type', WorkOrder::class)
            ->where('auditable_id', $ordem->id)
            ->where('acao', 'criado')
            ->first();

        $this->assertNotNull($log, 'deveria existir um audit log de criação para a ordem de serviço');
        $this->assertSame($autor->id, $log->user_id);
        $this->assertSame($autor->name, $log->autor_nome);
    }

    // -----------------------------------------------------------------
    // Alteração: apenas o campo alterado vai para antes/depois
    // -----------------------------------------------------------------

    public function test_alterar_um_campo_gera_audit_log_de_alteracao_so_com_o_campo_alterado(): void
    {
        $autor = $this->criarUsuarioComPapel('administrador');
        $ordem = WorkOrderFactory::new()->create(['observations' => 'Observação original']);

        $this->actingAs($autor);
        $ordem->update(['observations' => 'Observação alterada']);

        $log = AuditLog::query()
            ->where('auditable_type', WorkOrder::class)
            ->where('auditable_id', $ordem->id)
            ->where('acao', 'alterado')
            ->first();

        $this->assertNotNull($log, 'deveria existir um audit log de alteração para a ordem de serviço');
        $this->assertSame(['observations' => 'Observação original'], $log->valores_antes);
        $this->assertSame(['observations' => 'Observação alterada'], $log->valores_depois);
    }

    // -----------------------------------------------------------------
    // Salvar sem mudar nada não polui o histórico
    // -----------------------------------------------------------------

    public function test_salvar_sem_alterar_nada_nao_gera_registro_novo(): void
    {
        $autor = $this->criarUsuarioComPapel('administrador');
        $ordem = WorkOrderFactory::new()->create(['observations' => 'Observação original']);

        // Busca o registro de novo, como o route-model-binding faria numa
        // requisição real: a instância recém-criada em memória não tem toda
        // coluna nula hidratada em $original, o que faria um resave sem
        // mudança real parecer "sujo" só por causa de como o teste monta o
        // cenário, não por um comportamento da trait.
        $ordemFresca = $ordem->fresh();

        $totalAntes = AuditLog::query()->count();

        $this->actingAs($autor);
        $ordemFresca->update(['observations' => $ordemFresca->observations]);

        $this->assertSame(
            $totalAntes,
            AuditLog::query()->count(),
            'salvar sem alterar nenhum campo não deveria gerar um novo audit log'
        );
    }

    // -----------------------------------------------------------------
    // Exclusão
    // -----------------------------------------------------------------

    public function test_excluir_gera_audit_log_de_exclusao(): void
    {
        $autor = $this->criarUsuarioComPapel('administrador');
        $ordem = WorkOrderFactory::new()->create();
        $id = $ordem->id;

        $this->actingAs($autor);
        $ordem->delete();

        $log = AuditLog::query()
            ->where('auditable_type', WorkOrder::class)
            ->where('auditable_id', $id)
            ->where('acao', 'excluido')
            ->first();

        $this->assertNotNull($log, 'deveria existir um audit log de exclusão para a ordem de serviço');
    }

    // -----------------------------------------------------------------
    // Imutabilidade: nem exclusão nem alteração pela aplicação
    // -----------------------------------------------------------------

    /**
     * Fecha o entregável "não pode ser apagada" do plano. Não afrouxar.
     */
    public function test_audit_log_delete_lanca_excecao_e_o_registro_continua_no_banco(): void
    {
        $ordem = WorkOrderFactory::new()->create();
        $log = AuditLog::query()->where('auditable_id', $ordem->id)->firstOrFail();

        $capturou = false;

        try {
            AuditLog::first()->delete();
        } catch (\RuntimeException $e) {
            $capturou = true;
        }

        $this->assertTrue($capturou, 'AuditLog::delete() deveria lançar RuntimeException');
        $this->assertDatabaseHas('audit_logs', ['id' => $log->id]);
    }

    public function test_audit_log_update_lanca_excecao(): void
    {
        WorkOrderFactory::new()->create();

        $capturou = false;

        try {
            AuditLog::first()->update(['acao' => 'x']);
        } catch (\RuntimeException $e) {
            $capturou = true;
        }

        $this->assertTrue($capturou, 'AuditLog::update() deveria lançar RuntimeException');
    }

    // -----------------------------------------------------------------
    // Senha nunca vai para o log
    // -----------------------------------------------------------------

    /**
     * A troca de senha vem acompanhada da troca do nome para forçar a
     * geração de um audit log de "alterado": senha sozinha não gera log
     * nenhum, porque password está em $naoAuditar e o piso de campos
     * sensíveis da trait, então o depois filtrado fica vazio. Combinar com
     * outro campo garante um log para inspecionar e confirmar que password
     * não aparece nele.
     */
    public function test_alterar_a_senha_de_um_usuario_nao_grava_password_em_nenhum_campo_do_log(): void
    {
        $usuario = User::factory()->create(['name' => 'Nome original']);

        $usuario->update([
            'password' => 'nova-senha-secreta-123',
            'name' => 'Nome alterado',
        ]);

        $log = AuditLog::query()
            ->where('auditable_type', User::class)
            ->where('auditable_id', $usuario->id)
            ->where('acao', 'alterado')
            ->firstOrFail();

        $this->assertArrayNotHasKey('password', $log->valores_antes ?? []);
        $this->assertArrayNotHasKey('password', $log->valores_depois ?? []);
        $this->assertSame(['name' => 'Nome alterado'], $log->valores_depois);

        $bruto = json_encode([$log->valores_antes, $log->valores_depois]);
        $this->assertStringNotContainsString('nova-senha-secreta-123', $bruto);
    }

    // -----------------------------------------------------------------
    // Endpoint de histórico: permissão auditoria-ver
    // -----------------------------------------------------------------

    public function test_get_audit_logs_sem_a_permissao_devolve_403_e_com_a_permissao_devolve_os_registros(): void
    {
        $ordem = WorkOrderFactory::new()->create();

        $semPermissao = $this->criarUsuarioComPapel('tecnico');
        $respostaSemPermissao = $this->actingAs($semPermissao)->get("/audit-logs/ordem-servico/{$ordem->id}");
        $respostaSemPermissao->assertForbidden();

        $comPermissao = $this->criarUsuarioComPapel('administrador');
        $respostaComPermissao = $this->actingAs($comPermissao)->get("/audit-logs/ordem-servico/{$ordem->id}");

        $respostaComPermissao->assertOk();

        $registros = $respostaComPermissao->json('data');

        $this->assertNotEmpty($registros, 'a resposta deveria trazer o histórico de auditoria da ordem de serviço');
        $this->assertSame('criado', $registros[0]['acao']);
    }

    // -----------------------------------------------------------------
    // Endpoint de histórico: tipo fora da lista fechada devolve 404
    // -----------------------------------------------------------------

    public function test_get_audit_logs_com_tipo_inventado_devolve_404(): void
    {
        $administrador = $this->criarUsuarioComPapel('administrador');

        $resposta = $this->actingAs($administrador)->get('/audit-logs/tipo-inventado/1');

        $resposta->assertNotFound();
    }

    // -----------------------------------------------------------------
    // Expurgo: remove os antigos e a tabela continua funcional
    // -----------------------------------------------------------------

    /**
     * A fronteira exata de retenção em UTC já está coberta, teste a teste,
     * em PurgeAuditLogsTest. Este teste cobre outro ângulo: um audit log
     * nascido pela trait Auditavel (não inserido direto via DB::table) some
     * do banco depois do expurgo, e a tabela continua aceitando escrita
     * normal do Eloquent logo em seguida.
     */
    public function test_auditoria_purge_dias_zero_remove_registros_antigos_e_mantem_a_tabela_funcional(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 12:00:00', 'UTC'));
        $ordemAntiga = WorkOrderFactory::new()->create();

        Carbon::setTestNow(Carbon::parse('2026-07-26 12:00:00', 'UTC'));

        $this->artisan('auditoria:purge', ['--dias' => 0])->assertSuccessful();

        $this->assertFalse(
            AuditLog::query()->where('auditable_id', $ordemAntiga->id)->exists(),
            'audit log antigo deveria ter sido removido pelo expurgo'
        );

        $ordemNova = WorkOrderFactory::new()->create();

        $this->assertTrue(
            AuditLog::query()
                ->where('auditable_type', WorkOrder::class)
                ->where('auditable_id', $ordemNova->id)
                ->where('acao', 'criado')
                ->exists(),
            'a tabela audit_logs deveria continuar aceitando gravação normal depois do expurgo'
        );

        Carbon::setTestNow();
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function criarUsuarioComPapel(string $papel): User
    {
        $usuario = User::factory()->create(['is_active' => true]);
        $usuario->assignRole($papel);

        return $usuario;
    }
}
