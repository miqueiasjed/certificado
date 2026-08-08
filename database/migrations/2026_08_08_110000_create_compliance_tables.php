<?php

use App\Support\DominioMultiempresa;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conformidade regulatória (Plano 24, Task 24.1): referência normativa
 * configurável por tenant e resultado da última verificação do checklist.
 *
 * Duas tabelas novas, sem dado existente: um único deploy, sem a divisão em
 * etapas exigida para tabela que já tem dado em produção.
 *
 * `normative_references`
 * ----------------------
 * A RDC nº 52/2009 foi substituída pela RDC nº 622/2022 (publicada em
 * 09/03/2022, vigente desde 01/04/2022, que também revogou a RDC nº 20/2010).
 * Documento emitido citando a resolução errada é documento que vai para a mão
 * de fiscal com a norma revogada, e a próxima resolução virá. Por isso o texto
 * é dado, e não constante em código: quando a Anvisa publicar a substituta, o
 * tenant (ou a plataforma) troca uma linha, sem alteração de sistema.
 *
 * - **`company_id` nullable.** `null` é a referência padrão da plataforma, que
 *   vale para todo tenant que não cadastrou a própria. Linha com `company_id`
 *   preenchido é a referência daquele tenant e tem prioridade sobre a da
 *   plataforma na resolução por `chave`
 *   (`App\Models\NormativeReference::resolver()`). É exatamente por causa
 *   dessa linha de `company_id` nulo que o model **não** leva
 *   `BelongsToCompany`: o escopo global filtra `company_id = <tenant>`, o que
 *   esconderia o padrão da plataforma e deixaria o documento sem referência
 *   nenhuma. A classificação está registrada em
 *   `App\Support\DominioMultiempresa::TABELAS_FORA_DO_ESCOPO` e
 *   `MODELS_FORA_DO_ESCOPO`, com o motivo, e o teste da Task 4.10 cobra as
 *   duas listas.
 * - **`cascadeOnDelete` no `company_id`**, diferente do `restrictOnDelete`
 *   padrão das tabelas de domínio: a referência normativa é configuração, não
 *   registro operacional. Empresa removida não deixa configuração órfã para
 *   trás, e nada de valor documental se perde (o documento já emitido guarda o
 *   texto que foi impresso, ver a Task 24.2).
 * - **Unique composta `[company_id, chave]`.** Uma referência por chave por
 *   tenant, e uma da plataforma (`company_id` nulo). Em MySQL e em SQLite
 *   `NULL` não colide com `NULL` em unique, então a restrição não impede duas
 *   linhas de plataforma com a mesma chave; quem garante uma só é o
 *   `updateOrCreate` do seeder, e a leitura por `chave` usa `first()` de
 *   qualquer forma. Compor mesmo assim vale pelo que a restrição de fato
 *   protege, que é o tenant não acumular duas referências da mesma chave.
 * - **`vigente_desde` é `date`**, não `datetime`: é o dia em que a resolução
 *   passou a valer, sem hora relevante.
 * - **`ativo`** permite guardar a referência revogada ao lado da vigente, para
 *   consulta, sem que ela seja escolhida na resolução.
 *
 * `compliance_checks`
 * -------------------
 * Guarda o resultado da **última** verificação de cada item do checklist, não
 * o histórico: o histórico de conformidade é a auditoria do Plano 3. Por isso
 * a unique composta `[company_id, item]` e o `verificado_em` sobrescrito a
 * cada rodada.
 *
 * - **`situacao` fechada em quatro valores.** `nao_aplicavel` e a ausência de
 *   linha são o que impede o checklist de acusar de `irregular` quem
 *   simplesmente ainda não preencheu o cadastro: "não informado" nunca é
 *   "irregular", e tratar assim destruiria a confiança no checklist inteiro.
 * - **`detalhe` é `text` nullable**: a frase que explica o que falta, em
 *   português, montada pelo serviço de verificação (Task 24.5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('normative_references', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT)->nullable();

            // Ex.: `rdc_principal`.
            $table->string('chave', 60);

            // Ex.: "RDC nº 622, de 9 de março de 2022".
            $table->string('texto');

            // Ex.: "RDC nº 622/2022".
            $table->string('texto_curto')->nullable();

            $table->date('vigente_desde')->nullable();
            $table->boolean('ativo')->default(true);

            $table->timestamps();

            $table->unique([DominioMultiempresa::COLUNA_TENANT, 'chave']);

            $table->foreign(DominioMultiempresa::COLUNA_TENANT)
                ->references('id')
                ->on('companies')
                ->cascadeOnDelete()
                ->restrictOnUpdate();
        });

        Schema::create('compliance_checks', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);

            // Chave do item do checklist, ex.: `licenca_sanitaria`.
            $table->string('item', 60);

            $table->enum('situacao', ['regular', 'atencao', 'irregular', 'nao_aplicavel']);

            $table->text('detalhe')->nullable();

            $table->timestamp('verificado_em')->nullable();

            $table->timestamps();

            $table->unique([DominioMultiempresa::COLUNA_TENANT, 'item']);

            $table->foreign(DominioMultiempresa::COLUNA_TENANT)
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_checks');
        Schema::dropIfExists('normative_references');
    }
};
