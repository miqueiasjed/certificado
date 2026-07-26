<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Concerns\BelongsToCompany;
use App\Support\DominioMultiempresa;
use App\Support\TenantAtual;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task 4.10 do Plano 4: a rede permanente contra model de domínio sem
 * `BelongsToCompany`.
 *
 * O motivo de existir: o dia em que alguém cria um model novo e esquece a
 * trait é o dia em que uma empresa passa a enxergar dado de outra. Esse
 * esquecimento não aparece em code review porque o model funciona normalmente
 * sem a trait, só não filtra por empresa. Por isso este teste não confere uma
 * lista escrita à mão, que envelheceria junto com o erro: ele varre
 * `app/Models/` de verdade a cada execução, com `glob()`, e cobra que toda
 * classe encontrada esteja em uma das duas listas de
 * `App\Support\DominioMultiempresa` (`MODELS_DE_DOMINIO` ou
 * `MODELS_FORA_DO_ESCOPO`). Model novo sem entrada em nenhuma das duas listas
 * derruba o teste tanto quanto model de domínio sem a trait.
 *
 * Usa `Client` e `Company`, dois models reais, no caso de preenchimento
 * automático (o único que grava no banco), para provar que a trait funciona
 * no schema de produção, e não só na tabela de mentira da
 * `BelongsToCompanyTest` (Task 4.6).
 */
class EscopoDeEmpresaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Varredura de app/Models/: nenhuma classe pode ficar sem classificação
    // -----------------------------------------------------------------

    public function test_todo_model_encontrado_em_app_models_esta_classificado(): void
    {
        $classificados = array_merge(
            DominioMultiempresa::MODELS_DE_DOMINIO,
            DominioMultiempresa::modelsForaDoEscopo()
        );

        $naoClassificados = [];

        foreach ($this->modelosDoDiretorio() as $classe) {
            $this->assertTrue(
                class_exists($classe),
                "O arquivo de \"{$classe}\" em app/Models/ não corresponde a nenhuma classe carregável. "
                .'Confira se o nome do arquivo bate com o nome da classe declarada dentro dele.'
            );

            $this->assertTrue(
                is_subclass_of($classe, Model::class),
                "\"{$classe}\" está em app/Models/ mas não é um Eloquent Model (não estende Illuminate\\Database\\Eloquent\\Model). "
                .'Se não for um model de domínio, mova o arquivo para fora de app/Models/.'
            );

            if (! in_array($classe, $classificados, true)) {
                $naoClassificados[] = $classe;
            }
        }

        $this->assertSame(
            [],
            $naoClassificados,
            'Model(s) novo(s) em app/Models/ sem classificação multiempresa: '.implode(', ', $naoClassificados).'. '
            .'Abra app/Support/DominioMultiempresa.php e classifique cada um: em MODELS_DE_DOMINIO, se o model guardar '
            .'dado de uma empresa (e então precisa de "use BelongsToCompany;" na classe), ou em MODELS_FORA_DO_ESCOPO, '
            .'com uma frase explicando o motivo da exclusão. Model sem classificação some da varredura deste teste, '
            .'e é exatamente esse silêncio que deixa passar um vazamento de dado entre empresas.'
        );
    }

    // -----------------------------------------------------------------
    // Model de domínio usa a trait, model fora do escopo não usa
    // -----------------------------------------------------------------

    public function test_todo_model_de_dominio_usa_a_trait_belongs_to_company(): void
    {
        $semTrait = [];

        foreach (DominioMultiempresa::MODELS_DE_DOMINIO as $classe) {
            if (! in_array(BelongsToCompany::class, class_uses_recursive($classe), true)) {
                $tabela = (new $classe)->getTable();
                $semTrait[] = "{$classe} (tabela: {$tabela})";
            }
        }

        $this->assertSame(
            [],
            $semTrait,
            'Model(s) de domínio sem a trait BelongsToCompany: '.implode(', ', $semTrait).'. '
            .'Abra o arquivo do model listado e adicione "use BelongsToCompany;" (App\\Models\\Concerns\\BelongsToCompany) '
            .'na classe. Sem a trait o model não recebe o escopo global por empresa nem o preenchimento automático de '
            .'company_id, e um usuário de uma empresa passa a enxergar (e a poder alterar) dado de outra empresa.'
        );
    }

    public function test_nenhum_model_fora_do_escopo_usa_a_trait_por_engano(): void
    {
        $comTraitIndevida = [];

        foreach (DominioMultiempresa::modelsForaDoEscopo() as $classe) {
            if (in_array(BelongsToCompany::class, class_uses_recursive($classe), true)) {
                $comTraitIndevida[] = $classe;
            }
        }

        $this->assertSame(
            [],
            $comTraitIndevida,
            'Model(s) classificado(s) em DominioMultiempresa::MODELS_FORA_DO_ESCOPO mas usando BelongsToCompany: '
            .implode(', ', $comTraitIndevida).'. '
            .'Ou a trait foi aplicada por engano (remova "use BelongsToCompany;" do model), ou a classificação está '
            .'desatualizada e o model deveria estar em MODELS_DE_DOMINIO, com a tabela dele em TABELAS_DE_DOMINIO. '
            .'Resolva em app/Support/DominioMultiempresa.php.'
        );
    }

    // -----------------------------------------------------------------
    // Coerência entre a classificação declarada e o schema real
    // -----------------------------------------------------------------

    public function test_dominio_multiempresa_conferir_nao_acusa_divergencia(): void
    {
        $divergencias = DominioMultiempresa::conferir();

        $this->assertSame(
            [],
            $divergencias,
            "DominioMultiempresa::conferir() encontrou divergência entre o schema do banco e a classificação declarada:\n"
            .json_encode($divergencias, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n"
            .'Toda tabela nova precisa entrar em TABELAS_DE_DOMINIO ou em TABELAS_FORA_DO_ESCOPO (com o motivo), em '
            .'app/Support/DominioMultiempresa.php, antes de seguir em frente. É este método que pega a migration nova '
            .'que criou tabela e ninguém classificou.'
        );
    }

    public function test_toda_tabela_de_dominio_tem_a_coluna_company_id(): void
    {
        $semColuna = [];

        foreach (DominioMultiempresa::TABELAS_DE_DOMINIO as $tabela) {
            if (! Schema::hasColumn($tabela, DominioMultiempresa::COLUNA_TENANT)) {
                $semColuna[] = $tabela;
            }
        }

        $this->assertSame(
            [],
            $semColuna,
            'Tabela(s) declarada(s) em TABELAS_DE_DOMINIO sem a coluna company_id no banco: '.implode(', ', $semColuna).'. '
            .'Crie a migration que adiciona a coluna (em etapas, como manda o CLAUDE.md do projeto: estrutura, backfill '
            .'conferido, restrição, nunca as três no mesmo deploy) antes de declarar a tabela como de domínio.'
        );
    }

    public function test_todo_model_de_dominio_aponta_para_tabela_com_a_coluna_company_id(): void
    {
        $semColuna = [];

        foreach (DominioMultiempresa::MODELS_DE_DOMINIO as $classe) {
            $tabela = (new $classe)->getTable();

            if (! Schema::hasColumn($tabela, DominioMultiempresa::COLUNA_TENANT)) {
                $semColuna[] = "{$classe} (tabela: {$tabela})";
            }
        }

        $this->assertSame(
            [],
            $semColuna,
            'Model(s) em MODELS_DE_DOMINIO cuja tabela real não tem a coluna company_id: '.implode(', ', $semColuna).'. '
            .'Confira se getTable() do model bate com o nome declarado em TABELAS_DE_DOMINIO, e se a migration que '
            .'adiciona company_id já rodou nessa tabela.'
        );
    }

    // -----------------------------------------------------------------
    // company_id não pode ser preenchível pela requisição
    // -----------------------------------------------------------------

    public function test_nenhum_model_de_dominio_tem_company_id_no_fillable(): void
    {
        $comFillable = [];

        foreach (DominioMultiempresa::MODELS_DE_DOMINIO as $classe) {
            if (in_array(DominioMultiempresa::COLUNA_TENANT, (new $classe)->getFillable(), true)) {
                $comFillable[] = $classe;
            }
        }

        $this->assertSame(
            [],
            $comFillable,
            'Model(s) de domínio com company_id em $fillable: '.implode(', ', $comFillable).'. '
            .'A trait BelongsToCompany preenche company_id a partir do tenant corrente automaticamente; deixá-lo em '
            .'$fillable abre brecha para a requisição HTTP sobrescrever a empresa dona do registro. Remova '
            .'"company_id" do array $fillable do model.'
        );
    }

    // -----------------------------------------------------------------
    // Preenchimento automático, com um model real e a tabela real
    // -----------------------------------------------------------------

    public function test_criacao_dentro_de_um_tenant_preenche_company_id_automaticamente(): void
    {
        $outraEmpresa = Company::create([]);

        $cliente = TenantAtual::comTenant(
            $outraEmpresa->id,
            fn () => ClientFactory::new()->create()
        );

        $this->assertSame($outraEmpresa->id, (int) $cliente->company_id);
        $this->assertSame(
            $outraEmpresa->id,
            (int) DB::table('clients')->where('id', $cliente->id)->value('company_id')
        );
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * Nomes totalmente qualificados de toda classe de model encontrada em
     * `app/Models/`, pela varredura real do diretório (e não por uma lista
     * fixa). Não desce em `app/Models/Concerns/`: lá moram traits, não models.
     *
     * @return array<int, class-string>
     */
    private function modelosDoDiretorio(): array
    {
        $arquivos = glob(app_path('Models').'/*.php') ?: [];

        $modelos = array_map(
            static fn (string $arquivo): string => 'App\\Models\\'.basename($arquivo, '.php'),
            $arquivos
        );

        sort($modelos);

        return $modelos;
    }
}
