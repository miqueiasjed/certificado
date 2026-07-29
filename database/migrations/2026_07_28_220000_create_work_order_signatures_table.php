<?php

use App\Support\DominioMultiempresa;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Assinatura do cliente coletada em campo pelo aplicativo do técnico (Plano 13).
 *
 * Uma linha por OS: a unique em `work_order_id` é o que impede uma segunda
 * assinatura nascer como registro novo. Recoleta de assinatura numa OS já
 * assinada é correção justificada (Task 13.3, futura), que atualiza esta mesma
 * linha e guarda a anterior na auditoria (trait `Auditavel` do model), nunca
 * insere uma segunda.
 *
 * Decisões da modelagem:
 *
 * - **`imagem_path` é caminho de arquivo, nunca a imagem em base64 numa coluna
 *   `text`.** Base64 na tabela infla o backup e deixa lenta toda consulta que
 *   toca a OS, mesmo quando ninguém pediu a assinatura. O arquivo vive no
 *   disco (ou storage equivalente) e esta coluna só guarda o caminho.
 * - **Coordenada nullable, sem exceção.** O técnico pode negar a permissão de
 *   localização no aparelho, e a assinatura continua válida sem coordenada.
 *   `latitude`/`longitude` nunca se tornam obrigatórios, e o servidor nunca
 *   infere localização por IP para preencher o campo: seria rastreamento sem
 *   consentimento, fronteira já registrada no Plano 22.
 * - **`coletada_em` é `datetime`, não `date`.** É o instante da coleta (hora do
 *   aparelho do técnico), não um dia solto; grava-se em UTC e converte-se para
 *   o fuso do negócio só na exibição, via `BusinessDate`, como todo instante
 *   do projeto.
 * - **`user_id` nullable com `nullOnDelete`.** Técnico removido do sistema não
 *   pode derrubar o registro da assinatura: o fato aconteceu e vale mais que o
 *   vínculo com quem coletou.
 * - **`work_order_id` com `cascadeOnDelete`**, no mesmo padrão das demais
 *   tabelas filhas de `work_orders` (`work_order_adequations`,
 *   `work_order_photos`): assinatura de OS apagada não tem sentido nem
 *   consumidor.
 * - **`origem` em `enum`.** Dois valores fechados: `aplicativo` (coleta no PWA
 *   offline do técnico) e `web` (coleta pelo painel, quando existir).
 * - **`company_id` NOT NULL, sem valor padrão.** Tabela nasce depois do
 *   Deploy 5, com a trait `BelongsToCompany` já ativa preenchendo a coluna na
 *   criação; padrão temporário aqui só criaria o risco que o Plano 4 existe
 *   para evitar (insert esquecido caindo em silêncio no tenant errado). A FK é
 *   `restrictOnDelete`/`restrictOnUpdate`, igual às demais tabelas de domínio
 *   recentes (`device_replacements`, Task 11.9).
 *
 * Tabela nova, sem dado existente: um deploy só, sem a divisão em etapas
 * exigida de tabela que já roda em produção.
 */
return new class extends Migration
{
    /**
     * Executa a migration.
     */
    public function up(): void
    {
        Schema::create('work_order_signatures', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);

            $table->foreignId('work_order_id')->unique()->constrained('work_orders')->cascadeOnDelete();

            $table->string('assinante_nome');
            $table->string('assinante_documento')->nullable();
            $table->string('assinante_vinculo')->nullable();
            $table->string('imagem_path')->nullable();

            // Instante da coleta, no aparelho do técnico: gravado em UTC e
            // convertido para o fuso do negócio só na exibição.
            $table->dateTime('coletada_em');

            // Coordenada nullable de propósito: continua nula quando o
            // técnico não autoriza a localização no aparelho.
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('precisao_metros')->nullable();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->enum('origem', ['aplicativo', 'web']);

            $table->timestamps();

            $table->index(DominioMultiempresa::COLUNA_TENANT);

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
        Schema::dropIfExists('work_order_signatures');
    }
};
