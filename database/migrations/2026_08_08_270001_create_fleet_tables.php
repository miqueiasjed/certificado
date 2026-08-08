<?php

use App\Support\DominioMultiempresa;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Frota: veículo, abastecimento, manutenção e documentação (Plano 27).
 *
 * Quatro tabelas novas, sem dado existente, mais duas colunas nullable em
 * `work_orders`. Por isso um deploy só: a regra do CLAUDE.md que exige dividir
 * em estrutura, backfill e restrição vale para migration que toca tabela com
 * dado em produção e acaba impondo restrição depois. Aqui não há restrição a
 * aplicar depois — `work_orders.vehicle_id` e `work_orders.km_deslocamento`
 * nascem nullable e ficam assim para sempre, porque nem toda empresa controla
 * frota e o módulo é opcional desde o Plano 6.
 *
 * Decisões da modelagem:
 *
 * - **`vehicles.[company_id, placa]` unique composta.** Placa é única dentro da
 *   empresa, nunca globalmente: duas empresas concorrentes podem, e vão,
 *   cadastrar frotas diferentes, e uma unique global em `placa` impediria o
 *   segundo tenant de cadastrar um veículo que ele nem sabe que existe no
 *   primeiro. Mesmo critério de `DominioMultiempresa::UNIQUES_A_COMPOR`.
 * - **`vehicles.technician_id` com `nullOnDelete`.** Mesmo critério de
 *   `stock_locations.technician_id` (Plano 17): técnico desligado não leva o
 *   veículo embora; o veículo continua existindo, só sem responsável.
 * - **`vehicles.stock_location_id` com `nullOnDelete`.** É o local de estoque
 *   `tipo = veiculo` do Plano 17, criado junto com o veículo (Task 27.4).
 *   Apagar o local não pode apagar o veículo nem o histórico de abastecimento.
 * - **`vehicles.km_atual` `unsignedInteger` com padrão 0.** É a quilometragem
 *   corrente do hodômetro, atualizada por abastecimento e por manutenção. Nasce
 *   em zero porque veículo recém-cadastrado ainda não teve leitura registrada,
 *   e zero é o valor que nunca recusa uma leitura futura por ser retroativa.
 * - **`vehicles.custo_km_padrao` `decimal(10, 4)` nullable.** Quatro casas pelo
 *   mesmo motivo de `stock_movements.custo_unitario`: custo por quilômetro cai
 *   em fração pequena e duas casas transformariam R$ 0,8734/km em R$ 0,87/km,
 *   erro que se multiplica por milhares de quilômetros. Nullable porque é
 *   opcional: existe para o rateio funcionar antes de haver histórico de
 *   abastecimento suficiente (menos de 3 intervalos de tanque cheio).
 * - **`refuelings.tanque_cheio` é o que torna o consumo confiável.** Consumo
 *   só é calculado entre dois abastecimentos completos; calcular sobre
 *   abastecimento parcial produz número errado que ninguém percebe. A coluna
 *   nasce com padrão `true` porque é o caso comum, e o cálculo (Task 27.2)
 *   ignora as linhas em que ela for falsa.
 * - **`refuelings.litros` `decimal(8, 3)` e `valor_litro` `decimal(8, 4)`.**
 *   Bomba de combustível registra três casas em litros e o preço por litro é
 *   publicado com três (às vezes quatro) casas; arredondar aqui desloca o
 *   custo por quilômetro na terceira casa, que é onde ele vive.
 * - **`refuelings.user_id` com `restrictOnDelete`.** Quem registrou o
 *   abastecimento faz parte do histórico financeiro; usuário com abastecimento
 *   lançado não é apagado por baixo.
 * - **`refuelings.payable_id` e `vehicle_maintenances.payable_id` nullable, com
 *   `nullOnDelete`.** O título a pagar é **oferecido, não criado
 *   automaticamente** (decisão registrada no INDEX do plano): quem controla
 *   frota só operacionalmente não quer o financeiro sujo de lançamento que não
 *   pediu. A coluna guarda o vínculo quando o usuário aceita a oferta, e apagar
 *   o título não apaga o abastecimento.
 * - **`vehicle_maintenances.proxima_em_data` e `proxima_em_km` são campos
 *   independentes.** Troca de óleo é 6 meses **ou** 10 mil quilômetros, o que
 *   vier primeiro, e os dois critérios não se derivam um do outro. Ambos
 *   nullable: manutenção corretiva não tem próxima prevista.
 * - **`vehicle_maintenances.data` e `km` nullable.** Manutenção `agendada`
 *   ainda não aconteceu: não tem o dia em que foi feita nem a quilometragem do
 *   momento. Os dois são preenchidos quando ela passa a `realizada`.
 * - **`vehicle_documents.validade` como `date`, NOT NULL.** É um dia, não um
 *   instante, e campo `date` nunca sofre conversão de fuso. Documento de
 *   veículo sem validade não tem o que alertar, então a coluna é obrigatória.
 *   A comparação de vencido é feita por dia no fuso do negócio, via
 *   `App\Support\BusinessDate` (Task 27.3), nunca com `now()` em UTC.
 * - **`data` do abastecimento e da manutenção como `date`.** Mesmo critério:
 *   são dias, e a hora do abastecimento não muda decisão nenhuma.
 * - **`enum` em vez de `string` nos campos de valor fechado** (`tipo`,
 *   `situacao`, `tipo_combustivel`), mesmo padrão de `devices.situacao` e das
 *   tabelas de estoque: o banco recusar um valor inventado vale mais que a
 *   facilidade de acrescentar um item depois.
 * - **`company_id` NOT NULL, sem valor padrão**, mesmo critério das demais
 *   tabelas de domínio criadas depois do Plano 4: a trait `BelongsToCompany`
 *   preenche a coluna na criação.
 */
return new class extends Migration
{
    /**
     * Combustíveis aceitos, iguais a `Refueling::TIPOS_DE_COMBUSTIVEL`.
     *
     * @var array<int, string>
     */
    private const TIPOS_DE_COMBUSTIVEL = ['gasolina', 'etanol', 'diesel', 'gnv'];

    /**
     * Executa a migration.
     */
    public function up(): void
    {
        $this->criarVehicles();
        $this->criarRefuelings();
        $this->criarVehicleMaintenances();
        $this->criarVehicleDocuments();
        $this->acrescentarFrotaEmWorkOrders();
    }

    /**
     * Reverte a migration.
     */
    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->dropForeign(['vehicle_id']);
            $table->dropColumn(['vehicle_id', 'km_deslocamento']);
        });

        Schema::dropIfExists('vehicle_documents');
        Schema::dropIfExists('vehicle_maintenances');
        Schema::dropIfExists('refuelings');
        Schema::dropIfExists('vehicles');
    }

    /**
     * Cadastro do veículo.
     */
    private function criarVehicles(): void
    {
        Schema::create('vehicles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);

            $table->string('placa', 10);
            $table->string('modelo');
            $table->string('marca');
            $table->unsignedSmallInteger('ano')->nullable();
            $table->enum('tipo', ['carro', 'moto', 'utilitario', 'caminhao'])->default('carro');

            $table->foreignId('technician_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('stock_location_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedInteger('km_atual')->default(0);
            $table->enum('situacao', ['ativo', 'manutencao', 'inativo'])->default('ativo');
            $table->decimal('custo_km_padrao', 10, 4)->nullable();

            $table->timestamps();

            // Placa é única por empresa, nunca global: ver o cabeçalho.
            $table->unique([DominioMultiempresa::COLUNA_TENANT, 'placa']);

            $table->index(DominioMultiempresa::COLUNA_TENANT, 'vehicles_company_id_index');
            $table->foreign(DominioMultiempresa::COLUNA_TENANT)
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    /**
     * Abastecimentos, base do custo por quilômetro.
     */
    private function criarRefuelings(): void
    {
        Schema::create('refuelings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);

            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();

            $table->date('data');
            $table->unsignedInteger('km');
            $table->decimal('litros', 8, 3);
            $table->decimal('valor_total', 10, 2);
            $table->decimal('valor_litro', 8, 4);
            $table->enum('tipo_combustivel', self::TIPOS_DE_COMBUSTIVEL)->default('gasolina');
            $table->string('posto')->nullable();
            $table->boolean('tanque_cheio')->default(true);

            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('payable_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            // O cálculo de consumo percorre os abastecimentos de um veículo em
            // ordem de quilometragem; sem este índice a série vira table scan
            // assim que a frota passa de um ano de histórico.
            $table->index(['vehicle_id', 'km'], 'refuelings_vehicle_id_km_index');

            $table->index(DominioMultiempresa::COLUNA_TENANT, 'refuelings_company_id_index');
            $table->foreign(DominioMultiempresa::COLUNA_TENANT)
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    /**
     * Manutenção preventiva e corretiva.
     */
    private function criarVehicleMaintenances(): void
    {
        Schema::create('vehicle_maintenances', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);

            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();

            $table->enum('tipo', ['preventiva', 'corretiva'])->default('preventiva');
            $table->string('descricao');
            $table->date('data')->nullable();
            $table->unsignedInteger('km')->nullable();

            // Independentes de propósito: o que vier primeiro dispara o alerta.
            $table->date('proxima_em_data')->nullable();
            $table->unsignedInteger('proxima_em_km')->nullable();

            $table->decimal('valor', 10, 2)->nullable();
            $table->string('oficina')->nullable();
            $table->enum('situacao', ['agendada', 'realizada', 'cancelada'])->default('agendada');

            $table->foreignId('payable_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            // A varredura de alertas (Task 27.3) percorre as manutenções com
            // próxima prevista, por veículo e por situação.
            $table->index(['vehicle_id', 'situacao'], 'vehicle_maintenances_vehicle_id_situacao_index');

            $table->index(DominioMultiempresa::COLUNA_TENANT, 'vehicle_maintenances_company_id_index');
            $table->foreign(DominioMultiempresa::COLUNA_TENANT)
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    /**
     * Documentação do veículo, com validade e alerta.
     */
    private function criarVehicleDocuments(): void
    {
        Schema::create('vehicle_documents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);

            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();

            $table->enum('tipo', ['licenciamento', 'seguro', 'outro'])->default('licenciamento');
            $table->string('numero')->nullable();
            $table->date('validade');
            $table->string('arquivo_path')->nullable();

            $table->timestamps();

            // A varredura de vencimento (Task 27.3) filtra por validade.
            $table->index(['vehicle_id', 'validade'], 'vehicle_documents_vehicle_id_validade_index');

            $table->index(DominioMultiempresa::COLUNA_TENANT, 'vehicle_documents_company_id_index');
            $table->foreign(DominioMultiempresa::COLUNA_TENANT)
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    /**
     * Vínculo da OS com o veículo e a quilometragem do deslocamento.
     *
     * As duas colunas nascem nullable e continuam nullable: OS sem veículo é
     * OS válida, e `km_deslocamento` só existe quando alguém informa a
     * quilometragem de verdade. Quando ela é nula, o rateio da Task 27.2 usa a
     * distância estimada do roteiro (Plano 22) e marca a origem do número como
     * estimada, nunca fingindo que foi medida.
     */
    private function acrescentarFrotaEmWorkOrders(): void
    {
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('km_deslocamento')->nullable();
        });
    }
};
