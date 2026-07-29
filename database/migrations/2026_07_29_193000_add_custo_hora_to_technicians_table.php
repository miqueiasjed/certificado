<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custo/hora do técnico (Plano 18, Task 18.6): base para o custo de mão de
 * obra na margem por OS (`App\Services\Financial\WorkOrderMarginService`).
 *
 * Nullable, sem backfill e sem restrição: só a estrutura, a etapa segura de
 * uma migration em tabela com dado existente (`technicians` já tem registro
 * em produção). Técnico sem o valor preenchido não trava nada; quem lê o
 * campo (`WorkOrderMarginService`) zera o componente de mão de obra e marca a
 * margem inteira como parcial, em vez de a migration inventar um valor
 * padrão que pareceria custo real.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technicians', function (Blueprint $table): void {
            $table->decimal('custo_hora', 10, 2)->nullable()->after('registration_number');
        });
    }

    public function down(): void
    {
        Schema::table('technicians', function (Blueprint $table): void {
            $table->dropColumn('custo_hora');
        });
    }
};
