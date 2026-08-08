<?php

use App\Support\DominioMultiempresa;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consentimento e configuração do rastreamento CONTÍNUO do técnico em campo
 * (Plano 22, Task 22.4).
 *
 * Isto não é o mesmo consentimento raso de "pedir localização ao assinar"
 * (Plano 13, Task 13.9): aquele é um prompt de permissão do navegador,
 * único, recusável, sem registro persistido, incidental à assinatura.
 * Rastreamento contínuo é monitoramento de pessoa ao longo do dia inteiro, e
 * por isso exige um registro formal, revogável e auditável: quem autorizou
 * (`consentido_por`), quando (`consentido_em`), e para qual técnico. Sem os
 * dois primeiros preenchidos, `rastreamento_continuo` nunca pode valer
 * `true` - a garantia vive em `App\Models\TechnicianTrackingSetting`
 * (`static::saving`), não só no Service que grava.
 *
 * **Duas linhas com papéis diferentes, mesma tabela**:
 * - `technician_id = null`: configuração geral do tenant (o padrão da
 *   empresa, aplicado a todo técnico sem override próprio).
 * - `technician_id` preenchido: override específico daquele técnico,
 *   sobrepondo a configuração geral.
 *
 * `rastreamento_continuo`, `consentido_em` e `consentido_por` vivem sempre na
 * linha do técnico, nunca na linha geral: consentimento é pessoal, do próprio
 * técnico (regra de negócio inegociável do Plano 22), e uma linha geral com
 * a chave ligada não teria como representar o consentimento individual de
 * cada um. A linha geral (`technician_id = null`) existe para configuração
 * que não é consentimento e pode ter um padrão de empresa razoável -
 * `intervalo_minutos`, por exemplo -, aplicada a um técnico que ainda não tem
 * override próprio. `LocalDeExecucaoService::ligarRastreamentoContinuo()` e
 * `::desligarRastreamentoContinuo()` operam sempre sobre a linha do técnico
 * informado, nunca sobre a geral.
 *
 * A unicidade de "uma linha por técnico por empresa" é mantida pelo Service
 * (sempre busca antes de criar), não por uma constraint do banco: uma unique
 * `[company_id, technician_id]` não impediria duas linhas com `technician_id`
 * nulo na mesma empresa, porque o MySQL trata cada `NULL` como distinto num
 * índice unique. Registrado aqui para quem for alterar este schema depois não
 * presumir uma garantia que a constraint não dá.
 *
 * `rastreamento_continuo` nasce **sempre desligado** (`default(false)`): é a
 * regra de negócio inegociável do Plano 22, e nascer ligado por acidente é
 * exatamente o que a fronteira de consentimento proíbe.
 *
 * `intervalo_minutos` (`default(15)`): de quanto em quanto tempo o aplicativo
 * do técnico envia um ponto de localização, quando o rastreamento contínuo
 * está ligado. Usado pela task do aplicativo que lê esta configuração
 * (Plano 22, Task 22.7), não por esta migration.
 *
 * Tabela nova, sem dado existente: um único deploy, sem a divisão em etapas
 * exigida para tabela que já tem dado em produção.
 */
return new class extends Migration
{
    /**
     * Executa a migration.
     */
    public function up(): void
    {
        Schema::create('technician_tracking_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);
            $table->foreignId('technician_id')->nullable()->constrained()->cascadeOnDelete();
            $table->boolean('rastreamento_continuo')->default(false);
            $table->dateTime('consentido_em')->nullable();
            $table->foreignId('consentido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('intervalo_minutos')->default(15);
            $table->timestamps();

            $table->index(DominioMultiempresa::COLUNA_TENANT, 'technician_tracking_settings_company_id_index');
            $table->foreign(DominioMultiempresa::COLUNA_TENANT)
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    /**
     * Reverte a migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('technician_tracking_settings');
    }
};
