<?php

use App\Support\DominioMultiempresa;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Configuração fiscal do tenant e notas de serviço emitidas por ele.
 *
 * As credenciais usam `text` porque o cast `encrypted:array` aumenta o
 * conteúdo cifrado e pode ultrapassar o limite de um varchar. A configuração
 * e cada nota são dados de domínio, por isso recebem `company_id` obrigatório.
 *
 * A nota é documento fiscal e permanece no histórico. Cliente e nota
 * substituta usam `restrictOnDelete`; OS e título são apenas origens e podem
 * perder o vínculo sem apagar a nota.
 */
return new class extends Migration
{
    private const AMBIENTES = ['homologacao', 'producao'];

    private const SITUACOES = ['pendente', 'processando', 'emitida', 'cancelamento_pendente', 'cancelada', 'substituida', 'erro'];

    public function up(): void
    {
        $this->criarFiscalConfigs();
        $this->criarServiceInvoices();
        $this->criarProtecaoContraExclusaoDeNotas();
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS impedir_exclusao_service_invoices');
        Schema::dropIfExists('service_invoices');
        Schema::dropIfExists('fiscal_configs');
    }

    private function criarFiscalConfigs(): void
    {
        Schema::create('fiscal_configs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);
            $table->string('provedor');
            $table->enum('ambiente', self::AMBIENTES)->default('homologacao');
            $table->text('credenciais');
            $table->string('regime_tributario');
            $table->string('codigo_servico');
            $table->string('cnae')->nullable();
            $table->decimal('aliquota_iss', 5, 2);
            $table->boolean('iss_retido')->default(false);
            $table->string('natureza_operacao');
            $table->string('serie')->nullable();
            $table->unsignedInteger('proximo_numero')->nullable();
            $table->boolean('emissao_automatica')->default(false);
            $table->boolean('ativo')->default(false);
            $table->timestamps();

            $table->index(DominioMultiempresa::COLUNA_TENANT);
            $table->foreign(DominioMultiempresa::COLUNA_TENANT)
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    private function criarServiceInvoices(): void
    {
        Schema::create('service_invoices', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);
            $table->foreignId('fiscal_config_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('work_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('receivable_id')->nullable()->constrained()->nullOnDelete();
            $table->string('numero')->nullable();
            $table->string('codigo_verificacao')->nullable();
            $table->enum('situacao', self::SITUACOES)->default('pendente');
            $table->decimal('valor_servico', 10, 2);
            $table->decimal('valor_iss', 10, 2);
            $table->decimal('valor_liquido', 10, 2);
            $table->text('descricao_servico');
            $table->date('competencia');
            $table->timestamp('emitida_em')->nullable();
            $table->timestamp('cancelada_em')->nullable();
            $table->text('motivo_cancelamento')->nullable();
            $table->foreignId('substituida_por_id')->nullable()->constrained('service_invoices')->restrictOnDelete();
            $table->string('provedor_id')->nullable()->index();
            $table->string('pdf_path')->nullable();
            $table->string('xml_path')->nullable();
            $table->text('erro_mensagem')->nullable();
            $table->unsignedInteger('tentativas')->default(0);
            $table->timestamps();

            $table->index(DominioMultiempresa::COLUNA_TENANT, 'service_invoices_company_id_index');
            $table->foreign(DominioMultiempresa::COLUNA_TENANT)
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    private function criarProtecaoContraExclusaoDeNotas(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER impedir_exclusao_service_invoices
            BEFORE DELETE ON service_invoices
            FOR EACH ROW
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Nota fiscal de serviço não pode ser excluída.'
            SQL);
    }
};
