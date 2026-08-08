<?php

use App\Support\DominioMultiempresa;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EPI exigido por serviço (Plano 29, Task 29.1).
 *
 * Uma linha por par serviço x modelo de EPI. É a declaração de escritório do
 * que a execução daquele serviço exige vestir, e é dela que a Task 29.2 monta a
 * etapa de confirmação no aplicativo do técnico.
 *
 * Migration inteiramente aditiva: uma tabela nova, sem dado existente e sem
 * tocar em coluna de tabela em produção. A regra do CLAUDE.md que exige dividir
 * em estrutura, backfill e restrição vale para migration que impõe restrição
 * sobre dado que já existe; aqui não há nem dado nem restrição a impor depois.
 *
 * Decisões da modelagem:
 *
 * - **Unique composta `[company_id, service_id,
 *   personal_protective_equipment_id]`.** O mesmo EPI não se exige duas vezes no
 *   mesmo serviço: a exigência repetida viraria duas linhas na etapa de
 *   confirmação, e o técnico marcaria o mesmo respirador duas vezes. O
 *   `company_id` entra na unique porque é a regra do sistema para toda unique de
 *   domínio, mesmo quando as chaves estrangeiras já bastariam para separar os
 *   tenants — o mesmo formato já registrado para
 *   `routes.[company_id, technician_id, data]` (Plano 22).
 * - **Serviço sem exigência cadastrada é o estado normal**, não irregularidade.
 *   Quem acabou de ligar o módulo não tem uma linha aqui, e a etapa de
 *   confirmação simplesmente não aparece para aquele serviço. Nenhuma coluna
 *   desta tabela tenta representar "nenhuma exigência": a ausência de linha é
 *   que representa.
 * - **`obrigatorio` com default `true`.** Quem cadastra uma exigência está
 *   dizendo que aquele EPI é exigido; o EPI apenas recomendado é o caso raro e
 *   pede marcação explícita. Esta coluna é a da exigência **neste serviço**, e
 *   não se confunde com `personal_protective_equipments.obrigatorio`, que é o
 *   padrão do cadastro do modelo de EPI.
 * - **`service_id` com `cascadeOnDelete`.** A exigência só existe dentro do
 *   serviço e só é alcançada por ele; `ServiceService::deleteService()` apaga o
 *   serviço de verdade, e `restrictOnDelete` aqui transformaria a exclusão de um
 *   serviço em erro de SQL na cara do usuário, por causa de um registro
 *   acessório que ele nem sabe que existe.
 * - **`personal_protective_equipment_id` com `restrictOnDelete`**, mesmo
 *   critério de `ppe_deliveries`: o Plano 28 inativa o modelo de EPI em vez de
 *   excluir (`personal_protective_equipments.ativo`), e o `restrict` é o que
 *   garante que uma exclusão introduzida por engano não deixe exigência órfã.
 * - **Nome explícito na chave estrangeira do EPI.** O nome que o Laravel geraria
 *   (`service_ppe_requirements_personal_protective_equipment_id_foreign`, 65
 *   caracteres) estoura o limite de 64 do MySQL para nome de constraint, e a
 *   migration falharia na criação.
 * - **`company_id` NOT NULL, sem valor padrão**, mesmo critério das demais
 *   tabelas de domínio criadas depois do Plano 4: a trait `BelongsToCompany`
 *   preenche a coluna na criação.
 */
return new class extends Migration
{
    /**
     * Executa a migration.
     */
    public function up(): void
    {
        Schema::create('service_ppe_requirements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);

            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('personal_protective_equipment_id');

            // Exigência neste serviço. Não é o `obrigatorio` do cadastro do
            // modelo de EPI: ver o cabeçalho.
            $table->boolean('obrigatorio')->default(true);

            $table->timestamps();

            $table->unique(
                [DominioMultiempresa::COLUNA_TENANT, 'service_id', 'personal_protective_equipment_id'],
                'service_ppe_requirements_company_service_ppe_unique'
            );

            $table->foreign('personal_protective_equipment_id', 'service_ppe_requirements_ppe_foreign')
                ->references('id')
                ->on('personal_protective_equipments')
                ->restrictOnDelete();

            $table->index(DominioMultiempresa::COLUNA_TENANT, 'service_ppe_requirements_company_id_index');
            $table->foreign(DominioMultiempresa::COLUNA_TENANT)
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    /**
     * Reverte a migration.
     *
     * Derruba só a tabela criada aqui. Nenhum `DROP COLUMN` em tabela existente,
     * pelo mesmo motivo escrito nas migrations do Plano 28.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_ppe_requirements');
    }
};
