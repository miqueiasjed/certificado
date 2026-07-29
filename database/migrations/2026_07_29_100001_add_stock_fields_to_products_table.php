<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dimensão de estoque em `products` (Plano 17, Task 17.1): a chave que liga o
 * controle produto a produto, o ponto de reposição e a unidade de medida.
 *
 * Decisões desta etapa:
 *
 * - **`controla_estoque` nasce `false`.** É a decisão central do plano. A
 *   tabela tem dado em produção e todo produto do cliente atual hoje é ficha
 *   técnica, sem saldo cadastrado: nascer `true` faria a primeira OS depois do
 *   deploy tentar dar baixa de um estoque que ninguém cadastrou. O tenant liga
 *   produto por produto, depois de conferir o saldo inicial por contagem
 *   física (passo 4 da ordem de aplicação em `.claude/tasks/17/INDEX.md`).
 * - **`estoque_minimo` nullable, com as mesmas 4 casas das quantidades.** Nulo
 *   significa "sem ponto de reposição definido", que é o estado de todo produto
 *   existente, e é diferente de zero, que significaria alerta a cada saldo
 *   zerado. O alerta da Task 17.6 ignora o nulo.
 * - **`unidade` com padrão `un`.** Descreve corretamente o produto atual, que é
 *   contado por peça enquanto ninguém informar outra coisa. `ml`, `L`, `kg` e
 *   `g` cabem com folga nos 10 caracteres, e a coluna é texto livre de
 *   propósito: enum aqui viraria alteração de tabela toda vez que aparecesse
 *   uma apresentação nova.
 *
 * Deploy sem efeito observável: três colunas com valor padrão que já descreve
 * o estado de todo produto existente. Nenhuma tela muda enquanto
 * `controla_estoque` continuar `false`, e é por isso que estrutura e ativação
 * ficam em momentos separados, como manda o CLAUDE.md do projeto para tabela
 * com dado em produção.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            if (! Schema::hasColumn('products', 'controla_estoque')) {
                $table->boolean('controla_estoque')->default(false)->after('organ_registration_id');
            }

            if (! Schema::hasColumn('products', 'estoque_minimo')) {
                $table->decimal('estoque_minimo', 12, 4)->nullable()->after('controla_estoque');
            }

            if (! Schema::hasColumn('products', 'unidade')) {
                $table->string('unidade', 10)->default('un')->after('estoque_minimo');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            foreach (['unidade', 'estoque_minimo', 'controla_estoque'] as $coluna) {
                if (Schema::hasColumn('products', $coluna)) {
                    $table->dropColumn($coluna);
                }
            }
        });
    }
};
