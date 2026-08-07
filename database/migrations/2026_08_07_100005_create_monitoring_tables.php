<?php

use App\Support\DominioMultiempresa;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Planta do endereço, posição de cada dispositivo sobre ela e relatório de
 * monitoramento consolidado por período (Plano 21).
 *
 * Planta é versionada: quando o layout muda, nasce uma linha nova em
 * `floor_plans` com `versao` maior, a versão anterior fica com `ativa = false`
 * e `substituida_em` preenchido, mas nunca é apagada. Cada versão carrega as
 * próprias `device_positions`, presas a ela por `floor_plan_id`; trocar de
 * planta não move nem apaga as posições da versão anterior, é só a versão
 * nova nascer sem nenhuma. É assim que um relatório emitido há um ano
 * continua refletindo o layout daquela época, e é isso que a auditoria
 * confere.
 *
 * `x`/`y` são fração de 0 a 1 da largura/altura da imagem, não pixel: a mesma
 * planta é exibida em telas de tamanhos diferentes e impressa em papel, e
 * pixel absoluto quebraria em todas menos uma.
 *
 * `monitoring_reports.dados` guarda o retrato consolidado do período no
 * momento da geração (json) e nunca é recalculado na leitura: um relatório já
 * entregue ao cliente não pode mudar depois, com dado corrigido em OS
 * posterior à emissão.
 *
 * As uniques `floor_plans.[address_id, nome, versao]` e
 * `device_positions.[floor_plan_id, device_id]` não entram compostas com
 * `company_id`: `address_id` e `floor_plan_id` já pertencem a uma empresa só,
 * então a unique não bloqueia o segundo tenant, mesmo raciocínio já registrado
 * em `DominioMultiempresa::UNIQUES_GLOBAIS_MANTIDOS` para
 * `receivable_installments.receivable_id_numero_unique`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->criarFloorPlans();
        $this->criarDevicePositions();
        $this->criarMonitoringReports();
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_reports');
        Schema::dropIfExists('device_positions');
        Schema::dropIfExists('floor_plans');
    }

    private function criarFloorPlans(): void
    {
        Schema::create('floor_plans', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);
            $table->foreignId('address_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('versao');
            $table->string('nome');
            $table->string('arquivo_path');
            $table->unsignedInteger('largura_px');
            $table->unsignedInteger('altura_px');
            $table->boolean('ativa')->default(true);
            $table->timestamp('substituida_em')->nullable();
            $table->text('observacao')->nullable();
            $table->timestamps();

            $table->unique(['address_id', 'nome', 'versao']);

            $table->index(DominioMultiempresa::COLUNA_TENANT, 'floor_plans_company_id_index');
            $table->foreign(DominioMultiempresa::COLUNA_TENANT)
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    private function criarDevicePositions(): void
    {
        Schema::create('device_positions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);
            $table->foreignId('floor_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->decimal('x', 8, 4);
            $table->decimal('y', 8, 4);
            $table->boolean('rotulo_visivel')->default(true);
            $table->timestamps();

            $table->unique(['floor_plan_id', 'device_id']);

            $table->index(DominioMultiempresa::COLUNA_TENANT, 'device_positions_company_id_index');
            $table->foreign(DominioMultiempresa::COLUNA_TENANT)
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    private function criarMonitoringReports(): void
    {
        Schema::create('monitoring_reports', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('address_id')->nullable()->constrained()->nullOnDelete();
            $table->date('periodo_inicio');
            $table->date('periodo_fim');
            $table->timestamp('gerado_em');
            $table->foreignId('gerado_por')->constrained('users')->restrictOnDelete();
            $table->json('dados');
            $table->string('pdf_path')->nullable();
            $table->boolean('publicado_no_portal')->default(false);
            $table->timestamps();

            $table->index(['client_id', 'periodo_inicio', 'periodo_fim']);

            $table->index(DominioMultiempresa::COLUNA_TENANT, 'monitoring_reports_company_id_index');
            $table->foreign(DominioMultiempresa::COLUNA_TENANT)
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }
};
