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
        Schema::table('work_order_room', function (Blueprint $table) {
            // Primeiro, remover a coluna antiga se existir
            if (Schema::hasColumn('work_order_room', 'event_type')) {
                $table->dropColumn('event_type');
            }

            // Adicionar nova coluna como foreignId
            $table->foreignId('event_type_id')
                ->nullable()
                ->after('observation')
                ->constrained('event_types')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('work_order_room', function (Blueprint $table) {
            // Remover a foreign key
            $table->dropForeign(['event_type_id']);

            // Remover a coluna
            if (Schema::hasColumn('work_order_room', 'event_type_id')) {
                $table->dropColumn('event_type_id');
            }

            // Adicionar de volta a coluna antiga como string
            $table->string('event_type')->nullable()->after('observation');
        });
    }
};
