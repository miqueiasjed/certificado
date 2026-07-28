<?php

use App\Support\DominioMultiempresa;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operação de sincronização enviada pelo aplicativo do técnico (Plano 12,
 * Task 12.1).
 *
 * Cada linha é uma ação que o técnico registrou no celular (evento de
 * dispositivo, avistamento, adequação, foto ou conclusão de OS) e que o
 * aplicativo envia ao servidor, muitas vezes bem depois de registrada, porque
 * o técnico ficou sem rede em campo.
 *
 * Decisões da modelagem:
 *
 * - **`operacao_uuid` é a chave da idempotência, e é `unique` no banco.** O
 *   uuid é gerado no celular, no instante do registro, nunca pelo servidor:
 *   é a única forma de reconhecer o reenvio de uma operação cuja resposta se
 *   perdeu na rede. Sem a restrição no banco, dois envios simultâneos da
 *   mesma fila (o app reenvia por timeout, ou duas abas do mesmo dispositivo)
 *   aplicariam o mesmo evento duas vezes na OS. O unique **não** é composto
 *   com `company_id`: o dispositivo já pertence a uma empresa só, e o espaço
 *   de um uuid v4 torna a colisão entre tenants desprezível, o mesmo
 *   raciocínio já registrado para `invitations.token`. A exceção fica
 *   declarada em `DominioMultiempresa::UNIQUES_GLOBAIS_MANTIDOS`.
 * - **`registrada_em` é o instante do celular; `created_at` é o instante do
 *   servidor.** Os dois ficam gravados, e a diferença entre eles é o tempo
 *   que o técnico passou sem rede, o que explica operação chegando fora de
 *   ordem. Nunca usar um no lugar do outro.
 * - **`tipo` e `situacao` são `string`, não `enum`.** Os dois catálogos
 *   crescem a cada plano que adiciona um tipo de operação ou um desfecho
 *   novo, e enum aqui exigiria `ALTER TABLE` a cada um, o mesmo critério já
 *   aplicado a `onboarding_steps.chave` e a `subscriptions.situacao`. Sem
 *   default: quem decide o valor inicial é o Service que grava a operação.
 * - **`work_order_id` nullable com `nullOnDelete()`.** Nem toda operação
 *   sincronizada está ligada a uma OS (ex.: conclusão de cadastro avulso), e
 *   quando está, apagar a OS depois não pode derrubar o histórico de
 *   sincronização; mesmo critério de `certificates.work_order_id` e
 *   `financial_entries.work_order_id`.
 * - **`user_id` obrigatório, sem `nullOnDelete()`.** É quem enviou a operação
 *   pelo aplicativo, e a ação padrão do banco (restrict) impede apagar um
 *   usuário com histórico de sincronização em silêncio.
 * - **`payload` é `json`.** Guarda o corpo inteiro enviado pelo aplicativo,
 *   o que permite reaplicar ou depurar uma operação sem depender do celular
 *   reenviar.
 * - **`company_id` sem valor padrão.** Nasce depois da trait
 *   `BelongsToCompany` já aplicada a todo model de domínio (Deploy 5 do
 *   Plano 4), e todo insert passa por ela. `DEFAULT 1` aqui só reintroduziria
 *   o risco que aquele deploy existe para eliminar.
 *
 * Tabela nova, sem dado existente: um único deploy, sem a divisão em etapas
 * exigida para tabela que já roda em produção.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_operations', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);

            $table->foreignId('user_id')->constrained();

            // Chave da idempotência: gerada no celular, nunca no servidor.
            // Unique global de propósito (ver o cabeçalho desta migration).
            $table->uuid('operacao_uuid')->unique();

            // evento_dispositivo, avistamento, adequacao, foto, conclusao_os.
            $table->string('tipo');

            $table->foreignId('work_order_id')->nullable()->constrained()->nullOnDelete();

            $table->json('payload');

            // aplicada, conflito, recusada.
            $table->string('situacao');

            // Instante em que o técnico registrou no celular.
            $table->timestamp('registrada_em');

            // Instante em que o servidor aplicou a operação. Nulo até lá.
            $table->timestamp('aplicada_em')->nullable();

            $table->text('erro')->nullable();

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
        Schema::dropIfExists('sync_operations');
    }
};
