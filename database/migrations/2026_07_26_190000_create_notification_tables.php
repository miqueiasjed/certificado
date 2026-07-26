<?php

use App\Support\DominioMultiempresa;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estrutura da central de notificações (Plano 14): template, fila e histórico.
 *
 * As três tabelas nascem juntas porque só fazem sentido juntas: o template dá o
 * texto, a fila guarda o que vai sair e o histórico guarda o que aconteceu com
 * cada tentativa. Separá-las em migrations diferentes só criaria a chance de o
 * banco ficar com duas das três.
 *
 * Decisões da modelagem:
 *
 * - **`notification_templates` com unique (`company_id`, `evento`, `canal`)**:
 *   cada empresa tem no máximo um texto por evento e por canal. Dois templates
 *   do mesmo evento e canal fariam a escolha do texto virar sorteio, e o cliente
 *   receberia mensagens diferentes em execuções diferentes. O `company_id` entra
 *   no unique pela regra do projeto: todo unique de domínio é composto com ele,
 *   senão a primeira empresa a cadastrar um evento bloquearia todas as outras.
 *
 * - **`notification_queue.chave_idempotencia` unique global, de propósito**. É a
 *   única unique de domínio deste plano que não leva `company_id`, e a exceção
 *   está declarada em `DominioMultiempresa::UNIQUES_GLOBAIS_MANTIDOS`. A chave é
 *   montada pelo Service (Task 14.2) como
 *   `{evento}:{destinatario_tipo}:{destinatario_id}:{referencia_tipo}:{referencia_id}`,
 *   e os ids ali são os do banco compartilhado, que já pertencem a uma empresa
 *   só. Compor com `company_id` não separaria nada e ainda deixaria passar a
 *   duplicata que a restrição existe para impedir: é ela que garante que a
 *   rotina rodando duas vezes no mesmo dia não mande o mesmo lembrete duas
 *   vezes. A chave nunca leva timestamp, pelo mesmo motivo.
 *
 * - **`agendada_para` é instante, não dia.** O lembrete de véspera combina a
 *   data calculada no fuso do negócio com a hora do disparo, e a rotina de envio
 *   compara com `agora`. Já a decisão de *qual dia* é a véspera é feita com
 *   `BusinessDate`, no Service.
 *
 * - **Índice em (`situacao`, `agendada_para`)**: é exatamente a consulta da
 *   rotina de despacho, que a cada 5 minutos procura o que está `pendente` e já
 *   venceu. Sem ele, a varredura passa a ler a fila inteira, que é a tabela que
 *   mais cresce neste plano.
 *
 * - **`contexto` em json**: guarda os ids da OS, do certificado e do título que
 *   originaram o aviso. Fica em json em vez de virar cinco chaves estrangeiras
 *   nulas porque a origem varia por evento e a fila não precisa navegar até ela.
 *
 * - **`notification_logs` é filha da fila, com `cascadeOnDelete`**: histórico de
 *   item que não existe mais não tem consumidor. O expurgo por retenção apaga a
 *   fila e o histórico vai junto.
 *
 * - **`company_id` sem valor padrão**, diferente das tabelas do Plano 4. Aquele
 *   `DEFAULT 1` existia para o código que ainda não preenchia a coluna
 *   (ver `2026_07_26_160000_make_company_id_required_on_domain_tables`). Aqui
 *   não existe código antigo: as três tabelas nascem depois da trait
 *   `BelongsToCompany`, e todo insert passa por ela. Manter o padrão só criaria
 *   o risco que o Deploy 5 do Plano 4 existe para remover: insert que esquecesse
 *   o tenant gravaria na empresa 1 em silêncio. A migration de Deploy 5 pula
 *   tabela que já está sem padrão, então nada quebra lá.
 *
 * Tabelas novas, sem dado existente: um único deploy, sem a divisão em etapas
 * exigida para tabela que já tem dado em produção.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);

            // Chave do catálogo de eventos (`EventosDeNotificacao`, Task 14.2).
            // Fica como string, e não como enum, porque o catálogo vive no PHP e
            // cresce a cada plano: enum aqui exigiria ALTER TABLE a cada evento
            // novo, e as duas listas divergiriam na primeira vez que alguém
            // esquecesse a migration.
            $table->string('evento', 100);

            // Canal é conjunto fechado e curto: enum dá a garantia no banco.
            $table->enum('canal', ['email', 'whatsapp']);

            // Sem assunto no canal de WhatsApp: só o e-mail usa.
            $table->string('assunto')->nullable();

            $table->text('corpo');

            $table->boolean('ativo')->default(true);

            $table->timestamps();

            $table->unique(
                [DominioMultiempresa::COLUNA_TENANT, 'evento', 'canal'],
                'notification_templates_empresa_evento_canal_unique'
            );

            $table->index(DominioMultiempresa::COLUNA_TENANT);

            $table->foreign(DominioMultiempresa::COLUNA_TENANT)
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });

        Schema::create('notification_queue', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);

            $table->string('evento', 100);
            $table->enum('canal', ['email', 'whatsapp']);

            // 191 caracteres com folga para o formato do Service. Índice unique
            // sobre a coluna inteira, sem prefixo, para a restrição valer de
            // verdade.
            $table->string('chave_idempotencia', 191)->unique();

            $table->enum('destinatario_tipo', ['cliente', 'empresa', 'usuario']);

            // Sem chave estrangeira: o alvo muda de tabela conforme o tipo, e
            // aviso para a própria empresa não tem id de destinatário.
            $table->unsignedBigInteger('destinatario_id')->nullable();

            // E-mail ou telefone, já resolvido no enfileiramento. Guardado aqui
            // congelado de propósito: se o cliente trocar de e-mail depois, o
            // histórico continua mostrando para onde a mensagem foi.
            $table->string('destino');

            $table->string('assunto')->nullable();
            $table->text('corpo');

            $table->json('contexto')->nullable();

            $table->enum('situacao', [
                'pendente',
                'enviando',
                'enviada',
                'falha',
                'cancelada',
                'recusada',
            ])->default('pendente');

            // `dateTime` e não `timestamp`, no mesmo padrão de
            // `scheduled_task_runs` e `audit_logs`: coluna TIMESTAMP no MySQL
            // sofre conversão pelo fuso da sessão e ainda esbarra no limite de
            // 2038, e aqui grava-se instante já resolvido em UTC pela aplicação.
            $table->dateTime('agendada_para');

            $table->unsignedSmallInteger('tentativas')->default(0);
            $table->dateTime('proxima_tentativa_em')->nullable();

            $table->timestamps();

            $table->index(['situacao', 'agendada_para'], 'notification_queue_situacao_agendada_index');
            $table->index(DominioMultiempresa::COLUNA_TENANT);

            $table->foreign(DominioMultiempresa::COLUNA_TENANT)
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });

        Schema::create('notification_logs', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);

            // O nome da tabela é singular, então `constrained()` precisa dele
            // explícito: sozinho ele procuraria `notification_queues`.
            $table->foreignId('notification_queue_id')
                ->constrained('notification_queue')
                ->cascadeOnDelete();

            $table->unsignedSmallInteger('tentativa');

            $table->enum('resultado', ['sucesso', 'falha_temporaria', 'falha_permanente']);

            // Motivo em português, o que a tela mostra para responder "por que o
            // cliente não recebeu".
            $table->text('mensagem')->nullable();

            // Resposta do provedor como veio, para diagnóstico depois do fato.
            $table->json('resposta_bruta')->nullable();

            $table->dateTime('ocorrida_em');

            $table->timestamps();

            $table->index(DominioMultiempresa::COLUNA_TENANT);

            $table->foreign(DominioMultiempresa::COLUNA_TENANT)
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        // Da filha para a mãe: `notification_logs` tem chave estrangeira para a
        // fila, e derrubar a fila primeiro falharia.
        Schema::dropIfExists('notification_logs');
        Schema::dropIfExists('notification_queue');
        Schema::dropIfExists('notification_templates');
    }
};
