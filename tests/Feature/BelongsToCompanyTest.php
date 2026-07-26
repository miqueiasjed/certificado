<?php

namespace Tests\Feature;

use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use App\Support\TenantAtual;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Task 4.6 do Plano 4: cobre `App\Support\TenantAtual` e a trait
 * `App\Models\Concerns\BelongsToCompany`.
 *
 * A trait ainda não está aplicada a nenhum model do sistema, porque isso é a
 * Task 4.8. Para provar que ela funciona antes disso, o teste cria duas tabelas
 * próprias e dois models de mentira que a usam. As tabelas nascem no `setUp` e
 * são removidas no `tearDown`, e a coluna `company_id` delas é `NOT NULL
 * DEFAULT 1`, exatamente o estado em que as tabelas de domínio ficam em
 * produção entre o Deploy 3 e o Deploy 5.
 *
 * Nada aqui depende do schema de domínio: quando a trait entrar nos models
 * reais, quem cobre o isolamento de verdade é o teste de vazamento da Task 4.11.
 */
class BelongsToCompanyTest extends TestCase
{
    private const TABELA_PAI = 'tenant_scope_test_parents';

    private const TABELA_FILHA = 'tenant_scope_test_children';

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();
        $this->criarTabelasDeTeste();
    }

    protected function tearDown(): void
    {
        $this->removerTabelasDeTeste();
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // TenantAtual: resolução do tenant
    // -----------------------------------------------------------------

    public function test_sem_tenant_explicito_e_sem_usuario_autenticado_o_id_e_null(): void
    {
        $this->assertNull(TenantAtual::id());
        $this->assertFalse(TenantAtual::definido());
    }

    public function test_id_resolve_pelo_company_id_do_usuario_autenticado(): void
    {
        $this->autenticarUsuarioDaEmpresa(7);

        $this->assertSame(7, TenantAtual::id());
    }

    public function test_tenant_explicito_tem_precedencia_sobre_o_usuario_autenticado(): void
    {
        $this->autenticarUsuarioDaEmpresa(7);
        TenantAtual::definir(2);

        $this->assertSame(2, TenantAtual::id());
        $this->assertTrue(TenantAtual::definido());

        TenantAtual::limpar();

        $this->assertSame(7, TenantAtual::id());
    }

    public function test_com_tenant_restaura_o_valor_anterior_mesmo_com_excecao(): void
    {
        TenantAtual::definir(1);

        try {
            TenantAtual::comTenant(2, function (): void {
                $this->assertSame(2, TenantAtual::id());

                throw new RuntimeException('falha proposital dentro do callback');
            });

            $this->fail('A exceção do callback deveria ter sido propagada.');
        } catch (RuntimeException $erro) {
            $this->assertSame('falha proposital dentro do callback', $erro->getMessage());
        }

        $this->assertSame(1, TenantAtual::id());
    }

    public function test_com_tenant_aninhado_devolve_cada_nivel_ao_valor_anterior(): void
    {
        $resultado = TenantAtual::comTenant(1, function (): string {
            $interno = TenantAtual::comTenant(2, function (): string {
                $this->assertSame(2, TenantAtual::id());

                return 'interno';
            });

            $this->assertSame(1, TenantAtual::id());

            return $interno;
        });

        $this->assertSame('interno', $resultado);
        $this->assertNull(TenantAtual::id());
    }

    public function test_exigir_id_lanca_excecao_quando_nao_ha_tenant_resolvido(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Nenhuma empresa resolvida para esta operação.');

        TenantAtual::exigirId();
    }

    public function test_exigir_id_devolve_o_tenant_quando_ele_existe(): void
    {
        TenantAtual::definir(3);

        $this->assertSame(3, TenantAtual::exigirId());
    }

    // -----------------------------------------------------------------
    // Preenchimento de company_id na criação
    // -----------------------------------------------------------------

    public function test_criacao_dentro_de_um_tenant_preenche_company_id(): void
    {
        $registro = TenantAtual::comTenant(2, fn () => ModeloDeDominioFalso::create(['nome' => 'da empresa 2']));

        $this->assertSame(2, (int) $registro->company_id);
        $this->assertSame(2, (int) DB::table(self::TABELA_PAI)->where('id', $registro->id)->value('company_id'));
    }

    public function test_criacao_respeita_o_company_id_informado_explicitamente(): void
    {
        $registro = TenantAtual::comTenant(2, fn () => ModeloDeDominioFalso::create([
            'nome' => 'importação para a empresa 5',
            'company_id' => 5,
        ]));

        $this->assertSame(5, (int) $registro->company_id);
    }

    public function test_criacao_sem_tenant_nao_grava_null_e_deixa_o_padrao_do_banco_agir(): void
    {
        $registro = ModeloDeDominioFalso::create(['nome' => 'sem tenant resolvido']);

        // O atributo nem chega a ser tocado: quem responde é o DEFAULT da
        // coluna, como acontece em produção entre o Deploy 3 e o Deploy 5.
        $this->assertArrayNotHasKey('company_id', $registro->getAttributes());
        $this->assertSame(1, (int) DB::table(self::TABELA_PAI)->where('id', $registro->id)->value('company_id'));
    }

    public function test_model_sem_escopo_de_leitura_continua_preenchendo_company_id_na_criacao(): void
    {
        $registro = TenantAtual::comTenant(2, fn () => ModeloSemEscopoDeLeituraFalso::create(['nome' => 'caso User']));

        $this->assertSame(2, (int) $registro->company_id);
    }

    // -----------------------------------------------------------------
    // Escopo global de leitura
    // -----------------------------------------------------------------

    public function test_consulta_filtra_pelo_tenant_corrente(): void
    {
        $this->popularOsDoisTenants();

        $nomes = TenantAtual::comTenant(1, fn () => ModeloDeDominioFalso::query()->pluck('nome')->all());

        $this->assertSame(['pai da empresa 1'], $nomes);
    }

    public function test_registro_de_outro_tenant_nao_e_encontrado_por_id(): void
    {
        $this->popularOsDoisTenants();
        $daEmpresaDois = DB::table(self::TABELA_PAI)->where('company_id', 2)->value('id');

        $encontrado = TenantAtual::comTenant(1, fn () => ModeloDeDominioFalso::find($daEmpresaDois));

        $this->assertNull($encontrado);
    }

    public function test_sem_tenant_resolvido_o_escopo_nao_filtra_nada(): void
    {
        $this->popularOsDoisTenants();

        $this->assertSame(2, ModeloDeDominioFalso::query()->count());
    }

    public function test_a_coluna_do_filtro_vem_qualificada_com_a_tabela(): void
    {
        $sql = TenantAtual::comTenant(1, fn () => ModeloDeDominioFalso::query()->toSql());

        $this->assertStringContainsString(self::TABELA_PAI.'`.`company_id', $sql);
    }

    public function test_join_entre_dois_models_com_a_trait_nao_gera_coluna_ambigua(): void
    {
        $this->popularOsDoisTenants();
        $tabelaFilha = (new ModeloFilhoFalso)->getTable();

        $nomes = TenantAtual::comTenant(1, fn () => ModeloDeDominioFalso::query()
            ->join($tabelaFilha, $tabelaFilha.'.parent_id', '=', self::TABELA_PAI.'.id')
            ->pluck($tabelaFilha.'.nome')
            ->all());

        $this->assertSame(['filho da empresa 1'], $nomes);
    }

    public function test_relacionamento_carregado_tambem_respeita_o_escopo(): void
    {
        $this->popularOsDoisTenants();

        $filhos = TenantAtual::comTenant(1, fn () => ModeloDeDominioFalso::query()
            ->with('filhos')
            ->get()
            ->pluck('filhos')
            ->flatten()
            ->pluck('nome')
            ->all());

        $this->assertSame(['filho da empresa 1'], $filhos);
    }

    public function test_model_sem_escopo_de_leitura_enxerga_todos_os_tenants(): void
    {
        $this->popularOsDoisTenants();

        $total = TenantAtual::comTenant(1, fn () => ModeloSemEscopoDeLeituraFalso::query()->count());

        $this->assertSame(2, $total);
    }

    // -----------------------------------------------------------------
    // Saídas explícitas do isolamento
    // -----------------------------------------------------------------

    public function test_de_todas_as_empresas_traz_registros_de_todos_os_tenants(): void
    {
        $this->popularOsDoisTenants();

        $total = TenantAtual::comTenant(1, fn () => ModeloDeDominioFalso::deTodasAsEmpresas()->count());

        $this->assertSame(2, $total);
    }

    public function test_sem_escopo_traz_registros_de_todos_os_tenants(): void
    {
        $this->popularOsDoisTenants();

        $total = TenantAtual::comTenant(1, fn () => TenantAtual::semEscopo(
            fn () => ModeloDeDominioFalso::query()->count()
        ));

        $this->assertSame(2, $total);
    }

    public function test_sem_escopo_religa_o_escopo_ao_sair_mesmo_com_excecao(): void
    {
        $this->popularOsDoisTenants();

        try {
            TenantAtual::semEscopo(function (): void {
                $this->assertFalse(TenantAtual::escopoAtivo());

                throw new RuntimeException('falha proposital dentro do callback');
            });

            $this->fail('A exceção do callback deveria ter sido propagada.');
        } catch (RuntimeException) {
            // Esperado.
        }

        $this->assertTrue(TenantAtual::escopoAtivo());
        $this->assertSame(1, TenantAtual::comTenant(1, fn () => ModeloDeDominioFalso::query()->count()));
    }

    public function test_sem_escopo_aninhado_so_religa_ao_sair_do_nivel_mais_externo(): void
    {
        TenantAtual::semEscopo(function (): void {
            TenantAtual::semEscopo(function (): void {
                $this->assertFalse(TenantAtual::escopoAtivo());
            });

            $this->assertFalse(TenantAtual::escopoAtivo());
        });

        $this->assertTrue(TenantAtual::escopoAtivo());
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * Autentica um usuário que não é gravado no banco: o resolvedor só lê
     * `company_id` do usuário, e criar registro aqui poluiria a base.
     */
    private function autenticarUsuarioDaEmpresa(int $empresa): void
    {
        $usuario = new User;
        $usuario->company_id = $empresa;

        Auth::setUser($usuario);
    }

    private function popularOsDoisTenants(): void
    {
        $paiUm = DB::table(self::TABELA_PAI)->insertGetId(['company_id' => 1, 'nome' => 'pai da empresa 1']);
        $paiDois = DB::table(self::TABELA_PAI)->insertGetId(['company_id' => 2, 'nome' => 'pai da empresa 2']);

        DB::table(self::TABELA_FILHA)->insert([
            ['company_id' => 1, 'parent_id' => $paiUm, 'nome' => 'filho da empresa 1'],
            ['company_id' => 2, 'parent_id' => $paiDois, 'nome' => 'filho da empresa 2'],
        ]);
    }

    /**
     * Tabelas de mentira no mesmo formato das tabelas de domínio em produção
     * hoje: `company_id` NOT NULL com DEFAULT 1.
     */
    private function criarTabelasDeTeste(): void
    {
        $this->removerTabelasDeTeste();

        Schema::create(self::TABELA_PAI, function (Blueprint $tabela): void {
            $tabela->id();
            $tabela->unsignedBigInteger('company_id')->default(1);
            $tabela->string('nome');
        });

        Schema::create(self::TABELA_FILHA, function (Blueprint $tabela): void {
            $tabela->id();
            $tabela->unsignedBigInteger('company_id')->default(1);
            $tabela->unsignedBigInteger('parent_id');
            $tabela->string('nome');
        });
    }

    private function removerTabelasDeTeste(): void
    {
        Schema::dropIfExists(self::TABELA_FILHA);
        Schema::dropIfExists(self::TABELA_PAI);
    }
}

/**
 * Model de domínio de mentira: usa a trait como todo model da Task 4.8 vai usar.
 */
class ModeloDeDominioFalso extends Model
{
    use BelongsToCompany;

    protected $table = 'tenant_scope_test_parents';

    protected $guarded = [];

    public $timestamps = false;

    public function filhos(): HasMany
    {
        return $this->hasMany(ModeloFilhoFalso::class, 'parent_id');
    }
}

/**
 * Filho de mentira, só para o teste de join: duas tabelas com `company_id` na
 * mesma consulta é o cenário que quebraria sem o prefixo da tabela no filtro.
 */
class ModeloFilhoFalso extends Model
{
    use BelongsToCompany;

    protected $table = 'tenant_scope_test_children';

    protected $guarded = [];

    public $timestamps = false;
}

/**
 * Reproduz a exceção declarada do model `User`: sem escopo global de leitura,
 * com o preenchimento de `company_id` na criação mantido.
 */
class ModeloSemEscopoDeLeituraFalso extends Model
{
    use BelongsToCompany;

    protected $table = 'tenant_scope_test_parents';

    protected $guarded = [];

    public $timestamps = false;

    public static function aplicaEscopoDeEmpresaNaLeitura(): bool
    {
        return false;
    }
}
