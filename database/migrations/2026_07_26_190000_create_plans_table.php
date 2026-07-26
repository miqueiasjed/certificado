<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de planos comerciais da plataforma (Plano 5, Task 5.1).
 *
 * É a primeira tabela do sistema que nasce sabendo que NÃO é de domínio. Plano
 * comercial pertence a quem vende o SaaS, não a quem o usa: o mesmo "Essencial"
 * é o mesmo para todos os tenants, e dar a cada empresa a própria cópia do
 * catálogo faria a tela do super admin listar dez versões do mesmo plano. Por
 * isso não há `company_id` aqui, não há a trait `BelongsToCompany` no model, e a
 * tabela entra em `DominioMultiempresa::TABELAS_FORA_DO_ESCOPO` com o motivo
 * escrito. Sem essa classificação, o teste da Task 4.10 derruba a suíte, que é
 * exatamente o comportamento desejado: tabela nova sem decisão consciente sobre
 * isolamento é o caminho conhecido para vazamento entre empresas.
 *
 * Decisões da modelagem:
 *
 * - **`slug` com unique global**, sem compor com `company_id`. A regra do
 *   projeto ("todo unique de domínio é composto com company_id") vale para
 *   domínio; aqui o unique global é o que garante que `plano-essencial` é uma
 *   coisa só na plataforma inteira.
 * - **`periodicidade` como string**, não enum de banco. Mesmo critério já
 *   aplicado a `situacao` em `companies`: alterar enum em MySQL exige ALTER
 *   TABLE com reescrita, e o dia em que aparecer periodicidade trimestral não
 *   pode custar uma janela de manutenção. Os valores aceitos (`mensal`,
 *   `anual`) são cobrados na validação do FormRequest, não pelo banco.
 * - **Limite nulo significa ilimitado**, em todos os quatro limites. É assim que
 *   o plano interno da empresa 1 libera tudo sem depender de um valor mágico
 *   como 999999, que um dia alguém compararia com `>=` e transformaria em teto
 *   real.
 * - **`valor` decimal(10,2)**, nunca float. Dinheiro em ponto flutuante erra na
 *   soma, e este valor vira cobrança recorrente no Plano 7.
 * - **`ordem`** existe só para a exibição da tabela de preços; não tem unique,
 *   porque empate de ordem é problema de apresentação, não de integridade.
 *
 * Tabela nova, sem dado existente: cria em um passo só, sem a dança de três
 * etapas que o CLAUDE.md exige para tabela já povoada em produção.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->id();

            $table->string('nome');
            $table->string('slug')->unique();

            $table->decimal('valor', 10, 2)->default(0);

            // `mensal` ou `anual`. String de propósito, ver o cabeçalho.
            $table->string('periodicidade')->default('mensal');

            // Nulo = ilimitado, nos quatro.
            $table->unsignedInteger('limite_usuarios')->nullable();
            $table->unsignedInteger('limite_clientes')->nullable();
            $table->unsignedInteger('limite_os_mes')->nullable();
            $table->unsignedInteger('limite_armazenamento_mb')->nullable();

            $table->boolean('ativo')->default(true);
            $table->integer('ordem')->default(0);
            $table->text('descricao')->nullable();

            $table->timestamps();

            // A listagem pública e a do painel pedem sempre os ativos na ordem
            // de exibição; é a única consulta quente desta tabela.
            $table->index(['ativo', 'ordem']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
