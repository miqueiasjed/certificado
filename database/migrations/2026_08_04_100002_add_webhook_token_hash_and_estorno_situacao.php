<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Duas mudanças da Task 19.4 sobre o schema da Task 19.1, ainda sem dado em
 * produção (as duas tabelas nasceram na mesma sessão desta task): a coluna
 * que resolve o tenant a partir do `webhook_token` da URL, e a situação nova
 * que o estorno de uma cobrança já paga precisa registrar.
 *
 * ## `payment_gateway_configs.webhook_token_hash`
 *
 * `webhook_token` é cifrado com o cast `encrypted` (Task 19.1), que usa um IV
 * aleatório a cada gravação: o texto cifrado nunca se repete, mesmo para o
 * mesmo valor em claro, e por isso `WHERE webhook_token = ?` nunca encontra
 * nada. O webhook chega em `POST /webhooks/cobranca/{webhookToken}` com o
 * token em texto puro na URL, sem usuário e sem sessão: é preciso localizar o
 * tenant por igualdade, sem decifrar e comparar tenant a tenant em memória
 * (não escala, e é o tipo de solução que quebra silenciosamente com poucos
 * tenants a mais).
 *
 * A solução adotada: uma coluna auxiliar com o HMAC-SHA256 do token em claro,
 * usando a própria `APP_KEY` como chave
 * (`PaymentGatewayConfig::hashDoWebhookToken()`), gravada automaticamente
 * sempre que `webhook_token` muda (`PaymentGatewayConfig::booted()`). HMAC é
 * determinístico — o mesmo texto sempre produz o mesmo hash, e é isso que
 * torna o índice único útil — e não é reversível, então esta coluna não
 * reabre o vazamento que a cifragem de `webhook_token` existe para fechar.
 * `webhook_token` continua sendo a fonte de verdade armazenada; o hash é só
 * um índice de busca.
 *
 * `nullable` porque nem toda configuração tem `webhook_token` preenchido
 * ainda (a tela que gera o token é da Task 19.6/19.7); `unique` porque dois
 * tenants com o mesmo hash seria a rota de um encontrar a cobrança do outro
 * pela primeira linha que a consulta trouxesse.
 *
 * ## `charges.situacao` ganha `estornada`
 *
 * Cobrança estornada é informação diferente de cancelada: a cancelada nunca
 * chegou a ser paga, a estornada foi paga e o dinheiro voltou. Perder essa
 * distinção custaria a exatidão do documento fiscal (CLAUDE.md) e da
 * conciliação da Task 19.6. Enum do MySQL não aceita acréscimo de valor via
 * `Schema::table()`; a alteração é feita com `ALTER TABLE ... MODIFY`, sem
 * Doctrine DBAL (não instalado neste projeto).
 *
 * Tabelas sem dado em produção, criadas na mesma sessão desta task: cabe num
 * único deploy, sem a divisão em etapas do CLAUDE.md (que vale para coluna
 * que troca de estado com dado já existente).
 */
return new class extends Migration
{
    /**
     * @var array<int, string>
     */
    private const SITUACOES_DE_COBRANCA_COM_ESTORNO = [
        'pendente', 'emitida', 'paga', 'vencida', 'cancelada', 'erro', 'estornada',
    ];

    /**
     * @var array<int, string>
     */
    private const SITUACOES_DE_COBRANCA_SEM_ESTORNO = [
        'pendente', 'emitida', 'paga', 'vencida', 'cancelada', 'erro',
    ];

    public function up(): void
    {
        Schema::table('payment_gateway_configs', function (Blueprint $table): void {
            $table->string('webhook_token_hash', 64)->nullable()->after('webhook_token');
            $table->unique('webhook_token_hash');
        });

        DB::statement(sprintf(
            "ALTER TABLE charges MODIFY situacao ENUM(%s) NOT NULL DEFAULT 'pendente'",
            $this->listaSql(self::SITUACOES_DE_COBRANCA_COM_ESTORNO)
        ));
    }

    public function down(): void
    {
        DB::statement(sprintf(
            "ALTER TABLE charges MODIFY situacao ENUM(%s) NOT NULL DEFAULT 'pendente'",
            $this->listaSql(self::SITUACOES_DE_COBRANCA_SEM_ESTORNO)
        ));

        Schema::table('payment_gateway_configs', function (Blueprint $table): void {
            $table->dropUnique(['payment_gateway_configs_webhook_token_hash_unique']);
            $table->dropColumn('webhook_token_hash');
        });
    }

    /**
     * @param  array<int, string>  $valores
     */
    private function listaSql(array $valores): string
    {
        return implode(',', array_map(static fn (string $valor): string => "'{$valor}'", $valores));
    }
};
