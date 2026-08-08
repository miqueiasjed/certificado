<?php

use App\Support\DominioMultiempresa;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evento recebido do provedor de assinatura eletrônica por webhook (Plano 26,
 * Task 26.3), gravado antes de qualquer processamento, inclusive evento de
 * tipo desconhecido.
 *
 * Mesma função de `gateway_events` (Plano 7/19) para a cobrança: é esta tabela
 * que torna o webhook idempotente e que permite reprocessar um evento depois
 * de corrigir um bug, sem depender de o provedor reenviar.
 *
 * Decisões da modelagem:
 *
 * - **É tabela de domínio, com `company_id` NOT NULL e escopo global**, ao
 *   contrário de `gateway_events`, que fica fora do escopo. A diferença é de
 *   quem é o evento: `gateway_events` guarda também o evento da assinatura que
 *   o tenant paga à plataforma, que é dado da plataforma e é o super admin que
 *   concilia. Aqui todo evento nasce com tenant conhecido — ele é resolvido
 *   pelo `webhook_token` da URL, antes de qualquer leitura do corpo — e é dado
 *   que só a empresa dona do contrato pode ver.
 * - **A unique é composta: `[company_id, evento_id]`.** É ela que sustenta a
 *   idempotência, e compor com `company_id` segue a skill
 *   `permissoes-e-multitenancy`. O `evento_id` é sintético (a ZapSign não
 *   manda identificador de evento próprio), montado a partir do documento, do
 *   tipo e do estado — mesma técnica de
 *   `GatewayPagBank::identificadorSinteticoDeCobranca()`.
 * - **`signature_request_id` é nullable.** O evento é gravado **antes** de o
 *   pedido ser localizado, e um webhook para um documento que não existe neste
 *   tenant fica registrado sem vínculo, como evidência. `nullOnDelete()`
 *   porque o pedido não é apagado neste projeto, e a coluna não pode travar
 *   uma exclusão que hoje não existe.
 * - **`payload` guarda o corpo cru**, nunca o evento já interpretado: guardar
 *   traduzido carregaria para sempre um eventual erro de tradução.
 *
 * Tabela nova, sem dado existente: um único deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signature_events', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);

            $table->string('provedor');
            $table->string('evento_id');
            $table->string('tipo');

            $table->foreignId('signature_request_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->json('payload');

            $table->timestamp('processado_em')->nullable();
            $table->text('erro')->nullable();
            $table->unsignedInteger('tentativas')->default(0);

            $table->timestamps();

            // A restrição que torna o webhook idempotente.
            $table->unique([DominioMultiempresa::COLUNA_TENANT, 'evento_id']);

            $table->foreign(DominioMultiempresa::COLUNA_TENANT)
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signature_events');
    }
};
