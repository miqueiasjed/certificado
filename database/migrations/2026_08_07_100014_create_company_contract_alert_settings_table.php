<?php

use App\Support\DominioMultiempresa;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configuração por tenant do prazo de alerta de contrato a vencer (Plano 23,
 * Task 23.5): quantos dias antes do fim da vigência cada marco de aviso
 * dispara.
 *
 * Mesmo critério de modelagem de `company_billing_settings` (Plano 19, Task
 * 19.5) e `company_availability_settings` (Plano 16, Task 16.2): uma linha
 * por empresa (`company_id` único), e empresa sem linha aqui não está mal
 * configurada, apenas nunca abriu a tela de configuração (Task 23.7/23.8).
 * `App\Models\CompanyContractAlertSetting::atual()` aplica os marcos padrão
 * do sistema (60, 30 e 15 dias) exatamente como se a linha existisse com
 * eles.
 *
 * - **`dias_antecedencia` em `json`, nullable.** Lista de inteiros com os
 *   marcos configurados pelo tenant (ex.: `[45, 20]`). `null` significa "usa
 *   o padrão do sistema", mesmo critério de `marcos_ativos` em
 *   `company_billing_settings`: guardar a lista em vez de colunas fixas
 *   (`marco_1`, `marco_2`, `marco_3`) permite ao tenant ter mais ou menos de
 *   três marcos sem migration nova.
 *
 * Tabela nova, sem dado existente: um único deploy, sem a divisão em etapas
 * exigida para tabela que já tem dado em produção.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_contract_alert_settings', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT)->unique();

            $table->json('dias_antecedencia')->nullable();

            $table->timestamps();

            $table->foreign(DominioMultiempresa::COLUNA_TENANT)
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_contract_alert_settings');
    }
};
