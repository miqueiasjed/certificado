<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_invoices', function (Blueprint $table): void {
            $table->string('referencia_provedor')->nullable()->after('provedor_id');
            $table->text('motivo_substituicao')->nullable()->after('motivo_cancelamento');
            $table->timestamp('cancelamento_solicitado_em')->nullable()->after('cancelada_em');
            $table->string('cancelamento_provedor_id')->nullable()->after('cancelamento_solicitado_em');
            $table->json('payload_dps')->nullable()->after('referencia_provedor');
            $table->json('metadados_substituicao')->nullable()->after('payload_dps');
            $table->unique(['company_id', 'referencia_provedor'], 'service_invoices_referencia_provedor_unique');
        });
    }

    public function down(): void
    {
        Schema::table('service_invoices', function (Blueprint $table): void {
            $table->dropUnique('service_invoices_referencia_provedor_unique');
            $table->dropColumn([
                'referencia_provedor',
                'payload_dps',
                'metadados_substituicao',
                'motivo_substituicao',
                'cancelamento_solicitado_em',
                'cancelamento_provedor_id',
            ]);
        });
    }
};
