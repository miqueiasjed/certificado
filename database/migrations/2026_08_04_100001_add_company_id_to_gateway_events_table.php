<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `gateway_events` (Plano 7) ganha `company_id` nullable (Plano 19,
 * Task 19.1): a mesma tabela passa a registrar tanto o evento de assinatura
 * da plataforma (sem tenant, como hoje) quanto o evento de cobrança do tenant
 * ao cliente final (Plano 19), que já nasce com o tenant conhecido.
 *
 * Coluna nullable, sem backfill e sem restrição: continua fora do escopo por
 * empresa (`App\Support\DominioMultiempresa::TABELAS_FORA_DO_ESCOPO`), porque
 * quem processa e concilia o evento é a plataforma, não um tenant específico
 * — mesmo critério já registrado ali para `invoices`/`subscriptions`, que
 * também têm `company_id` sem usar o escopo global. Por ser aditiva e
 * nullable, cabe num único deploy, sem a divisão em três etapas do CLAUDE.md
 * (que vale para coluna que troca de estado com dado já existente, não para
 * coluna nova que nasce opcional).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gateway_events', function (Blueprint $table): void {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('gateway_events', function (Blueprint $table): void {
            $table->dropForeign(['company_id']);
            $table->dropColumn('company_id');
        });
    }
};
