<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Validade dos documentos regulatórios da empresa e do registro do produto na
 * Anvisa (Plano 24, Task 24.1).
 *
 * A RDC nº 622/2022 exige que a empresa especializada mantenha licença
 * sanitária, licença ambiental, alvará de funcionamento e responsável técnico
 * habilitado com registro no conselho, todos válidos. O sistema já guardava os
 * **números** desses documentos em `companies` (`license_sanitary`,
 * `license_environmental`, `license_business`, `crq`/`register_crea`), mas não
 * a data em que cada um vence, e por isso não tinha como avisar do vencimento
 * nem responder o que falta para a empresa estar regular.
 *
 * Do mesmo modo, `organ_registrations` guardava só o número do registro do
 * produto no Ministério da Saúde/Anvisa, sem validade e sem situação. A norma
 * só admite produto saneante desinfestante **registrado**, então aplicar
 * produto de registro vencido ou cancelado é irregularidade, e o sistema
 * precisa ao menos avisar (Task 24.4).
 *
 * Duas tabelas com dado em produção
 * ---------------------------------
 * Todas as colunas nascem **nullable** (ou com default, no caso de `situacao`)
 * e nenhuma restrição nova é criada, então esta é a etapa de estrutura, e ela
 * pode ir em deploy único: nenhuma linha existente vira inválida.
 *
 * **Validade nula significa "não informado", nunca "vencido".** Tratar nulo
 * como vencido acusaria de irregular todo cliente que ainda não preencheu o
 * cadastro, que é hoje a totalidade deles. O backfill é manual, pelo próprio
 * tenant, nas telas da Task 24.6.
 *
 * `organ_registrations.situacao`
 * -----------------------------
 * Fechada em `ativo`, `vencido` e `cancelado`, com default `ativo`, para que a
 * linha existente continue com o significado que tinha antes desta migration
 * (registro em uso). `vencido` é derivado da `validade` pela rotina da Task
 * 24.3; `cancelado` é informação que só vem de fora (cancelamento publicado
 * pela Anvisa) e por isso não tem como ser inferida de data nenhuma.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            // Validade do registro do responsável técnico no conselho
            // (CRQ/CREA/CRBio/CRMV, conforme a habilitação).
            $table->date('registro_conselho_validade')->nullable()->after('register_crea');

            $table->date('licenca_sanitaria_validade')->nullable()->after('registro_conselho_validade');
            $table->date('licenca_ambiental_validade')->nullable()->after('licenca_sanitaria_validade');
            $table->date('licenca_funcionamento_validade')->nullable()->after('licenca_ambiental_validade');
        });

        Schema::table('organ_registrations', function (Blueprint $table): void {
            $table->date('validade')->nullable()->after('record');
            $table->enum('situacao', ['ativo', 'vencido', 'cancelado'])->default('ativo')->after('validade');
        });
    }

    public function down(): void
    {
        Schema::table('organ_registrations', function (Blueprint $table): void {
            $table->dropColumn(['validade', 'situacao']);
        });

        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn([
                'registro_conselho_validade',
                'licenca_sanitaria_validade',
                'licenca_ambiental_validade',
                'licenca_funcionamento_validade',
            ]);
        });
    }
};
