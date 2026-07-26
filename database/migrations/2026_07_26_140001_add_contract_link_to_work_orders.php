<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            // Vínculo com o contrato que originou a visita. Nulo para OS criada à mão.
            $table->foreignId('contract_id')
                ->nullable()
                ->after('service_id')
                ->constrained('contracts')
                ->nullOnDelete();

            // Distingue OS gerada pelo contrato ('contrato') de OS criada à mão (nulo).
            // Sem essa distinção, o cancelamento em cascata de visitas futuras (Task 9.5)
            // varreria também OS que alguém criou manualmente.
            $table->string('origem', 20)->nullable()->after('contract_id');

            // A enésima visita do contrato. Sem unique composto com contract_id: a
            // reprogramação (Task 9.5) pode renumerar mantendo datas, e o unique
            // travaria isso. A idempotência é garantida no Service (Task 9.3/9.4)
            // pelo par [contract_id, scheduled_date].
            $table->unsignedInteger('visita_numero')->nullable()->after('origem');

            $table->index(['contract_id', 'scheduled_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // A FK precisa cair antes do índice composto: o índice é o que sustenta a
        // constraint no MySQL, e a ordem inversa falha com "needed in a foreign
        // key constraint".
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contract_id');
        });

        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropIndex(['contract_id', 'scheduled_date']);
        });

        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropColumn(['origem', 'visita_numero']);
        });
    }
};
