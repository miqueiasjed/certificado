<?php

use App\Support\DominioMultiempresa;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Convite de usuário e trilha de configuração inicial do tenant (Plano 8,
 * Task 8.1).
 *
 * As duas tabelas nascem na mesma migration porque as duas são estrutura de
 * onboarding do tenant novo, sem vínculo entre si além disso.
 *
 * Decisões da modelagem:
 *
 * - **`invitations`**: o token é o que autoriza o cadastro de quem foi
 *   convidado, por isso `string(64)` com `unique` no banco, gerado com
 *   `Str::random(64)` em `Invitation::booted()`. `papel` guarda o nome do
 *   papel Spatie que o convidado recebe ao aceitar, como string solta, porque
 *   o catálogo de papéis vive no Spatie Permission, não numa FK. `expira_em`
 *   é `date`, nunca `datetime`: convite vencido é comparado por dia no fuso do
 *   negócio, e um convite que expira hoje ainda não expirou às 21h de
 *   Brasília, o mesmo raciocínio que vale para garantia de certificado
 *   (`App\Support\BusinessDate`). `aceito_em` e `cancelado_em` são instantes,
 *   por isso `timestamp`, e nulos até o convite ser resolvido de um jeito ou
 *   de outro. `convidado_por` usa `nullOnDelete`: o convite continua contando
 *   a história de quem entrou na empresa mesmo que quem convidou seja
 *   removido depois.
 *
 *   A regra "um convite pendente por e-mail" não vira `unique` composto no
 *   banco porque MySQL não faz unique parcial (só entre convites não aceitos
 *   e não cancelados), e uma unique cheia em `[company_id, email]` impediria
 *   reconvidar alguém cujo convite anterior expirou. A garantia fica no
 *   Service; aqui entra só o índice comum em `[company_id, email]`, para essa
 *   checagem de duplicidade não varrer a tabela inteira.
 *
 * - **`onboarding_steps`**: uma linha por passo da trilha, identificado por
 *   `chave` (string solta, e não enum, porque o catálogo de passos cresce a
 *   cada plano e enum aqui exigiria `ALTER TABLE` a cada passo novo, o mesmo
 *   critério já usado em `notification_templates.evento`). `concluido_em` e
 *   `ignorado_em` são instantes nulos até o tenant concluir ou pular aquele
 *   passo. Unique composta `[company_id, chave]`: o mesmo passo não se repete
 *   para a mesma empresa.
 *
 * - **`company_id` sem valor padrão** nas duas tabelas: nascem depois da
 *   trait `BelongsToCompany` já aplicada a todo model de domínio (Deploy 5 do
 *   Plano 4), e todo insert passa por ela. Manter `DEFAULT 1` aqui só
 *   reintroduziria o risco que aquele deploy existe para eliminar: insert que
 *   esquecesse o tenant gravaria em silêncio na empresa 1.
 *
 * Tabelas novas, sem dado existente: um único deploy, sem a divisão em
 * etapas exigida para tabela que já tem dado em produção.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);

            $table->string('email');
            $table->string('nome')->nullable();

            // Nome do papel Spatie atribuído ao usuário quando aceitar o
            // convite.
            $table->string('papel');

            // Token de uso único que autoriza o cadastro: 64 caracteres,
            // gerado com Str::random(64) em Invitation::booted(). Nunca
            // previsível a partir do e-mail ou do id.
            $table->string('token', 64)->unique();

            $table->foreignId('convidado_por')->nullable()->constrained('users')->nullOnDelete();

            // Dia sem hora relevante: convite vence por dia no fuso do
            // negócio, nunca por instante. Ver Invitation::estaExpirado().
            $table->date('expira_em');

            $table->timestamp('aceito_em')->nullable();
            $table->timestamp('cancelado_em')->nullable();

            $table->timestamps();

            // "Um convite pendente por e-mail" é regra do Service, porque
            // MySQL não faz unique parcial. Aqui fica só o índice comum, para
            // a checagem de duplicidade não varrer a tabela inteira.
            $table->index([DominioMultiempresa::COLUNA_TENANT, 'email']);

            $table->foreign(DominioMultiempresa::COLUNA_TENANT)
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });

        Schema::create('onboarding_steps', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);

            $table->string('chave');

            $table->timestamp('concluido_em')->nullable();
            $table->timestamp('ignorado_em')->nullable();

            $table->timestamps();

            $table->unique(
                [DominioMultiempresa::COLUNA_TENANT, 'chave'],
                'onboarding_steps_empresa_chave_unique'
            );

            $table->foreign(DominioMultiempresa::COLUNA_TENANT)
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    /**
     * Reverte a migration. Nenhum vínculo entre as duas tabelas, então a
     * ordem de queda é indiferente.
     */
    public function down(): void
    {
        Schema::dropIfExists('invitations');
        Schema::dropIfExists('onboarding_steps');
    }
};
