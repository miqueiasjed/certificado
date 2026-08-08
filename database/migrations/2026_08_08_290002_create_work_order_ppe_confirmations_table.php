<?php

use App\Support\DominioMultiempresa;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O que o técnico confirmou vestir naquela ordem de serviço (Plano 29, Task
 * 29.1).
 *
 * Uma linha por par OS x modelo de EPI exigido. É o registro de **uso**, e
 * complementa a ficha do Plano 28, que é o registro de **entrega**: a NR-6 cobra
 * a entrega, e é o uso que o contrato promete ao cliente e que a RDC 622/2022
 * cobra no POP da empresa especializada.
 *
 * Migration inteiramente aditiva: uma tabela nova, sem dado existente. **Nenhuma
 * coluna nova em `work_orders`** — a tabela já tem dado em produção e já foi
 * alterada nos Planos 13, 22, 24 e 27, e a ligação com a OS é `hasMany`, que não
 * pede coluna nenhuma do lado dela.
 *
 * Decisões da modelagem:
 *
 * - **Unique composta `[company_id, work_order_id,
 *   personal_protective_equipment_id]`, e ela é o que dá idempotência à
 *   sincronização offline.** A confirmação é feita em campo e sobe pela fila do
 *   Plano 12; o aplicativo reenvia a mesma confirmação quando a conexão cai
 *   depois do envio e antes da resposta, e sem a unique cada reenvio viraria
 *   linha nova. É o mesmo problema que `sync_operations.operacao_uuid` já
 *   resolve na fila, aplicado agora ao registro final. O `company_id` entra na
 *   unique porque é a regra do sistema para toda unique de domínio, mesmo quando
 *   as chaves estrangeiras já bastariam para separar os tenants.
 * - **`confirmado_em` é `datetime`, nunca `date`.** É o instante no aparelho do
 *   técnico, gravado em UTC e convertido só na exibição. Duas OS no mesmo dia
 *   precisam de ordem entre si, e a confirmação acontece no horário da execução,
 *   não no dia dela. Mesmo critério de `work_order_adequations.registrada_em` e
 *   de `sync_operations.registrada_em`.
 * - **`justificativa` é `text` nullable, e a obrigatoriedade dela não é
 *   constraint de banco.** A regra "justificativa obrigatória quando
 *   `confirmado` é `false`" fica no Service da Task 29.2. Recusa vinda do banco
 *   em operação offline chega ao técnico como erro de sincronização, no meio do
 *   campo, sem tela onde corrigir e sem quem explique — a validação precisa
 *   acontecer onde há usuário para responder a ela.
 * - **`confirmado` sem valor padrão, de propósito.** É o fato central do
 *   registro. Default `false` faria um insert incompleto gravar uma negativa,
 *   isto é, acusar o técnico de não ter usado o EPI por causa de um campo que
 *   ninguém mandou; default `true` faria o contrário, e o inverso é pior ainda
 *   perante fiscalização. Insert sem o valor precisa falhar, alto e visível.
 * - **`work_order_id` com `cascadeOnDelete`**, mesmo critério de
 *   `work_order_adequations`: a confirmação é registro da execução daquela OS e
 *   não sobrevive a ela.
 * - **`personal_protective_equipment_id` com `restrictOnDelete`**, mesmo
 *   critério de `ppe_deliveries` e de `service_ppe_requirements`: o Plano 28
 *   inativa o modelo de EPI em vez de excluir, e o `restrict` garante que uma
 *   exclusão introduzida por engano não apague a prova do uso junto com o
 *   cadastro.
 * - **`user_id` nullable com `nullOnDelete`**, mesmo critério de
 *   `audit_logs.user_id`, `work_order_signatures.user_id` e
 *   `ppe_deliveries.user_id`: quem confirmou é informação de apoio, e o registro
 *   de conformidade não pode desaparecer junto com a conta de um técnico
 *   desligado. A especificação da task lista `user_id` sem dizer o
 *   comportamento; `restrictOnDelete` aqui travaria a exclusão de usuário por
 *   causa de uma confirmação de EPI, e apagar a linha junto perderia o registro.
 * - **Nome explícito na chave estrangeira do EPI.** O nome que o Laravel geraria
 *   (`work_order_ppe_confirmations_personal_protective_equipment_id_foreign`, 68
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
        Schema::create('work_order_ppe_confirmations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);

            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('personal_protective_equipment_id');

            // Fato central do registro: sem default, ver o cabeçalho.
            $table->boolean('confirmado');

            // Obrigatória quando `confirmado` é falso, pela regra do Service da
            // Task 29.2, nunca por constraint de banco.
            $table->text('justificativa')->nullable();

            // Instante do aparelho do técnico: gravado em UTC e convertido só na
            // exibição. Nunca `date`.
            $table->dateTime('confirmado_em');

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            $table->unique(
                [DominioMultiempresa::COLUNA_TENANT, 'work_order_id', 'personal_protective_equipment_id'],
                'work_order_ppe_confirmations_company_work_order_ppe_unique'
            );

            $table->foreign('personal_protective_equipment_id', 'work_order_ppe_confirmations_ppe_foreign')
                ->references('id')
                ->on('personal_protective_equipments')
                ->restrictOnDelete();

            $table->index(DominioMultiempresa::COLUNA_TENANT, 'work_order_ppe_confirmations_company_id_index');
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
        Schema::dropIfExists('work_order_ppe_confirmations');
    }
};
