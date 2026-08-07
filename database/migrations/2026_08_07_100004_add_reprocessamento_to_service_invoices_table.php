<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_invoices', function (Blueprint $table): void {
            $table->foreignId('reprocessada_por_id')
                ->nullable()
                ->after('substituida_por_id')
                ->constrained('service_invoices')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('service_invoices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reprocessada_por_id');
        });
    }
};
