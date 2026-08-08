<?php

use App\Support\DominioMultiempresa;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rascunho gerado por modelo de linguagem e medição de uso por tenant
 * (Plano 25).
 *
 * Duas colunas de conteúdo, e não uma: `conteudo_gerado` guarda o que o modelo
 * escreveu e nunca é sobrescrito; `conteudo_revisado` guarda o que a pessoa
 * aprovou. Comparar as duas é o que prova, perante uma auditoria sobre a
 * autoria do laudo, que houve revisão humana antes da emissão. Sobrescrever o
 * gerado apagaria justamente a prova.
 *
 * `modelo` é gravado no rascunho e em cada uso porque o identificador do
 * modelo muda com o tempo: sem ele, um parecer gerado hoje e questionado daqui
 * a um ano não teria como ser atribuído à versão que o escreveu.
 *
 * `custo_estimado` tem 6 casas decimais de propósito. O custo de uma chamada é
 * fração de centavo, e duas casas arredondariam toda a apuração para zero, o
 * que tornaria a medição por tenant inútil justamente para o que ela existe:
 * conhecer o custo antes de o recurso virar item de plano.
 *
 * As duas tabelas são novas e nascem completas em um único deploy, sem etapa
 * de backfill: não há dado existente a preservar.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->criarAiDrafts();
        $this->criarAiUsages();
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usages');
        Schema::dropIfExists('ai_drafts');
    }

    private function criarAiDrafts(): void
    {
        Schema::create('ai_drafts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);
            $table->string('tipo', 40);
            $table->string('origem_tipo');
            $table->unsignedBigInteger('origem_id');
            $table->text('conteudo_gerado');
            $table->text('conteudo_revisado')->nullable();
            $table->string('situacao', 20)->default('gerado');
            $table->string('modelo');
            $table->foreignId('gerado_por')->constrained('users')->restrictOnDelete();
            $table->foreignId('revisado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revisado_em')->nullable();
            $table->timestamps();

            $table->index(['origem_tipo', 'origem_id']);
            $table->index([DominioMultiempresa::COLUNA_TENANT, 'tipo', 'situacao'], 'ai_drafts_company_tipo_situacao_index');

            $table->index(DominioMultiempresa::COLUNA_TENANT, 'ai_drafts_company_id_index');
            $table->foreign(DominioMultiempresa::COLUNA_TENANT)
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    private function criarAiUsages(): void
    {
        Schema::create('ai_usages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);
            // Nullable porque a chamada que falhou, ou a que só apurou custo sem
            // produzir texto, também precisa ser medida: teto e conta se apuram
            // sobre a chamada, não sobre o rascunho.
            $table->foreignId('ai_draft_id')->nullable()->constrained('ai_drafts')->nullOnDelete();
            $table->string('tipo', 40);
            $table->string('modelo');
            $table->unsignedInteger('tokens_entrada')->default(0);
            $table->unsignedInteger('tokens_saida')->default(0);
            $table->unsignedInteger('tokens_cache_leitura')->default(0);
            $table->decimal('custo_estimado', 10, 6)->default(0);
            $table->unsignedInteger('duracao_ms')->default(0);
            $table->boolean('sucesso')->default(true);
            $table->text('erro')->nullable();
            $table->timestamps();

            $table->index([DominioMultiempresa::COLUNA_TENANT, 'created_at'], 'ai_usages_company_id_created_at_index');

            $table->index(DominioMultiempresa::COLUNA_TENANT, 'ai_usages_company_id_index');
            $table->foreign(DominioMultiempresa::COLUNA_TENANT)
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }
};
