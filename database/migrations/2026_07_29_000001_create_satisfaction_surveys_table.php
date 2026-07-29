<?php

use App\Support\DominioMultiempresa;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pesquisa de satisfação disparada após a OS concluída (Plano 16, Task 16.1).
 *
 * Decisões da modelagem:
 *
 * - **`work_order_id` com `unique`.** Uma pesquisa por visita: duas pesquisas
 *   da mesma OS gerariam média torta no painel de satisfação. Mesmo critério
 *   de `work_order_signatures.work_order_id`, e o `cascadeOnDelete()` segue o
 *   mesmo raciocínio, OS apagada não tem sentido nem consumidor para a
 *   pesquisa que nasceu dela.
 * - **`token` com 64 caracteres e `unique`.** É o que permite responder a
 *   pesquisa sem login: token curto seria adivinhável, e responder a pesquisa
 *   de outro cliente contamina o indicador da empresa. O espaço grande de
 *   `Str::random(64)` (gerado na Task 16.5) é o que torna a colisão
 *   desprezível, mesmo raciocínio já registrado para `invitations.token`.
 *   `unique()` já cria o índice usado na busca pelo token; nenhum índice
 *   redundante é adicionado.
 * - **`client_id` obrigatório, com `cascadeOnDelete()`.** Mesma decisão de
 *   `work_orders.client_id`: toda pesquisa nasce de uma visita a um cliente
 *   específico, e não existe pesquisa sem cliente.
 * - **`technician_id` e `service_type_id` nullable, com `nullOnDelete()`.**
 *   Mesmo critério de `work_orders.technician_id`/`service_type_id`: técnico
 *   ou tipo de serviço removido não apaga a resposta já registrada, só perde
 *   o vínculo para o corte por técnico/serviço no painel.
 * - **`expira_em` como `date`, não `datetime`.** É um dia, não um instante: o
 *   link para de funcionar a partir daquele dia, sem hora relevante, e nunca
 *   sofre conversão de fuso.
 * - **`enviada_em`, `respondida_em` e `contato_feito_em` como `datetime`.**
 *   Cada um é um instante (quando o disparo saiu, quando o cliente respondeu,
 *   quando alguém da empresa fez o contato pela nota baixa), gravado em UTC e
 *   convertido para o fuso do negócio só na exibição, via `BusinessDate`.
 * - **`nota` de 1 a 5, sem `CHECK` no banco.** O intervalo é validado na
 *   camada de aplicação (FormRequest da Task 16.5); o banco só garante que é
 *   um inteiro pequeno.
 * - **`pendencia_de_contato` com padrão `false`.** Nasce sem pendência; vira
 *   `true` quando a nota é baixa (regra da Task 16.5) e volta a `false`
 *   quando `contato_feito_em` é preenchido.
 * - **`company_id` sem valor padrão**, mesmo critério de
 *   `work_order_signatures`: tabela nasce depois da trait `BelongsToCompany`
 *   já aplicada a todo model de domínio.
 *
 * Tabela nova, sem dado existente: um único deploy, sem a divisão em etapas
 * exigida para tabela que já tem dado em produção.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('satisfaction_surveys', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);

            $table->foreignId('work_order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('technician_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_type_id')->nullable()->constrained()->nullOnDelete();

            $table->string('token', 64)->unique();

            // Instantes: gravados em UTC, convertidos para o fuso do negócio
            // só na exibição, via BusinessDate.
            $table->timestamp('enviada_em')->nullable();
            $table->timestamp('respondida_em')->nullable();

            $table->unsignedTinyInteger('nota')->nullable();
            $table->text('comentario')->nullable();

            // Dia sem hora relevante: nunca sofre conversão de fuso.
            $table->date('expira_em');

            $table->boolean('pendencia_de_contato')->default(false);
            $table->timestamp('contato_feito_em')->nullable();

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
        Schema::dropIfExists('satisfaction_surveys');
    }
};
