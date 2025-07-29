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
        Schema::table('service_orders', function (Blueprint $table) {
            $table->string('order_number')->unique()->after('id'); // Numeração sequencial
            $table->enum('service_type', [
                'dedetizacao',
                'desinsetizacao',
                'descupinizacao',
                'desratizacao',
                'sanitizacao'
            ])->after('order_date'); // Tipo de serviço
            $table->foreignId('technician_id')->nullable()->constrained('users')->after('client_id'); // Técnico responsável
            $table->text('special_instructions')->nullable()->after('observations'); // Instruções especiais
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->dropForeign(['technician_id']);
            $table->dropColumn(['order_number', 'service_type', 'technician_id', 'special_instructions']);
        });
    }
};
