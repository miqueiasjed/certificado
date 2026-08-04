<?php

use App\Support\DominioMultiempresa;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configuração por tenant da emissão recorrente e da régua de cobrança
 * (Plano 19, Task 19.5): ligar/desligar a régua, escolher os marcos ativos e
 * a antecedência da emissão automática de cobrança de parcela de contrato.
 *
 * Mesmo critério de modelagem de `company_availability_settings` (Plano 16,
 * Task 16.2): uma linha por empresa (`company_id` único), e empresa sem
 * linha aqui não está mal configurada, apenas nunca abriu a tela de
 * configuração (Task 19.7). `App\Models\CompanyBillingSetting::atual()`
 * aplica os valores padrão do sistema exatamente como se a linha existisse
 * com eles.
 *
 * - **`marcos_ativos` em `json`, nullable.** `null` significa "todos os
 *   marcos do catálogo", que é o vocabulário de
 *   `App\Services\Payments\ReguaDeCobrancaService::OFFSETS` — esta migration
 *   e este model não conhecem os nomes dos marcos, de propósito, para o dia
 *   em que um marco novo entrar não exigir migration nenhuma.
 * - **`antecedencia_emissao_dias` com `default(10)`, na própria coluna.**
 *   Diferente de `marcos_ativos` (que só existe quando a linha existe),
 *   esta coluna leva valor padrão direto no banco, mesmo critério de
 *   `antecedencia_minima_dias` em `company_availability_settings`.
 * - **`regua_ativa` com `default(true)`.** A régua nasce ligada: é o
 *   comportamento que a Task 19.5 espera por padrão, e desligá-la é ação
 *   deliberada do tenant na tela de configuração.
 *
 * Tabela nova, sem dado existente: um único deploy, sem a divisão em etapas
 * exigida para tabela que já tem dado em produção.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_billing_settings', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT)->unique();

            $table->boolean('regua_ativa')->default(true);
            $table->json('marcos_ativos')->nullable();
            $table->unsignedSmallInteger('antecedencia_emissao_dias')->default(10);

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
        Schema::dropIfExists('company_billing_settings');
    }
};
