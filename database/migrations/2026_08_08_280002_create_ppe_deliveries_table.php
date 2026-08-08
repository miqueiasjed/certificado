<?php

use App\Support\DominioMultiempresa;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ficha de entrega de EPI ao técnico (Plano 28, Task 28.1).
 *
 * Uma linha por item entregue. É o registro que a NR-6 exige (item 6.5.1, na
 * redação da Portaria MTE nº 2.175/2022) e a prova do empregador em
 * reclamatória trabalhista, com arquivamento recomendado de 20 anos.
 *
 * Migration aditiva: uma tabela nova, sem dado existente e sem tocar em coluna
 * de tabela em produção.
 *
 * Decisões da modelagem:
 *
 * - **`numero_ca` e `validade_ca` são cópia, não relação.** O CA é gravado no
 *   ato da entrega (Task 28.2) e nunca mais relido de
 *   `personal_protective_equipments`. A ficha diz o que foi entregue naquele
 *   dia: renovar o CA no cadastro não pode reescrever a ficha do ano passado,
 *   pela mesma razão pela qual documento emitido não se recalcula. Ler o CA
 *   pela relação transformaria um documento oponível em uma tela que muda
 *   sozinha.
 * - **`trocar_ate` e `validade_ca` são colunas separadas, e é a decisão central
 *   do plano.** `validade_ca` é do certificado do fabricante e vale para as
 *   entregas futuras daquele modelo; `trocar_ate` é do item que aquele técnico
 *   tem na mão, calculado de `vida_util_dias` na Task 28.2. Renovar um não mexe
 *   no outro. Tratar as duas como uma só produz um sistema que ora alerta o
 *   mundo inteiro por um certificado renovado, ora nunca alerta a troca do
 *   respirador usado há dois anos.
 * - **A entrega é imutável: não há edição nem exclusão.** Erro se corrige com
 *   `estornada_em` + `motivo_estorno`, na mesma convenção do movimento de
 *   ajuste do razão de estoque do Plano 17. Por isso as duas colunas de estorno
 *   nascem aqui, e não em uma tabela de correção à parte: o estorno faz parte
 *   da própria ficha e precisa ser lido junto com ela.
 * - **`entregue_em`, `trocar_ate` e `devolvido_em` são `date`; `assinado_em` e
 *   `estornada_em` são `datetime`.** Dia sem hora relevante não sofre conversão
 *   de fuso; instante é gravado em UTC e convertido só na exibição. Assinatura
 *   e estorno são instantes porque precisam de ordem: duas correções no mesmo
 *   dia têm de ficar em sequência.
 * - **`technician_id` e `personal_protective_equipment_id` com
 *   `restrictOnDelete`.** A ficha sobrevive ao técnico: desligamento inativa o
 *   técnico (`technicians.is_active`), e a ficha dele continua consultável e
 *   extraível. Apagar o técnico ou o modelo de EPI por baixo esvaziaria um
 *   registro que a fiscalização pode pedir vinte anos depois.
 * - **`user_id` nullable com `nullOnDelete`**, mesmo critério de
 *   `audit_logs.user_id` e `work_order_signatures.user_id`: quem registrou é
 *   informação de apoio, e a ficha não pode desaparecer com a conta de quem
 *   digitou. A prova do recebimento é a assinatura do técnico, não o operador.
 * - **Índice `[company_id, technician_id, entregue_em]`.** A ficha do técnico é
 *   a consulta principal da tela (Task 28.6) e a extração por período (Task
 *   28.5) varre por data dentro do tenant. Sem ele as duas viram table scan no
 *   primeiro ano de histórico.
 * - **`company_id` NOT NULL, sem valor padrão**, mesmo critério das demais
 *   tabelas de domínio criadas depois do Plano 4: a trait `BelongsToCompany`
 *   preenche a coluna na criação.
 */
return new class extends Migration
{
    /**
     * Motivos aceitos, iguais a `PpeDelivery::MOTIVOS`.
     *
     * @var array<int, string>
     */
    private const MOTIVOS = [
        'primeira_entrega',
        'substituicao',
        'dano',
        'perda',
        'vencimento',
        'outro',
    ];

    /**
     * Executa a migration.
     */
    public function up(): void
    {
        Schema::create('ppe_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);

            $table->foreignId('technician_id')->constrained()->restrictOnDelete();
            $table->foreignId('personal_protective_equipment_id')
                ->constrained('personal_protective_equipments')
                ->restrictOnDelete();

            $table->unsignedInteger('quantidade')->default(1);

            // Dia da entrega no fuso do negócio: nunca sofre conversão de fuso.
            $table->date('entregue_em');
            $table->enum('motivo', self::MOTIVOS)->default('primeira_entrega');

            // Cópia do CA no momento da entrega. Não é relação: ver o cabeçalho.
            $table->string('numero_ca')->nullable();
            $table->date('validade_ca')->nullable();

            // Troca do item na mão daquele técnico, calculada de
            // `vida_util_dias` na Task 28.2. Nula quando o modelo de EPI não
            // tem vida útil cadastrada: sem troca programada.
            $table->date('trocar_ate')->nullable();

            // Confirmação do recebimento exigida pela NR-6. Entrega sem
            // assinatura é pendência, não ficha válida.
            $table->string('assinatura_path')->nullable();
            $table->timestamp('assinado_em')->nullable();

            $table->date('devolvido_em')->nullable();
            $table->string('motivo_devolucao')->nullable();

            // Correção de erro: a entrega não se edita nem se exclui.
            $table->timestamp('estornada_em')->nullable();
            $table->text('motivo_estorno')->nullable();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            $table->index(
                [DominioMultiempresa::COLUNA_TENANT, 'technician_id', 'entregue_em'],
                'ppe_deliveries_company_technician_entregue_em_index'
            );

            $table->index(DominioMultiempresa::COLUNA_TENANT, 'ppe_deliveries_company_id_index');
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
     * Derruba só a tabela criada aqui. Nenhum `DROP COLUMN` em tabela
     * existente, pelo mesmo motivo escrito na migration do cadastro de EPI.
     */
    public function down(): void
    {
        Schema::dropIfExists('ppe_deliveries');
    }
};
