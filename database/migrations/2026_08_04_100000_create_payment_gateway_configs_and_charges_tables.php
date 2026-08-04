<?php

use App\Support\DominioMultiempresa;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cobrança do tenant ao cliente final (Plano 19, Task 19.1): a credencial de
 * gateway de cada tenant e a cobrança emitida a partir de uma parcela de
 * `receivable_installments` (Plano 18).
 *
 * Diferente de `subscriptions`/`invoices`/`gateway_events` (Plano 7), que são
 * a cobrança da plataforma ao tenant e ficam fora do escopo por empresa
 * porque o super admin precisa ver todos os tenants na mesma tela, as duas
 * tabelas desta migration são dado que o próprio tenant produz e só ele pode
 * ver: entram em `TABELAS_DE_DOMINIO`, os models levam `BelongsToCompany`, e
 * `company_id` segue o padrão já usado em `chart_of_accounts`/`receivables`
 * (Task 18.1) — `unsignedBigInteger` sem default, porque as duas nascem
 * depois da trait já aplicada a todo model de domínio.
 *
 * Decisões da modelagem:
 *
 * - **`payment_gateway_configs.credenciais` é `text`, cifrado com o cast
 *   `encrypted:array` do Laravel (AES-256-CBC, chave em `APP_KEY`).**
 *   Credencial de gateway de pagamento é acesso à conta bancária do cliente
 *   do tenant; gravar em texto puro significa que qualquer dump do banco vira
 *   esse acesso. `encrypted:array` cifra o array inteiro (client_id, secret,
 *   token, o que o gateway exigir) numa única coluna, sem esquema fixo de
 *   campo por gateway.
 * - **`webhook_token` também é cifrado, e por isso `text`, não `string`.**
 *   O cast `encrypted` de um valor de 64 caracteres já produz uma string de
 *   pouco mais de 300 caracteres (base64 de IV + ciphertext + HMAC), que
 *   estoura um `varchar(255)` e falha com o banco em modo estrito (
 *   `config/database.php: 'strict' => true`). A especificação da Task 19.1
 *   descreve o campo como "string" no sentido de dado textual, não como o
 *   tipo físico da coluna; `text` é o tipo que não trunca o valor cifrado.
 *   Ele é o que identifica de qual tenant é um webhook recebido sem confiar
 *   em nada do corpo da mensagem (Task 19.4 decide a estratégia de consulta;
 *   esta migration só garante que o valor cifrado cabe inteiro).
 * - **`payment_gateway_configs.company_id` é `unique`.** Um tenant configura
 *   um gateway ativo por vez, mesmo critério de `subscriptions.company_id`.
 * - **`charges.receivable_installment_id` usa `restrictOnDelete()`.** A
 *   cobrança é documento com valor perante fiscalização (CLAUDE.md), então a
 *   parcela que já tem cobrança emitida não pode ser apagada em silêncio;
 *   mesmo critério já aplicado a `client_id`/`supplier_id` nas tabelas de
 *   título (Task 18.1).
 * - **`charges.gateway_charge_id` leva índice próprio, além da unique
 *   composta.** A unique `[company_id, gateway, gateway_charge_id]` cobre a
 *   consulta quando os três já são conhecidos; o índice solto cobre consulta
 *   só por `gateway_charge_id` durante conciliação (Task 19.6), antes de o
 *   tenant ser resolvido.
 * - **Unique composta com `company_id`, como manda a skill
 *   `permissoes-e-multitenancy`.** Dois tenants no mesmo gateway podem
 *   coincidir no identificador da cobrança; sem o `company_id` na composição,
 *   o segundo tenant colidiria com o primeiro.
 * - **Uma cobrança ativa por parcela não é restrição de banco nesta task.**
 *   Uma parcela pode ter mais de uma cobrança ao longo do tempo (boleto
 *   vencido e reemitido); a garantia de que só uma fica ativa é validação da
 *   Task 19.3, no Service, não índice único parcial aqui.
 * - **`valor`/`valor_pago` são `decimal(10,2)`, nunca float, porque é
 *   dinheiro. `vencimento`/`pago_em` são `date`**, dia sem hora relevante,
 *   mesmo critério de `receivable_installments` (Task 18.1) e `invoices`
 *   (Task 7.1): a comparação de vencido é por dia no fuso do negócio, via
 *   `App\Support\BusinessDate`, nunca por instante.
 *
 * Tabelas novas, sem dado existente: um único deploy, sem a divisão em etapas
 * exigida para tabela que já tem dado em produção.
 */
return new class extends Migration
{
    /**
     * Ambientes aceitos do gateway configurado.
     *
     * @var array<int, string>
     */
    private const AMBIENTES = ['sandbox', 'producao'];

    /**
     * Tipos de cobrança aceitos.
     *
     * @var array<int, string>
     */
    private const TIPOS_DE_COBRANCA = ['boleto', 'pix'];

    /**
     * Situações aceitas da cobrança.
     *
     * @var array<int, string>
     */
    private const SITUACOES_DE_COBRANCA = ['pendente', 'emitida', 'paga', 'vencida', 'cancelada', 'erro'];

    public function up(): void
    {
        $this->criarPaymentGatewayConfigs();
        $this->criarCharges();
    }

    /**
     * Ordem inversa da criação: `charges` não depende de
     * `payment_gateway_configs` por chave estrangeira (cada uma referencia
     * `companies` e `receivable_installments`), mas a ordem de queda mantém o
     * espelho da ordem de criação.
     */
    public function down(): void
    {
        Schema::dropIfExists('charges');
        Schema::dropIfExists('payment_gateway_configs');
    }

    /**
     * Credencial de gateway de pagamento por tenant, cifrada.
     */
    private function criarPaymentGatewayConfigs(): void
    {
        Schema::create('payment_gateway_configs', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);

            $table->string('gateway');
            $table->enum('ambiente', self::AMBIENTES)->default('sandbox');

            // Cifrados com o cast encrypted:array/encrypted do model. Ver o
            // cabeçalho desta migration sobre o tipo `text` dos dois.
            $table->text('credenciais');
            $table->text('webhook_token');

            $table->boolean('ativo')->default(false);
            $table->timestamp('verificado_em')->nullable();

            $table->timestamps();

            // Um gateway ativo por tenant.
            $table->unique(DominioMultiempresa::COLUNA_TENANT);

            $table->foreign(DominioMultiempresa::COLUNA_TENANT)
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    /**
     * Cobrança (boleto ou PIX) emitida a partir de uma parcela a receber.
     */
    private function criarCharges(): void
    {
        Schema::create('charges', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);

            // Documento com valor perante fiscalização: parcela com cobrança
            // emitida não pode ser apagada em silêncio.
            $table->foreignId('receivable_installment_id')->constrained()->restrictOnDelete();

            $table->enum('tipo', self::TIPOS_DE_COBRANCA);
            $table->string('gateway');

            // Índice solto para conciliação (Task 19.6) só por este campo,
            // além da unique composta logo abaixo.
            $table->string('gateway_charge_id')->index();

            $table->decimal('valor', 10, 2);

            // Dia sem hora relevante: nunca sofre conversão de fuso.
            $table->date('vencimento');

            $table->enum('situacao', self::SITUACOES_DE_COBRANCA)->default('pendente');

            $table->string('link_pagamento')->nullable();
            $table->text('linha_digitavel')->nullable();
            $table->text('qr_code_pix')->nullable();

            $table->date('pago_em')->nullable();
            $table->decimal('valor_pago', 10, 2)->nullable();

            $table->text('erro_mensagem')->nullable();
            $table->unsignedInteger('tentativas')->default(0);

            // Vínculo com a cobrança que esta substitui, quando nasce de uma
            // reemissão (Task 19.3): `ChargeService::reemitir()` cancela a
            // anterior no gateway e localmente, cria uma cobrança nova e
            // grava o vínculo aqui, para o boleto vencido e o que o
            // substituiu ficarem navegáveis a partir de um só registro. Nula
            // na emissão comum. `nullOnDelete()` porque este projeto não
            // apaga `Charge`, só cancela; a coluna não pode travar uma
            // exclusão que hoje não existe.
            $table->foreignId('charge_anterior_id')->nullable()->constrained('charges')->nullOnDelete();

            $table->timestamps();

            // Dois tenants no mesmo gateway podem coincidir no identificador
            // da cobrança; sem company_id na composição, o segundo tenant
            // colidiria com o primeiro.
            $table->unique([DominioMultiempresa::COLUNA_TENANT, 'gateway', 'gateway_charge_id']);

            $table->foreign(DominioMultiempresa::COLUNA_TENANT)
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }
};
