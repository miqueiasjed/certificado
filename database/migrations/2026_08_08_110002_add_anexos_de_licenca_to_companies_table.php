<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anexo digitalizado de cada documento regulatório da empresa (Plano 24,
 * Task 24.6).
 *
 * A tela de validades pede o número, a validade **e** o arquivo de cada
 * documento: quem recebe fiscalização precisa mostrar o documento, não só
 * afirmar que ele existe, e o arquivo espalhado em e-mail e pasta de rede é
 * exatamente o que some na hora errada.
 *
 * Quatro colunas de caminho no disco `public`, no mesmo formato de
 * `companies.logo_path` e das três colunas de assinatura, que já guardam
 * caminho de arquivo dessa forma. Nada de tabela de anexos: é um arquivo por
 * documento, sem versionamento e sem metadado próprio, e uma tabela para isso
 * só acrescentaria junção.
 *
 * Tabela com dado em produção
 * ---------------------------
 * Todas nascem **nullable**, sem restrição nova e sem backfill: nenhuma linha
 * existente vira inválida, então esta é etapa de estrutura pura e vai em
 * deploy único.
 *
 * Ressalva de ordem de aplicação: as demais colunas deste plano subiram no
 * Deploy 1 (Task 24.1). Estas quatro são estrutura descoberta na Task 24.6,
 * junto com a tela que as usa, e por isso acompanham o deploy das telas
 * (Deploy 4). Podem ser antecipadas para o Deploy 1 sem qualquer efeito: sem
 * a tela, ninguém escreve nelas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('registro_conselho_arquivo')->nullable()->after('registro_conselho_validade');
            $table->string('licenca_sanitaria_arquivo')->nullable()->after('licenca_sanitaria_validade');
            $table->string('licenca_ambiental_arquivo')->nullable()->after('licenca_ambiental_validade');
            $table->string('licenca_funcionamento_arquivo')->nullable()->after('licenca_funcionamento_validade');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn([
                'registro_conselho_arquivo',
                'licenca_sanitaria_arquivo',
                'licenca_ambiental_arquivo',
                'licenca_funcionamento_arquivo',
            ]);
        });
    }
};
