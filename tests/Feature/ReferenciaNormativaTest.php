<?php

namespace Tests\Feature;

use App\Models\ActiveIngredient;
use App\Models\Antidote;
use App\Models\ChemicalGroup;
use App\Models\Company;
use App\Models\Contract;
use App\Models\NormativeReference;
use App\Models\OrganRegistration;
use App\Models\Product;
use App\Models\ServiceOrder;
use App\Services\CertificateService;
use App\Services\Compliance\ConformidadeDaExecucaoService;
use App\Services\Compliance\ReferenciaNormativaService;
use App\Services\ContractService;
use App\Services\WorkOrderService;
use App\Support\BusinessDate;
use App\Support\TenantAtual;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use Database\Factories\AddressFactory;
use Database\Factories\CertificateFactory;
use Database\Factories\ClientFactory;
use Database\Factories\WorkOrderFactory;
use Database\Seeders\NormativeReferenceSeeder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * Task 24.7 do Plano 24: os cinco documentos emitidos leem a referência
 * normativa do cadastro, a do tenant vence a da plataforma, e a ausência total
 * de referência não impede a emissão.
 *
 * Documento emitido tem valor perante fiscalização, e é isso que sustenta as
 * duas garantias opostas deste arquivo:
 *
 * - o texto **precisa** sair, e sair do cadastro, para o documento não citar
 *   uma resolução revogada;
 * - a falta dele **não pode** derrubar a emissão. Documento que falha de gerar
 *   é pior que documento sem a linha da referência, e quem paga a diferença é
 *   o técnico em campo.
 *
 * A asserção é feita sobre o HTML renderizado das views de `resources/views/pdf`,
 * e não sobre o binário do PDF: é o mesmo template que o dompdf recebe, e
 * procurar texto dentro de um PDF comprimido testaria a biblioteca, não este
 * código.
 */
class ReferenciaNormativaTest extends TestCase
{
    use RefreshDatabase;

    private const HOJE = '2026-07-26';

    private const TEXTO_DA_PLATAFORMA = 'RDC nº 622, de 9 de março de 2022, da Anvisa';

    private const TEXTO_DO_TENANT = 'RDC nº 999, de 1 de janeiro de 2030, da Anvisa';

    private Company $empresaA;

    private Company $empresaB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixarRelogioEm(self::HOJE, '09:00');

        Company::query()->whereKey(1)->update([
            'name' => 'Dedetizadora A',
            'cnpj' => '11.111.111/0001-11',
            'phone' => '(11) 3333-1111',
        ]);

        $this->empresaA = Company::query()->findOrFail(1);
        $this->empresaB = Company::create(['name' => 'Dedetizadora B']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Resolução da referência
    // -----------------------------------------------------------------

    public function test_o_seeder_cria_a_referencia_padrao_da_plataforma_e_e_idempotente(): void
    {
        (new NormativeReferenceSeeder)->run();
        (new NormativeReferenceSeeder)->run();

        $daPlataforma = NormativeReference::query()->whereNull('company_id')->get();

        $this->assertCount(1, $daPlataforma, 'rodar o seeder duas vezes não pode duplicar a referência');
        $this->assertSame(self::TEXTO_DA_PLATAFORMA, $daPlataforma->first()->texto);
        $this->assertSame('2022-04-01', $daPlataforma->first()->vigente_desde->toDateString());
    }

    public function test_referencia_do_tenant_vence_a_da_plataforma(): void
    {
        (new NormativeReferenceSeeder)->run();

        $servico = app(ReferenciaNormativaService::class);

        $this->assertSame(self::TEXTO_DA_PLATAFORMA, $servico->obter($this->empresaA));
        $this->assertSame(self::TEXTO_DA_PLATAFORMA, $servico->obter($this->empresaB));

        NormativeReference::create([
            'company_id' => $this->empresaA->id,
            'chave' => NormativeReference::CHAVE_PRINCIPAL,
            'texto' => self::TEXTO_DO_TENANT,
            'ativo' => true,
        ]);

        $this->assertSame(self::TEXTO_DO_TENANT, $servico->obter($this->empresaA));
        $this->assertSame(
            self::TEXTO_DA_PLATAFORMA,
            $servico->obter($this->empresaB),
            'a referência da empresa A não pode vazar para a empresa B'
        );
    }

    public function test_referencia_inativa_do_tenant_nao_e_escolhida(): void
    {
        (new NormativeReferenceSeeder)->run();

        NormativeReference::create([
            'company_id' => $this->empresaA->id,
            'chave' => NormativeReference::CHAVE_PRINCIPAL,
            'texto' => self::TEXTO_DO_TENANT,
            'ativo' => false,
        ]);

        $this->assertSame(
            self::TEXTO_DA_PLATAFORMA,
            app(ReferenciaNormativaService::class)->obter($this->empresaA),
            'a referência guardada como histórico não pode ir para o documento'
        );
    }

    public function test_sem_referencia_nenhuma_o_servico_devolve_string_vazia_e_nao_lanca(): void
    {
        $this->assertSame('', app(ReferenciaNormativaService::class)->obter($this->empresaA));
        $this->assertSame('', app(ReferenciaNormativaService::class)->obter(null));
    }

    // -----------------------------------------------------------------
    // Os cinco documentos
    // -----------------------------------------------------------------

    public function test_os_cinco_documentos_leem_a_referencia_do_cadastro(): void
    {
        (new NormativeReferenceSeeder)->run();

        NormativeReference::create([
            'company_id' => $this->empresaA->id,
            'chave' => NormativeReference::CHAVE_PRINCIPAL,
            'texto' => self::TEXTO_DO_TENANT,
            'ativo' => true,
        ]);

        foreach ($this->documentosRenderizados() as $nome => $html) {
            $this->assertStringContainsString(
                self::TEXTO_DO_TENANT,
                $html,
                "o documento {$nome} precisa citar a referência cadastrada pelo tenant"
            );
            $this->assertStringNotContainsString(
                self::TEXTO_DA_PLATAFORMA,
                $html,
                "o documento {$nome} não pode citar a referência da plataforma quando o tenant tem a dele"
            );
        }
    }

    public function test_tenant_sem_referencia_propria_usa_a_da_plataforma_nos_cinco_documentos(): void
    {
        (new NormativeReferenceSeeder)->run();

        foreach ($this->documentosRenderizados() as $nome => $html) {
            $this->assertStringContainsString(
                self::TEXTO_DA_PLATAFORMA,
                $html,
                "o documento {$nome} precisa cair na referência padrão da plataforma"
            );
        }
    }

    /**
     * A garantia oposta: nenhuma referência cadastrada não pode impedir a
     * emissão de documento nenhum.
     */
    public function test_ausencia_total_de_referencia_nao_quebra_a_geracao_de_nenhum_documento(): void
    {
        $this->assertSame(0, NormativeReference::query()->count());

        foreach ($this->documentosRenderizados() as $nome => $html) {
            $this->assertNotSame('', trim($html), "o documento {$nome} precisa ser gerado mesmo sem referência");
            $this->assertStringNotContainsString(
                'Serviço prestado em conformidade com a .',
                $html,
                "o documento {$nome} não pode imprimir a frase da referência com o texto vazio"
            );
        }
    }

    // -----------------------------------------------------------------
    // Nada bloqueia
    // -----------------------------------------------------------------

    /**
     * O teste que impede um bloqueio de entrar por engano.
     *
     * O plano é explícito: nenhuma verificação desta entrega impede concluir
     * OS, assinar ou emitir documento. Travar a operação do cliente por
     * interpretação equivocada de norma é pior que o problema que se quer
     * resolver. Este cenário monta o pior caso possível — empresa com as
     * quatro licenças vencidas e produto aplicado com registro vencido — e
     * cobra que o fluxo inteiro conclua.
     */
    public function test_licenca_vencida_e_registro_vencido_nao_impedem_concluir_a_os_nem_emitir_documento(): void
    {
        $ontem = BusinessDate::hoje()->subDay()->toDateString();

        $this->empresaA->update([
            'registro_conselho_validade' => $ontem,
            'licenca_sanitaria_validade' => $ontem,
            'licenca_ambiental_validade' => $ontem,
            'licenca_funcionamento_validade' => $ontem,
        ]);

        [$os, $produto] = $this->naEmpresa($this->empresaA, function (): array {
            $registro = OrganRegistration::create([
                'record' => '3.0123.4567.001-8',
                'validade' => BusinessDate::hoje()->subYear()->toDateString(),
            ]);

            // `products` exige princípio ativo, grupo químico e antídoto
            // (colunas NOT NULL desde o cadastro original), então o fixture
            // cria os três; nenhum deles participa da regra sob teste.
            $produto = Product::create([
                'name' => 'Produto de teste',
                'organ_registration_id' => $registro->id,
                'active_ingredient_id' => ActiveIngredient::create(['name' => 'Fipronil'])->id,
                'chemical_group_id' => ChemicalGroup::create(['name' => 'Fenilpirazol'])->id,
                'antidote_id' => Antidote::create(['name' => 'Tratamento sintomático'])->id,
            ]);

            $cliente = ClientFactory::new()->create();
            $endereco = AddressFactory::new()->create(['client_id' => $cliente->id]);

            $os = WorkOrderFactory::new()->create([
                'client_id' => $cliente->id,
                'address_id' => $endereco->id,
                'status' => 'in_progress',
            ]);

            $os->products()->attach($produto->id, ['quantity' => 2, 'unit' => 'L']);

            return [$os, $produto];
        });

        // 1. Concluir a OS continua funcionando.
        $concluida = $this->naEmpresa(
            $this->empresaA,
            fn (): bool => app(WorkOrderService::class)->markAsCompleted($os->fresh())
        );

        $this->assertTrue($concluida, 'licença vencida não pode impedir a conclusão de uma OS já executada');
        $this->assertSame('completed', $os->fresh()->status);

        // 2. A conferência aponta o aviso, sem tê-lo impedido.
        $conferencia = $this->naEmpresa(
            $this->empresaA,
            fn (): array => app(ConformidadeDaExecucaoService::class)->conferir($os->fresh())
        );

        $this->assertNotEmpty(
            $conferencia['avisos'],
            'o produto com registro vencido precisa gerar aviso, mesmo tendo sido aplicado'
        );
        $this->assertStringContainsString('venceu em', $conferencia['avisos'][0]['detalhe']);
        $this->assertStringContainsString('não atesta conformidade', $conferencia['ressalva']);

        // 3. Emitir o documento continua funcionando.
        $html = $this->naEmpresa($this->empresaA, function () use ($os): string {
            $dados = app(WorkOrderService::class)->preparePdfData($os->fresh());

            return View::make('pdf.work-order', $dados)->render();
        });

        $this->assertStringContainsString($os->order_number, $html);
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * Os cinco documentos renderizados a partir dos mesmos dados que os
     * controllers passam ao dompdf.
     *
     * @return array<string, string>
     */
    private function documentosRenderizados(): array
    {
        return $this->naEmpresa($this->empresaA, function (): array {
            $cliente = ClientFactory::new()->create();
            $endereco = AddressFactory::new()->create(['client_id' => $cliente->id]);

            $os = WorkOrderFactory::new()->create([
                'client_id' => $cliente->id,
                'address_id' => $endereco->id,
                'status' => 'completed',
                'payment_status' => 'paid',
                'final_amount' => 500,
            ]);

            $certificado = CertificateFactory::new()->create([
                'client_id' => $cliente->id,
                'work_order_id' => $os->id,
            ]);

            $contrato = Contract::create([
                'address_id' => $endereco->id,
                'contract_number' => 'CT-0001',
                'start_date' => BusinessDate::hoje()->toDateString(),
                'end_date' => BusinessDate::hoje()->addYear()->toDateString(),
                'service_value' => 1200,
                'service_type' => 'periodico',
            ]);

            // `start_time`/`end_time` são colunas TIME NOT NULL sem default
            // no schema original: o fixture precisa informá-las.
            $ordemDeServico = ServiceOrder::create([
                'client_id' => $cliente->id,
                'order_number' => 'SO-0001',
                'order_date' => BusinessDate::hoje()->toDateString(),
                'start_time' => '08:00',
                'end_time' => '10:00',
                'status' => 'pending',
            ]);

            $dadosDoContrato = app(ContractService::class)->preparePdfData($contrato);
            $dadosDoContrato['address'] = $endereco;
            $dadosDoContrato['client'] = $cliente;

            return [
                'certificado' => View::make(
                    'pdf.certificate',
                    app(CertificateService::class)->preparePdfData($certificado)
                )->render(),
                'contrato' => View::make('pdf.contract', $dadosDoContrato)->render(),
                'ordem de serviço (OS)' => View::make(
                    'pdf.work-order',
                    app(WorkOrderService::class)->preparePdfData($os)
                )->render(),
                'ordem de serviço (agendamento)' => View::make(
                    'pdf.service-order',
                    ['serviceOrder' => $this->comRelacaoDeDispositivosVazia($ordemDeServico)]
                )->render(),
                'recibo' => View::make(
                    'pdf.receipt',
                    app(WorkOrderService::class)->prepareReceiptData($os)
                )->render(),
            ];
        });
    }

    /**
     * Contorna um defeito **pré-existente** de `pdf/service-order.blade.php`.
     *
     * A view faz `$serviceOrder->devices->count()`, mas `ServiceOrder` não tem
     * relação `devices` (só `client`, `technician`, `rooms`, `service` e
     * `products`). Em produção isso significa que
     * `GET /service-orders/{id}/pdf` estoura com "Call to a member function
     * count() on null" — defeito anterior a este plano, que a Task 24.2 não
     * introduziu e não tinha escopo para corrigir (mexer em documento emitido
     * exige conferência visual própria).
     *
     * O fixture injeta a relação vazia para que este teste possa afirmar o que
     * ele veio afirmar (a referência normativa no documento) em vez de morrer
     * num defeito alheio. O defeito está registrado no relatório do plano.
     */
    private function comRelacaoDeDispositivosVazia(ServiceOrder $ordemDeServico): ServiceOrder
    {
        return $ordemDeServico
            ->load(['client', 'technician', 'service', 'rooms', 'products'])
            ->setRelation('devices', new EloquentCollection);
    }

    private function fixarRelogioEm(string $diaEmBrasilia, string $hora): void
    {
        $emUtc = CarbonImmutable::parse($diaEmBrasilia.' '.$hora, BusinessDate::fuso())->setTimezone('UTC');

        Carbon::setTestNow(Carbon::parse($emUtc->format('Y-m-d H:i:s'), 'UTC'));
    }

    private function naEmpresa(Company $empresa, Closure $callback): mixed
    {
        return TenantAtual::comTenant((int) $empresa->id, $callback);
    }
}
