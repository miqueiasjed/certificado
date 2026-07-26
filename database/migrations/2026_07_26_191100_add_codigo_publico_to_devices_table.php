<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deploy 1 do Plano 11: identificador público do dispositivo e situação do
 * ponto de monitoramento.
 *
 * `codigo_publico` é o que vai impresso na etiqueta e dentro do QR code, e por
 * isso não pode ser o `id` nem nada derivado dele. Sequência interna exposta
 * conta quantos dispositivos a empresa tem e deixa qualquer um trocar o número
 * na URL para ler o dispositivo do vizinho. A geração do código fica na
 * Task 11.2, junto do backfill dos dispositivos que já existem.
 *
 * Decisões desta etapa:
 *
 * - **`codigo_publico` nullable e sem unique, de propósito.** A tabela tem dado
 *   em produção e nenhuma linha tem código ainda. `NOT NULL` e unique composto
 *   `[company_id, codigo_publico]` entram na Task 11.2, depois do backfill
 *   conferido, em deploy separado, seguindo a regra do projeto de nunca aplicar
 *   estrutura, backfill e restrição no mesmo deploy.
 * - **Índice simples agora.** Serve à busca por código na leitura do QR code e
 *   é a base do unique composto da Task 11.2. Índice não-unique aceita nulo
 *   repetido, então convive com o estado atual.
 * - **12 caracteres.** Cabe o formato curto e legível a olho nu na etiqueta,
 *   com folga para o alfabeto sem ambiguidade que a Task 11.2 vai definir.
 * - **`situacao` é campo novo, e `active` não é tocado.** `active` continua
 *   valendo exatamente como hoje para todas as telas e consultas existentes.
 *   `situacao` responde a outra pergunta: o que aconteceu com o ponto físico
 *   (segue em uso, foi substituído por outro dispositivo, ou foi removido).
 *   Reaproveitar `active` para isso mudaria o comportamento das telas atuais
 *   neste mesmo deploy, que é justamente o que este deploy não faz.
 * - **`enum` em vez de `string`.** São três valores fechados, definidos pelo
 *   fluxo de substituição da Task 11.5, e o banco recusar um valor inventado
 *   vale mais do que a facilidade de acrescentar um quarto depois. Mesmo padrão
 *   de `work_order_adequations.status`.
 *
 * Deploy sem efeito observável: coluna nullable e coluna com padrão que já
 * descreve o estado de todo dispositivo existente.
 */
return new class extends Migration
{
    /**
     * Situações possíveis de um dispositivo. `ativo` é o padrão porque descreve
     * corretamente todo dispositivo que existe hoje.
     *
     * @var array<int, string>
     */
    private const SITUACOES = ['ativo', 'substituido', 'removido'];

    /**
     * Executa a migração.
     */
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            if (! Schema::hasColumn('devices', 'codigo_publico')) {
                $table->string('codigo_publico', 12)->nullable()->after('number');
                $table->index('codigo_publico');
            }

            if (! Schema::hasColumn('devices', 'situacao')) {
                $table->enum('situacao', self::SITUACOES)->default('ativo')->after('active');
            }
        });
    }

    /**
     * Reverte a migração. O índice cai junto com a coluna no MySQL, mas é
     * removido antes de propósito: assim a reversão não depende desse detalhe
     * do driver.
     */
    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            if (Schema::hasColumn('devices', 'codigo_publico')) {
                $table->dropIndex('devices_codigo_publico_index');
                $table->dropColumn('codigo_publico');
            }

            if (Schema::hasColumn('devices', 'situacao')) {
                $table->dropColumn('situacao');
            }
        });
    }
};
