<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Assinatura de cada tenant, as faturas por período dela e todo evento
 * recebido do gateway de pagamento (Plano 7, Task 7.1).
 *
 * As três tabelas nascem na mesma migration porque uma não faz sentido sem as
 * outras: fatura pertence a uma assinatura, e evento de gateway é o que move o
 * estado das duas.
 *
 * Nenhuma das três é de domínio, e por isso nenhuma leva a trait
 * `BelongsToCompany` nem passa pelo escopo global por empresa, mesmo
 * `subscriptions` e `invoices` tendo `company_id`: são administradas pela
 * plataforma (o super admin cobra e concilia todos os tenants na mesma tela),
 * no mesmo critério já aplicado a `tenant_usages` e `company_modules`. A
 * classificação fica em `App\Support\DominioMultiempresa::TABELAS_FORA_DO_ESCOPO`
 * e `MODELS_FORA_DO_ESCOPO`, com o motivo de cada uma; sem ela o
 * `EscopoDeEmpresaTest` (Task 4.10) derruba a suíte. O tenant vê as próprias
 * faturas por filtro explícito de `company_id` (Task 7.8), não pelo escopo
 * global.
 *
 * - **`subscriptions`**: uma linha por tenant, por isso `company_id` é
 *   `unique`. `company_id` usa `cascadeOnDelete()` porque a assinatura é
 *   apuração de cobrança do tenant, não dado que o tenant produziu (mesmo
 *   critério de `tenant_usages`); `plan_id` fica com a ação padrão do banco
 *   (bloqueia apagar um plano do catálogo enquanto alguma assinatura aponta
 *   para ele), diferente do `plan_id` nullable de `companies`, porque aqui o
 *   plano contratado é o motivo da linha existir. `situacao` e
 *   `forma_pagamento` são string, sem default: a Task 7.2 em diante decide o
 *   valor inicial ao criar a assinatura, e um default aqui esconderia
 *   assinatura criada sem decisão explícita de estado ou de forma de
 *   pagamento. `gateway` tem default `pagbank` porque hoje é o único gateway
 *   integrado. `inicio_em` e `proxima_cobranca_em` são `date`: vencimento de
 *   cobrança é um dia do calendário, não um instante, e converter fuso em
 *   campo `date` é o que produz o erro de um dia (ver `App\Support\
 *   BusinessDate`). `cancelada_em` é `timestamp`: o instante em que o
 *   cancelamento foi registrado importa para auditoria, ao contrário do dia de
 *   vencimento.
 *
 * - **`invoices`**: uma por período de uma assinatura, garantido pela unique
 *   composta `[subscription_id, referencia]` — sem ela, reprocessar a geração
 *   de faturas do mês duplicaria a cobrança. `vencimento` e `pago_em` são
 *   `date`, nunca `datetime`: pagamento de fatura não tem hora relevante, e
 *   comparar por instante é o que faria uma fatura aparecer "vencida" às 21h
 *   de Brasília enquanto ainda é o dia do vencimento em UTC. `valor` é
 *   `decimal(10,2)`, nunca float, porque é dinheiro. `gateway_invoice_id` é
 *   indexado porque é a chave usada para casar o evento de webhook com a
 *   fatura local.
 *
 * - **`gateway_events`**: registro de todo evento recebido do gateway, antes
 *   de qualquer processamento, inclusive evento de tipo desconhecido —
 *   `payload` guarda o corpo inteiro, o que permite reprocessar depois de um
 *   bug sem depender do gateway reenviar. `evento_id` é **unique**: é essa
 *   restrição no banco que torna o webhook idempotente, porque dois envios
 *   simultâneos do mesmo evento colidem na inserção em vez de processar duas
 *   vezes. `tentativas` conta quantas vezes o processamento foi tentado, para
 *   a rotina de reprocessamento saber quando desistir. Índice composto
 *   `[tipo, created_at]` para a tela de diagnóstico, que lista eventos de um
 *   tipo em ordem cronológica.
 *
 * Tabelas novas, sem dado existente: as três nascem no mesmo deploy, sem a
 * dança de três etapas que o CLAUDE.md exige para tabela já povoada em
 * produção.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->id();

            // Unique: um tenant tem uma assinatura. cascadeOnDelete porque é
            // apuração de cobrança do tenant, não dado que ele produziu.
            $table->foreignId('company_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();

            // Ação padrão do banco (restrict): não se apaga um plano do
            // catálogo enquanto alguma assinatura aponta para ele.
            $table->foreignId('plan_id')->constrained();

            // ativa, em_atraso, suspensa, cancelada. String de propósito, sem
            // default: a Task 7.2 decide o valor inicial ao criar a linha.
            $table->string('situacao');

            // pix, boleto, cartao. Sem default, mesmo motivo de `situacao`.
            $table->string('forma_pagamento');

            $table->string('gateway')->default('pagbank');
            $table->string('gateway_subscription_id')->nullable()->index();

            // Dia do calendário, sem hora. Ver o cabeçalho.
            $table->date('inicio_em');
            $table->date('proxima_cobranca_em')->nullable();

            // Instante, gravado em UTC como todo campo de instante do projeto.
            $table->timestamp('cancelada_em')->nullable();
            $table->text('motivo_cancelamento')->nullable();

            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();

            // Formato YYYY-MM, calculado no fuso do negócio por quem grava.
            $table->string('referencia');

            $table->decimal('valor', 10, 2);

            // aberta, paga, vencida, cancelada. Sem default, mesmo motivo de
            // `subscriptions.situacao`.
            $table->string('situacao');

            // Dia do calendário, nunca datetime: comparação de vencimento é
            // por dia no fuso do negócio, não por instante. Ver o cabeçalho.
            $table->date('vencimento');
            $table->date('pago_em')->nullable();

            $table->string('gateway_invoice_id')->nullable()->index();
            $table->string('link_pagamento')->nullable();
            $table->text('linha_digitavel')->nullable();
            $table->text('qr_code_pix')->nullable();

            $table->timestamps();

            // Impede fatura duplicada do mesmo mês para a mesma assinatura.
            $table->unique(['subscription_id', 'referencia']);
        });

        Schema::create('gateway_events', function (Blueprint $table): void {
            $table->id();

            $table->string('gateway');

            // Chave da idempotência do webhook: sem este unique, dois envios
            // simultâneos do mesmo evento processam duas vezes.
            $table->string('evento_id')->unique();

            $table->string('tipo');
            $table->json('payload');

            $table->timestamp('processado_em')->nullable();
            $table->text('erro')->nullable();
            $table->unsignedInteger('tentativas')->default(0);

            $table->timestamps();

            $table->index(['tipo', 'created_at']);
        });
    }

    /**
     * Reverte a migration.
     *
     * `invoices` cai antes de `subscriptions`, porque tem chave estrangeira
     * apontando para ela. `gateway_events` não tem vínculo com nenhuma das
     * outras duas, então a posição dela na ordem é indiferente.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('gateway_events');
    }
};
