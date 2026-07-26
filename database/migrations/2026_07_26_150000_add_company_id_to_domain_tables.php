<?php

use App\Support\DominioMultiempresa;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etapa 1 da migração multiempresa (Plano 4): adiciona `company_id` nullable,
 * sem chave estrangeira e sem índice, em todas as tabelas de domínio listadas
 * em `DominioMultiempresa::TABELAS_DE_DOMINIO`.
 *
 * Deploy isolado e sem efeito observável: nenhum código lê ou escreve essa
 * coluna ainda. Índice, chave estrangeira e `NOT NULL` entram na Task 4.4,
 * em um deploy separado, depois do backfill da Task 4.3.
 */
return new class extends Migration
{
    /**
     * Executa a migração.
     */
    public function up(): void
    {
        $alteradas = [];
        $puladas = [];

        foreach (DominioMultiempresa::TABELAS_DE_DOMINIO as $tabela) {
            if (! Schema::hasTable($tabela) || Schema::hasColumn($tabela, DominioMultiempresa::COLUNA_TENANT)) {
                $puladas[] = $tabela;

                continue;
            }

            Schema::table($tabela, function (Blueprint $table): void {
                $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT)->nullable()->after('id');
            });

            $alteradas[] = $tabela;
        }

        fwrite(STDOUT, sprintf(
            "\n  company_id nullable: %d tabela(s) alterada(s), %d pulada(s) (já tinham a coluna ou não existem).\n",
            count($alteradas),
            count($puladas)
        ));
    }

    /**
     * Reverte a migração, removendo `company_id` das mesmas tabelas.
     */
    public function down(): void
    {
        foreach (DominioMultiempresa::TABELAS_DE_DOMINIO as $tabela) {
            if (! Schema::hasTable($tabela) || ! Schema::hasColumn($tabela, DominioMultiempresa::COLUNA_TENANT)) {
                continue;
            }

            Schema::table($tabela, function (Blueprint $table): void {
                $table->dropColumn(DominioMultiempresa::COLUNA_TENANT);
            });
        }
    }
};
