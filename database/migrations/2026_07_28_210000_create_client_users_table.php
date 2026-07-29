<?php

use App\Support\DominioMultiempresa;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Usuário do cliente final: quem acessa o portal do cliente (Plano 15, Task 15.1).
 *
 * Decisão central desta task: `client_users` é uma tabela SEPARADA de `users`,
 * e não um papel a mais no usuário existente. O cliente final não é
 * funcionário do tenant, não recebe papel nem permissão do Spatie Permission,
 * e não pode aparecer em nenhuma listagem interna de usuários. Compartilhar
 * `users` faria toda consulta interna precisar lembrar de excluir clientes, e
 * a primeira que esquecesse vazaria dado do cliente para dentro da operação do
 * tenant.
 *
 * Decisões da modelagem:
 *
 * - **Unique composto `[company_id, email]`**, e não unique global de e-mail
 *   como em `users`: a mesma pessoa pode ser cliente de duas empresas
 *   diferentes da plataforma, e isso é normal aqui. A exceção de `users.email`
 *   (login por e-mail, PRD do Plano 4) não se aplica a este model.
 * - **`password` nasce nullable**: o acesso é criado por convite, e a senha só
 *   passa a existir quando o cliente faz o primeiro acesso. O fluxo do convite
 *   em si é da Task 15.2; aqui só a coluna que o suporta.
 * - **`convite_token` indexado, não único**: é uma string de alta entropia
 *   (gerada pelo Service da Task 15.2), então a colisão é desprezível pelo
 *   próprio espaço do token, o mesmo raciocínio já registrado para
 *   `invitations.token`. O índice existe só para a busca pelo token não varrer
 *   a tabela inteira.
 * - **`ultimo_acesso_em` e `email_verificado_em`** ficam de fora do
 *   preenchimento em massa do model (não fillable): são gravados pelo próprio
 *   fluxo de autenticação, nunca a partir de um formulário.
 * - **`company_id` sem valor padrão**: nasce depois da trait `BelongsToCompany`
 *   já aplicada a todo model de domínio (Deploy 5 do Plano 4), e todo insert
 *   passa por ela.
 *
 * Tabela nova, sem dado existente: um único deploy, sem a divisão em etapas
 * exigida para tabela que já tem dado em produção.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_users', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);

            $table->foreignId('client_id')->constrained()->cascadeOnDelete();

            $table->string('nome');
            $table->string('email');

            // Nulo até o primeiro acesso: acesso nasce por convite, senha vem
            // depois (Task 15.2).
            $table->string('password')->nullable();

            $table->string('telefone')->nullable();

            $table->boolean('ativo')->default(true);

            // Token de convite, alta entropia, apenas indexado (ver docblock
            // acima sobre a colisão ser desprezível pelo próprio espaço do
            // token).
            $table->string('convite_token', 64)->nullable();
            $table->timestamp('convite_expira_em')->nullable();

            $table->timestamp('ultimo_acesso_em')->nullable();
            $table->timestamp('email_verificado_em')->nullable();

            $table->rememberToken();

            $table->timestamps();

            $table->unique(
                [DominioMultiempresa::COLUNA_TENANT, 'email'],
                'client_users_empresa_email_unique'
            );

            $table->index('convite_token', 'client_users_convite_token_index');

            $table->foreign(DominioMultiempresa::COLUNA_TENANT)
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_users');
    }
};
