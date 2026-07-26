<?php

use App\Support\DominioMultiempresa;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Substituição de um dispositivo por outro no mesmo ponto de monitoramento.
 *
 * Dispositivo danificado, perdido ou trocado de tipo não é editado nem apagado:
 * ele continua no banco como o histórico daquele ponto, com os eventos que
 * gerou, e um dispositivo novo assume dali em diante. Esta tabela é o elo entre
 * os dois, e é ela que permite reconstruir a linha do tempo completa do ponto
 * perante fiscalização (a leitura da cadeia é `DeviceReplacementService`, na
 * Task 11.5).
 *
 * Decisões da modelagem:
 *
 * - **Uma linha por substituição, ligando dois dispositivos.** `device_anterior_id`
 *   e `device_novo_id` formam uma cadeia: o novo de hoje é o anterior da próxima
 *   troca. Guardar a cadeia aqui, e não um campo `substituido_por` em `devices`,
 *   é o que deixa registrar o motivo, a data e o autor de cada troca.
 * - **Nenhum evento migra de um dispositivo para o outro.** Evento pertence ao
 *   dispositivo que o gerou, e mover falsificaria o registro. A continuidade do
 *   ponto vem desta tabela.
 * - **`restrictOnDelete` nas duas pontas.** Apagar um dispositivo que participa
 *   de uma substituição quebraria o histórico do ponto justamente no lugar onde
 *   ele é documento. O banco recusa, e a exclusão passa a ser decisão de
 *   negócio explícita, com a regra no Service.
 * - **`user_id` nullable com `nullOnDelete`.** Usuário removido não pode
 *   derrubar o registro da substituição: o fato aconteceu e vale mais que o
 *   vínculo com quem o registrou.
 * - **`substituido_em` é `date`, não `datetime`.** É o dia da troca em campo, e
 *   a hora não muda nada na leitura do histórico. Campo de dia nunca passa por
 *   conversão de fuso, que é o que produz o erro de um dia.
 * - **`motivo` em `enum`.** Cinco valores fechados, com `outro` como saída de
 *   emergência acompanhada de `observacao` (a obrigatoriedade da observação
 *   quando o motivo for `outro` é validação de FormRequest, na Task 11.5).
 * - **`company_id` NOT NULL, sem valor padrão.** Diferente das tabelas do
 *   Plano 4, esta nasce depois do Deploy 5, com a trait `BelongsToCompany` já
 *   ativa preenchendo a coluna na criação. O `DEFAULT 1` temporário daquelas
 *   tabelas existia para cobrir a janela sem a trait; aqui ele só criaria o
 *   risco que o Plano 4 existe para evitar, que é o insert esquecido gravar em
 *   silêncio no tenant errado. Sem padrão, o esquecimento vira erro de banco
 *   visível. A FK é `restrictOnDelete`/`restrictOnUpdate`, igual às demais.
 * - **Índice separado em cada uma das duas colunas de dispositivo.** As
 *   consultas do histórico partem ora do anterior ("o que substituiu este?"),
 *   ora do novo ("este substituiu quem?"), e um índice composto só serviria à
 *   primeira. As chaves estrangeiras já criam esses índices no MySQL; são
 *   declarados assim mesmo para a intenção não depender do driver.
 *
 * Tabela nova, sem dado existente: um deploy só, sem a divisão em etapas
 * exigida de tabela que já roda em produção.
 */
return new class extends Migration
{
    /**
     * Motivos possíveis de uma substituição. `outro` exige `observacao`
     * preenchida, e essa regra vive no FormRequest da Task 11.5.
     *
     * @var array<int, string>
     */
    private const MOTIVOS = ['danificado', 'perdido', 'desgaste', 'troca_de_tipo', 'outro'];

    /**
     * Executa a migração.
     */
    public function up(): void
    {
        Schema::create('device_replacements', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);

            $table->foreignId('device_anterior_id')->constrained('devices')->restrictOnDelete();
            $table->foreignId('device_novo_id')->constrained('devices')->restrictOnDelete();

            $table->enum('motivo', self::MOTIVOS);
            $table->text('observacao')->nullable();

            // Dia da troca em campo: sem hora relevante e sem conversão de fuso.
            $table->date('substituido_em');

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('device_anterior_id', 'device_replacements_device_anterior_id_index');
            $table->index('device_novo_id', 'device_replacements_device_novo_id_index');

            $table->index(DominioMultiempresa::COLUNA_TENANT);

            $table->foreign(DominioMultiempresa::COLUNA_TENANT)
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    /**
     * Reverte a migração.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_replacements');
    }
};
