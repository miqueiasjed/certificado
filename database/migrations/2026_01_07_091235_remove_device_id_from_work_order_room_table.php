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
            // Remover foreign key primeiro
            if (Schema::hasColumn('work_order_room', 'device_id')) {
                try {
                    $table->dropForeign(['device_id']);
                } catch (\Exception $e) {
                    // Foreign key não existe, ignorar erro
                }
                
                // Remover coluna
                $table->dropColumn('device_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('work_order_room', function (Blueprint $table) {
            if (!Schema::hasColumn('work_order_room', 'device_id')) {
                $table->unsignedBigInteger('device_id')->nullable()->after('event_observations');
                
                try {
                    $table->foreign('device_id')->references('id')->on('devices')->onDelete('set null');
                } catch (\Exception $e) {
                    // Ignorar erro se foreign key já existir
                }
            }
        });
    }
};
