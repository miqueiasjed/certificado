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
        if (!Schema::hasTable('work_order_device')) {
            Schema::create('work_order_device', function (Blueprint $table) {
            $table->id();
                $table->foreignId('work_order_id')->constrained()->onDelete('cascade');
                $table->foreignId('device_id')->constrained()->onDelete('cascade');
                $table->text('observation')->nullable();
            $table->timestamps();

                // Impedir duplicatas
                $table->unique(['work_order_id', 'device_id']);
        });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_order_device');
    }
};
