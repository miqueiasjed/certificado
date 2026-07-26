<?php

use App\Support\DominioMultiempresa;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fundação do tenant em banco novo, antes de `company_id` virar obrigatório
 * (Plano 4, Deploy 3, junto com
 * `2026_07_26_160000_make_company_id_required_on_domain_tables`).
 *
 * Em produção esta migration não faz nada, e é assim que ela foi desenhada: lá
 * a empresa 1 já existe, criada por `Company::current()`, e o backfill da
 * Task 4.3 já zerou os `company_id` nulos. A conferência no início do `up()`
 * confirma isso e sai sem tocar em nada.
 *
 * Ela existe pelo caminho do banco novo (`migrate` do zero em ambiente novo, em
 * CI e na suíte de testes com `RefreshDatabase`), onde a cadeia de migrations
 * chega quebrada na etapa seguinte por dois motivos encadeados:
 *
 * 1. `2025_10_08_100943_populate_service_types_table` insere cinco tipos de
 *    serviço muito antes de `company_id` existir. Quando a coluna aparece, na
 *    Task 4.2, essas linhas nascem com o tenant nulo, e não há operador para
 *    rodar `multiempresa:backfill` no meio de um `migrate`.
 * 2. `companies` fica vazia até alguém chamar `Company::current()`. Sem nenhuma
 *    empresa, a chave estrangeira da etapa seguinte não tem para onde apontar e
 *    qualquer usuário criado por factory é recusado pelo banco.
 *
 * Os dois problemas são de instalação, não de operação: num banco sem nenhuma
 * empresa cadastrada não existe dúvida sobre a quem o dado pertence, porque só
 * pode haver um dono. Por isso esta migration age apenas quando `companies`
 * está vazia, e nunca sobrescreve `company_id` já preenchido.
 *
 * O caso ambíguo continua sendo tratado como erro: linha apontando para uma
 * empresa que não existe é órfã de verdade, exige decisão humana, e a migration
 * seguinte aborta com o relatório do que encontrou.
 */
return new class extends Migration
{
    /**
     * Id do tenant fundador. É o mesmo que `Company::current()` cria e o mesmo
     * que o backfill da Task 4.3 grava.
     */
    private const TENANT_FUNDADOR = 1;

    /**
     * Executa a migração.
     */
    public function up(): void
    {
        if (! Schema::hasTable('companies')) {
            return;
        }

        if (DB::table('companies')->exists()) {
            fwrite(STDOUT, "\n  Fundação do tenant: nada a fazer, já existe empresa cadastrada.\n");

            return;
        }

        DB::table('companies')->insert([
            'id' => self::TENANT_FUNDADOR,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $preenchidas = 0;
        $tabelas = [];

        foreach (DominioMultiempresa::TABELAS_DE_DOMINIO as $tabela) {
            if (! Schema::hasTable($tabela) || ! Schema::hasColumn($tabela, DominioMultiempresa::COLUNA_TENANT)) {
                continue;
            }

            $afetadas = DB::table($tabela)
                ->whereNull(DominioMultiempresa::COLUNA_TENANT)
                ->update([DominioMultiempresa::COLUNA_TENANT => self::TENANT_FUNDADOR]);

            if ($afetadas > 0) {
                $preenchidas += $afetadas;
                $tabelas[] = "{$tabela} ({$afetadas})";
            }
        }

        fwrite(STDOUT, sprintf(
            "\n  Fundação do tenant: banco sem nenhuma empresa, empresa #%d criada.\n"
            ."  %d linha(s) semeada(s) por migrations anteriores receberam company_id = %d%s.\n",
            self::TENANT_FUNDADOR,
            $preenchidas,
            self::TENANT_FUNDADOR,
            $tabelas === [] ? '' : ': '.implode(', ', $tabelas)
        ));
    }

    /**
     * Sem reversão por decisão.
     *
     * Apagar a empresa fundadora deixaria órfão todo o dado que aponta para
     * ela, e limpar `company_id` de volta para nulo é o que a migration
     * seguinte já faz no `down()` dela. Voltar esta aqui só destruiria o tenant
     * de um banco que depende dele para operar.
     */
    public function down(): void
    {
        //
    }
};
