<?php

use App\Support\DominioMultiempresa;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Assinatura eletrônica de contrato (Plano 26, Task 26.1): a credencial do
 * provedor de assinatura por tenant, o pedido de assinatura de um contrato e
 * os signatários desse pedido, além das duas colunas de situação em
 * `contracts`.
 *
 * Isto é deliberadamente diferente de `work_order_signatures` (Plano 13), que
 * é a coleta por toque na tela, com o cliente presente, e comprova recebimento
 * do serviço. Contrato é assinado a distância e precisa de trilha de
 * auditoria: quem assinou, de qual IP, com qual navegador e em que instante.
 * É por isso que `signature_signers` guarda `ip`, `user_agent` e `assinado_em`
 * por signatário, e não uma imagem de rabisco.
 *
 * Decisões da modelagem:
 *
 * - **`signature_provider_configs` espelha `payment_gateway_configs`
 *   (Plano 19, Task 19.1), de propósito.** A conta com o provedor de
 *   assinatura é da empresa, não da plataforma: cada tenant tem a própria
 *   credencial, cifrada com o cast `encrypted:array` do Laravel (AES-256-CBC,
 *   chave em `APP_KEY`). Dump de banco não pode virar acesso à conta do
 *   cliente. `credenciais` e `webhook_token` são `text`, e não `string`, pelo
 *   mesmo motivo já registrado lá: o valor cifrado de um token de 64
 *   caracteres passa de 300 caracteres e estouraria um `varchar(255)` com o
 *   banco em modo estrito.
 * - **`webhook_token_hash` nasce junto, e não numa migration posterior.**
 *   `POST /webhooks/assinatura/{webhookToken}` (Task 26.3) chega sem sessão e
 *   sem tenant resolvido, e o único jeito de descobrir de qual empresa é o
 *   webhook é o token da própria URL. Como `webhook_token` é cifrado com IV
 *   aleatório, `WHERE webhook_token = ?` nunca casa; o HMAC-SHA256
 *   determinístico do token em claro é o índice de busca. No Plano 19 isso só
 *   foi percebido na Task 19.4 e custou uma segunda migration; aqui a tabela
 *   é nova e o mesmo problema já é conhecido, então a coluna entra no mesmo
 *   deploy. Ela é nullable porque o `booted()` do model a preenche a partir do
 *   `webhook_token`, e uma configuração ainda sem token não tem hash.
 * - **`signature_requests.provedor_documento_id` é nullable até o envio.** O
 *   pedido nasce em `rascunho`, antes de existir documento no provedor; o
 *   identificador só chega na resposta do envio (Task 26.3). Nullable também é
 *   o que faz a unique composta não bloquear dois rascunhos.
 * - **Unique composta `[company_id, provedor, provedor_documento_id]`**, como
 *   manda a skill `permissoes-e-multitenancy`. Dois tenants no mesmo provedor
 *   podem coincidir no identificador do documento; sem `company_id` na
 *   composição, o segundo tenant colidiria com o primeiro. Mesmo critério de
 *   `charges.[company_id, gateway, gateway_charge_id]`.
 * - **`arquivo_original_path` e `arquivo_assinado_path` são colunas
 *   separadas.** O original prova o que foi enviado ao cliente; o assinado é o
 *   documento que vale perante fiscalização. Sobrescrever um com o outro
 *   apagaria a prova de que o texto assinado é o texto enviado.
 *   `arquivo_assinado_path` é nullable porque só existe depois que todos
 *   assinaram, e é preenchido baixando o arquivo do provedor no ato: o link
 *   dele expira e o documento precisa continuar acessível anos depois.
 * - **`expira_em` é `date`; `enviado_em` e `concluido_em` são `timestamp`.**
 *   O prazo de assinatura é um dia do calendário no fuso do negócio (nunca
 *   sofre conversão de fuso); envio e conclusão são instantes. Mesmo critério
 *   já aplicado em `charges.vencimento` x `charges.created_at`.
 * - **`signature_signers.company_id` existe apesar de a tabela ser filha.**
 *   O signatário é consultado direto na sincronização e no webhook, quando o
 *   pedido ainda não foi carregado, então o escopo do pai não é atravessado e
 *   o escopo global é a única defesa. Mesmo critério já aplicado a
 *   `work_order_photos` e `commission_items`.
 * - **`signature_signers.ip` é `string(45)`.** Cabe um IPv6 completo
 *   (39 caracteres) e o formato mapeado de IPv4 em IPv6, com folga.
 * - **`criado_por` usa `restrictOnDelete()`.** Contrato assinado é documento
 *   com valor perante fiscalização (CLAUDE.md): a trilha de auditoria não pode
 *   perder quem disparou o pedido porque o usuário foi apagado depois.
 * - **`contracts.situacao_assinatura` tem padrão `nao_enviado`.** A coluna é
 *   nova em tabela com contrato real de cliente em produção, mas o padrão já
 *   deixa toda linha existente no estado correto — nenhum contrato de hoje foi
 *   enviado para assinatura, porque o recurso não existia. Não há backfill a
 *   conferir nem restrição a aplicar depois, então esta é a exceção legítima
 *   à regra das três etapas: um único deploy. `assinado_em` é nullable, sem
 *   padrão.
 *
 * As três tabelas são novas, sem dado existente: um único deploy, sem a
 * divisão em etapas exigida para tabela que já tem dado em produção.
 */
return new class extends Migration
{
    /**
     * Ambientes aceitos do provedor configurado. Mesma lista de
     * `payment_gateway_configs.ambiente`.
     *
     * @var array<int, string>
     */
    private const AMBIENTES = ['sandbox', 'producao'];

    /**
     * Situações aceitas do pedido de assinatura.
     *
     * @var array<int, string>
     */
    private const SITUACOES_DO_PEDIDO = [
        'rascunho',
        'enviado',
        'visualizado',
        'assinado',
        'recusado',
        'expirado',
        'cancelado',
    ];

    /**
     * Papéis aceitos do signatário.
     *
     * @var array<int, string>
     */
    private const PAPEIS_DE_SIGNATARIO = ['contratante', 'contratada', 'testemunha'];

    /**
     * Situações aceitas do signatário.
     *
     * @var array<int, string>
     */
    private const SITUACOES_DE_SIGNATARIO = ['pendente', 'visualizou', 'assinou', 'recusou'];

    /**
     * Situações aceitas da assinatura de um contrato.
     *
     * @var array<int, string>
     */
    private const SITUACOES_DE_ASSINATURA = ['nao_enviado', 'em_assinatura', 'assinado', 'recusado'];

    public function up(): void
    {
        $this->criarSignatureProviderConfigs();
        $this->criarSignatureRequests();
        $this->criarSignatureSigners();
        $this->acrescentarSituacaoEmContracts();
    }

    /**
     * Ordem inversa da criação: `signature_signers` referencia
     * `signature_requests`, então a filha cai primeiro.
     */
    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table): void {
            $table->dropColumn(['situacao_assinatura', 'assinado_em']);
        });

        Schema::dropIfExists('signature_signers');
        Schema::dropIfExists('signature_requests');
        Schema::dropIfExists('signature_provider_configs');
    }

    /**
     * Credencial do provedor de assinatura por tenant, cifrada.
     */
    private function criarSignatureProviderConfigs(): void
    {
        Schema::create('signature_provider_configs', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);

            $table->string('provedor');
            $table->enum('ambiente', self::AMBIENTES)->default('sandbox');

            // Cifrados com o cast encrypted:array/encrypted do model. Ver o
            // cabeçalho desta migration sobre o tipo `text` dos dois.
            $table->text('credenciais');
            $table->text('webhook_token');

            // Índice de busca do token cifrado, para o webhook público
            // descobrir o tenant. Ver o cabeçalho desta migration.
            $table->string('webhook_token_hash')->nullable()->unique();

            $table->boolean('ativo')->default(false);
            $table->timestamp('verificado_em')->nullable();

            $table->timestamps();

            // Um provedor de assinatura por tenant, mesmo critério de
            // `payment_gateway_configs.company_id`.
            $table->unique(DominioMultiempresa::COLUNA_TENANT);

            $table->foreign(DominioMultiempresa::COLUNA_TENANT)
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    /**
     * Pedido de assinatura de um contrato, com a situação do ciclo inteiro.
     */
    private function criarSignatureRequests(): void
    {
        Schema::create('signature_requests', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);

            // Contrato é documento com valor perante fiscalização: contrato
            // com pedido de assinatura não pode ser apagado em silêncio.
            $table->foreignId('contract_id')->constrained()->restrictOnDelete();

            $table->string('provedor');

            // Nulo enquanto o pedido é rascunho: o identificador do documento
            // só existe depois da resposta do provedor (Task 26.3). Índice
            // solto para a sincronização periódica e o webhook, que consultam
            // só por ele antes de o tenant estar resolvido.
            $table->string('provedor_documento_id')->nullable()->index();

            $table->enum('situacao', self::SITUACOES_DO_PEDIDO)->default('rascunho');

            $table->timestamp('enviado_em')->nullable();

            // Dia sem hora relevante: o prazo do provedor é contado por dia no
            // fuso do negócio, nunca por instante.
            $table->date('expira_em')->nullable();

            $table->timestamp('concluido_em')->nullable();

            // Dois arquivos, nunca um só. Ver o cabeçalho desta migration.
            $table->string('arquivo_original_path')->nullable();
            $table->string('arquivo_assinado_path')->nullable();

            $table->text('motivo_recusa')->nullable();

            $table->unsignedBigInteger('criado_por')->nullable();

            $table->timestamps();

            // Dois tenants no mesmo provedor podem coincidir no identificador
            // do documento; sem company_id na composição, o segundo tenant
            // colidiria com o primeiro.
            $table->unique([DominioMultiempresa::COLUNA_TENANT, 'provedor', 'provedor_documento_id']);

            $table->foreign(DominioMultiempresa::COLUNA_TENANT)
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->restrictOnUpdate();

            // A trilha de auditoria não pode perder quem disparou o pedido.
            $table->foreign('criado_por')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    /**
     * Signatários de um pedido, com a trilha de auditoria de cada um.
     */
    private function criarSignatureSigners(): void
    {
        Schema::create('signature_signers', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);

            // Signatário só existe dentro de um pedido: apagar o pedido leva
            // os signatários junto. Diferente das FKs de documento acima, aqui
            // `cascadeOnDelete()` não perde prova nenhuma — o pedido é a
            // unidade de prova, e um signatário órfão não comprova nada.
            $table->foreignId('signature_request_id')->constrained()->cascadeOnDelete();

            $table->string('nome');
            $table->string('email');
            $table->string('documento')->nullable();

            $table->enum('papel', self::PAPEIS_DE_SIGNATARIO);

            // Alguns contratos exigem que a contratada assine antes de o
            // documento chegar ao cliente.
            $table->unsignedInteger('ordem')->default(1);

            $table->enum('situacao', self::SITUACOES_DE_SIGNATARIO)->default('pendente');

            // Trilha de auditoria: é o que dá valor jurídico à assinatura a
            // distância e é a diferença entre isto e a coleta em tela do
            // Plano 13.
            $table->timestamp('assinado_em')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamps();

            $table->foreign(DominioMultiempresa::COLUNA_TENANT)
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    /**
     * Situação de assinatura do contrato. Ver o cabeçalho desta migration
     * sobre por que o padrão já deixa as linhas existentes corretas.
     */
    private function acrescentarSituacaoEmContracts(): void
    {
        Schema::table('contracts', function (Blueprint $table): void {
            $table->enum('situacao_assinatura', self::SITUACOES_DE_ASSINATURA)
                ->default('nao_enviado')
                ->after('em_negociacao_em');

            $table->timestamp('assinado_em')->nullable()->after('situacao_assinatura');
        });
    }
};
