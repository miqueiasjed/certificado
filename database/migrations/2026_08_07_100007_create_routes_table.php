<?php

use App\Support\DominioMultiempresa;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roteiro do dia por técnico e as paradas que o compõem (Plano 22).
 *
 * `routes` é o roteiro de um técnico em um dia: nasce planejado, muda de
 * situação conforme o técnico avança, e carrega a distância e a duração
 * totais devolvidas pelo motor de otimização (task futura do Plano 22).
 * `route_stops` é cada OS que entra nesse roteiro, na ordem em que vai ser
 * visitada.
 *
 * Tabelas novas, sem dado existente: um deploy só, sem a divisão em etapas
 * que uma tabela que já roda em produção exige.
 *
 * Decisões da modelagem:
 *
 * - **`routes.[company_id, technician_id, data]` é unique composta com as
 *   três colunas, de propósito, diferente do padrão mais comum de compor
 *   unique de domínio só com a chave estrangeira.** O caso mais frequente no
 *   schema (`floor_plans.[address_id, nome, versao]`,
 *   `device_positions.[floor_plan_id, device_id]`) tem uma FK que já
 *   pertence a uma empresa só, e por isso `company_id` de sobra na unique não
 *   muda nada. Aqui a especificação da Task 22.1 pede explicitamente as três
 *   colunas, e `company_id` entra em `DominioMultiempresa::UNIQUES_A_COMPOR`
 *   como se já tivesse nascido composta, porque a tabela nasce nesta mesma
 *   migration sem nunca ter existido só com `[technician_id, data]`.
 * - **`route_stops.[route_id, work_order_id]` não entra composta com
 *   `company_id`.** `route_id` já pertence a uma empresa só (a mesma
 *   empresa do roteiro), então compor com `company_id` não separaria tenant
 *   nenhum e só enfraqueceria a unique à toa. Mesmo raciocínio já registrado
 *   em `DominioMultiempresa::UNIQUES_GLOBAIS_MANTIDOS` para
 *   `device_positions.floor_plan_id_device_id_unique` (Plano 21).
 * - **`routes.technician_id` com `restrictOnDelete`.** Mesmo padrão das
 *   demais FKs de domínio recentes (`device_replacements`,
 *   `work_order_signatures.user_id` é exceção documentada à parte): técnico
 *   com roteiro não pode ser apagado por baixo, o histórico de roteirização
 *   vale mais que a conveniência de excluir o cadastro.
 * - **`route_stops.route_id` e `route_stops.work_order_id` com
 *   `cascadeOnDelete`.** Parada de roteiro não tem sentido nem consumidor sem
 *   o roteiro ou sem a OS que ela representa, mesmo padrão de
 *   `work_order_photos`/`work_order_adequations` em relação a `work_orders`.
 * - **`situacao` em `enum`**: `planejada` (padrão), `em_andamento`,
 *   `concluida`. Roteiro nasce planejado e muda de situação conforme o
 *   técnico avança pelo dia (task futura do Plano 22 cuida da transição).
 * - **`distancia_total_km`/`duracao_estimada_min` nullable.** Só existem
 *   depois que o motor de otimização roda; um roteiro recém-criado, com
 *   paradas ainda não ordenadas, não tem os dois.
 * - **`otimizada_em` nullable, `datetime`.** Instante da última otimização
 *   bem-sucedida, para saber se o roteiro está desatualizado depois de uma
 *   parada nova ou removida.
 * - **`route_stops.distancia_anterior_km`/`duracao_anterior_min`
 *   nullable.** Distância e duração do trecho vindo da parada anterior
 *   (ou da base, na primeira parada), calculadas junto da otimização;
 *   ficam nulas até o roteiro passar pelo motor de otimização.
 * - **`route_stops.chegada_estimada` é `time`, não `datetime`.** É o horário
 *   estimado de chegada dentro do dia do roteiro (a data já está em
 *   `routes.data`), não um instante com data própria.
 * - **`company_id` NOT NULL, sem valor padrão**, mesmo padrão das demais
 *   tabelas de domínio recentes: a trait `BelongsToCompany` preenche a
 *   coluna na criação, e um padrão temporário aqui só reintroduziria o risco
 *   que o Plano 4 existe para evitar.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->criarRoutes();
        $this->criarRouteStops();
    }

    public function down(): void
    {
        Schema::dropIfExists('route_stops');
        Schema::dropIfExists('routes');
    }

    private function criarRoutes(): void
    {
        Schema::create('routes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);
            $table->foreignId('technician_id')->constrained()->restrictOnDelete();
            $table->date('data');
            $table->enum('situacao', ['planejada', 'em_andamento', 'concluida'])->default('planejada');
            $table->decimal('distancia_total_km', 8, 2)->nullable();
            $table->unsignedInteger('duracao_estimada_min')->nullable();
            $table->dateTime('otimizada_em')->nullable();
            $table->timestamps();

            // Unique composta com company_id de propósito: ver o cabeçalho
            // desta migration para o raciocínio completo.
            $table->unique([DominioMultiempresa::COLUNA_TENANT, 'technician_id', 'data']);

            $table->index(DominioMultiempresa::COLUNA_TENANT, 'routes_company_id_index');
            $table->foreign(DominioMultiempresa::COLUNA_TENANT)
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    private function criarRouteStops(): void
    {
        Schema::create('route_stops', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);
            $table->foreignId('route_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('ordem');
            $table->decimal('distancia_anterior_km', 8, 2)->nullable();
            $table->unsignedInteger('duracao_anterior_min')->nullable();
            $table->time('chegada_estimada')->nullable();
            $table->timestamps();

            // Sem company_id na unique de propósito: route_id já pertence a
            // uma empresa só. Ver o cabeçalho desta migration.
            $table->unique(['route_id', 'work_order_id']);

            $table->index(DominioMultiempresa::COLUNA_TENANT, 'route_stops_company_id_index');
            $table->foreign(DominioMultiempresa::COLUNA_TENANT)
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }
};
