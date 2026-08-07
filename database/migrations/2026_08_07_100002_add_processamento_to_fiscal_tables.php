<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_configs', function (Blueprint $table): void {
            $table->enum('gatilho_emissao_automatica', ['conclusao_os', 'quitacao_titulo'])
                ->default('conclusao_os')
                ->after('emissao_automatica');
            $table->boolean('exige_inscricao_municipal_tomador')
                ->default(false)
                ->after('gatilho_emissao_automatica');
        });

        Schema::table('service_invoices', function (Blueprint $table): void {
            $table->foreignId('address_id')->nullable()->after('client_id')->constrained()->restrictOnDelete();
            $table->boolean('erro_temporario')->nullable()->after('erro_mensagem');
            $table->timestamp('proxima_tentativa_em')->nullable()->after('tentativas');
            $table->timestamp('ultima_tentativa_em')->nullable()->after('proxima_tentativa_em');
            $table->uuid('processamento_token')->nullable()->after('ultima_tentativa_em');
            $table->timestamp('processamento_bloqueado_ate')->nullable()->after('processamento_token');
            $table->index(['situacao', 'proxima_tentativa_em'], 'service_invoices_retry_index');
            $table->index('processamento_bloqueado_ate', 'service_invoices_claim_index');
        });
    }

    public function down(): void
    {
        Schema::table('service_invoices', function (Blueprint $table): void {
            $table->dropForeign(['address_id']);
            $table->dropIndex('service_invoices_retry_index');
            $table->dropIndex('service_invoices_claim_index');
            $table->dropColumn([
                'address_id',
                'erro_temporario',
                'proxima_tentativa_em',
                'ultima_tentativa_em',
                'processamento_token',
                'processamento_bloqueado_ate',
            ]);
        });

        Schema::table('fiscal_configs', function (Blueprint $table): void {
            $table->dropColumn(['gatilho_emissao_automatica', 'exige_inscricao_municipal_tomador']);
        });
    }
};
