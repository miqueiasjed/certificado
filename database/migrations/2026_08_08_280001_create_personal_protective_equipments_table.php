<?php

use App\Support\DominioMultiempresa;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cadastro do modelo de EPI que a empresa compra (Plano 28, Task 28.1).
 *
 * Migration inteiramente aditiva: uma tabela nova, sem dado existente e sem
 * tocar em coluna de tabela em produção. A regra do CLAUDE.md que exige dividir
 * em estrutura, backfill e restrição vale para migration que impõe restrição
 * sobre dado que já existe; aqui não há nem dado nem restrição a impor depois.
 *
 * Decisões da modelagem:
 *
 * - **`numero_ca` e `validade_ca` nascem nullable, e continuam nullable.** O
 *   tenant cadastra o EPI antes de ter o certificado em mãos, e campo em branco
 *   nunca é irregularidade — é a mesma regra do cadastro regulatório do Plano
 *   24, onde acusar de irregular quem apenas não preencheu destruiria a
 *   confiança no checklist. EPI sem CA cadastrado simplesmente não entra na
 *   varredura de vencimento (Task 28.3).
 * - **Não existe unique de `numero_ca`, nem global nem composta com
 *   `company_id`.** Dois modelos de EPI diferentes podem compartilhar o mesmo
 *   Certificado de Aprovação: é comum o fabricante cobrir tamanhos ou variantes
 *   do mesmo equipamento sob um único CA. Unique aqui recusaria cadastro
 *   legítimo.
 * - **`validade_ca` é `date`, nunca `datetime`.** É um dia, não um instante, e
 *   campo `date` não sofre conversão de fuso. A comparação de vencido é por dia
 *   no fuso do negócio, via `App\Support\BusinessDate`, nunca contra `now()` em
 *   UTC, que daria o certificado como vencido a partir das 21h de Brasília do
 *   dia anterior. Esta validade é a **do certificado do fabricante**, e vale
 *   para as entregas futuras daquele modelo; a validade do item que o técnico
 *   tem na mão é `ppe_deliveries.trocar_ate`, coluna separada de propósito.
 * - **`vida_util_dias` nullable significa "sem troca programada"**, e não um
 *   prazo padrão inventado. Prazo inventado vira alerta falso, e alerta falso
 *   faz o usuário desligar o alerta inteiro.
 * - **`enum` em vez de `string` em `tipo`**, mesmo padrão de `devices.situacao`,
 *   das tabelas de estoque e das de frota: o banco recusar um valor inventado
 *   vale mais que a facilidade de acrescentar um item depois.
 * - **`company_id` NOT NULL, sem valor padrão**, mesmo critério das demais
 *   tabelas de domínio criadas depois do Plano 4: a trait `BelongsToCompany`
 *   preenche a coluna na criação.
 */
return new class extends Migration
{
    /**
     * Tipos aceitos, iguais a `PersonalProtectiveEquipment::TIPOS`.
     *
     * @var array<int, string>
     */
    private const TIPOS = [
        'respirador',
        'luva',
        'oculos',
        'protetor_auricular',
        'calcado',
        'vestimenta',
        'outro',
    ];

    /**
     * Executa a migration.
     */
    public function up(): void
    {
        Schema::create('personal_protective_equipments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);

            $table->string('nome');
            $table->enum('tipo', self::TIPOS)->default('outro');
            $table->string('fabricante')->nullable();

            // Certificado de Aprovação: número e validade do certificado do
            // fabricante. Os dois em branco são estado neutro, ver o cabeçalho.
            $table->string('numero_ca')->nullable();
            $table->date('validade_ca')->nullable();

            // Prazo padrão de troca do item entregue. Nulo é "sem troca
            // programada", nunca um prazo padrão inventado.
            $table->unsignedInteger('vida_util_dias')->nullable();

            // Usado pelo Plano 29, que exige EPI por serviço.
            $table->boolean('obrigatorio')->default(false);
            $table->boolean('ativo')->default(true);

            $table->text('observacoes')->nullable();

            $table->timestamps();

            $table->index(DominioMultiempresa::COLUNA_TENANT, 'personal_protective_equipments_company_id_index');
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
     * existente: `down()` destrutivo aplicado por engano em produção é dano sem
     * volta, e é o que não se deve repetir do Plano 27.
     */
    public function down(): void
    {
        Schema::dropIfExists('personal_protective_equipments');
    }
};
