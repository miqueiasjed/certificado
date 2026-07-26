<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preferência de contato do cliente, para a central de notificações do Plano 14.
 *
 * Decisões:
 *
 * - **`aceita_email` e `aceita_whatsapp` nascem `true`.** É decisão de negócio
 *   registrada no plano: nascer `false` faria a central subir sem mandar nada, e
 *   a empresa concluiria que o recurso está quebrado. A recusa é registrada
 *   quando o cliente pedir, e é aí que a coluna vira `false`.
 * - **`canal_preferido` nullable**: nulo significa "a empresa decide", que é o
 *   estado de todo cliente existente. Preencher é opção do cliente que pediu um
 *   canal específico, e não obrigação de cadastro.
 * - **`email_notificacao` separado de `email`**: em cliente pessoa jurídica o
 *   e-mail cadastral costuma ser o da nota fiscal, e quem recebe aviso de visita
 *   é outra pessoa. Nulo cai no `email` de sempre.
 *
 * Colunas novas com valor padrão em tabela que já tem dado: o MySQL preenche as
 * linhas existentes com o padrão no próprio ALTER, então o backfill é o próprio
 * padrão e não há segunda etapa a conferir. Nenhuma restrição nova é criada
 * sobre dado antigo, o que mantém isto em um deploy só.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->boolean('aceita_email')->default(true)->after('notes');
            $table->boolean('aceita_whatsapp')->default(true)->after('aceita_email');
            $table->enum('canal_preferido', ['email', 'whatsapp'])->nullable()->after('aceita_whatsapp');
            $table->string('email_notificacao')->nullable()->after('canal_preferido');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn([
                'aceita_email',
                'aceita_whatsapp',
                'canal_preferido',
                'email_notificacao',
            ]);
        });
    }
};
