<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Certificate;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Company;
use App\Models\Contract;
use App\Models\PaymentDetail;
use App\Models\WorkOrder;
use App\Models\WorkOrderAdequation;
use App\Services\PortalService;
use App\Support\CamposVisiveisAoCliente;
use App\Support\TenantAtual;
use Carbon\Carbon;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Task 15.3 do Plano 15: `PortalService` com escopo duplo (empresa + cliente).
 *
 * É a task central de segurança do Plano 15 (o primeiro acesso externo do
 * sistema): um vazamento aqui significa o cliente de uma empresa vendo dado
 * de outro cliente da mesma empresa, ou de outra empresa inteira.
 *
 * O critério mais importante é "desligar o escopo global não faz o Service
 * vazar dado". Os dois testes da última seção seguram exatamente isso,
 * desligando `TenantAtual` por completo (`limpar()` + `semEscopo()`) e
 * confirmando que o `PortalService` continua isolando por conta própria, já
 * que o filtro por `client_id`/`company_id` está escrito na consulta, e não
 * delegado ao scope automático do Eloquent.
 */
class PortalServiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $empresaUm;

    private Client $clienteA;

    private ClientUser $clientUserA;

    // Entidades do cliente A.
    private WorkOrder $visitaA;

    private Certificate $certificadoA;

    private Contract $contratoA;

    private WorkOrderAdequation $adequacaoAbertaA;

    private WorkOrderAdequation $adequacaoConcluidaA;

    private PaymentDetail $faturaA;

    // Entidades do cliente B, mesma empresa do cliente A.
    private WorkOrder $visitaB;

    private Certificate $certificadoB;

    private Contract $contratoB;

    private WorkOrderAdequation $adequacaoAbertaB;

    private PaymentDetail $faturaB;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();

        // "Hoje" fixo, para proximasVisitas() e o status calculado do
        // certificado não dependerem do relógio real da máquina que roda o teste.
        Carbon::setTestNow('2026-07-28 10:00:00');

        // A empresa 1 vem da migration de fundação do tenant.
        $this->empresaUm = Company::query()->firstOrFail();

        TenantAtual::comTenant($this->empresaUm->id, function (): void {
            $this->clienteA = ClientFactory::new()->create(['name' => 'Cliente A']);
            $clienteB = ClientFactory::new()->create(['name' => 'Cliente B']);

            [
                $this->visitaA,
                $this->certificadoA,
                $this->contratoA,
                $this->adequacaoAbertaA,
                $this->adequacaoConcluidaA,
                $this->faturaA,
            ] = $this->criarCenarioDoCliente($this->clienteA);

            [
                $this->visitaB,
                $this->certificadoB,
                $this->contratoB,
                $this->adequacaoAbertaB,
                ,
                $this->faturaB,
            ] = $this->criarCenarioDoCliente($clienteB);

            $this->clientUserA = ClientUser::create([
                'client_id' => $this->clienteA->id,
                'nome' => 'Usuário A',
                'email' => 'usuario-a@exemplo.test',
                'ativo' => true,
            ]);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        TenantAtual::limpar();

        parent::tearDown();
    }

    /**
     * Monta visita, certificado, contrato, duas adequações (uma aberta e uma
     * concluída) e uma fatura, todos vinculados ao cliente informado. Roda
     * dentro do tenant já resolvido pelo chamador.
     *
     * Sem certificado "em rascunho" aqui de propósito: o enum atual de
     * `certificates.status` não aceita mais `draft` (ver o achado documentado
     * no cabeçalho de `PortalService`), então não há como persistir essa
     * linha. A exclusão de rascunho é testada inspecionando a consulta
     * montada, não com um registro real. Ver
     * `test_consulta_de_certificados_exclui_rascunho_por_construcao`.
     *
     * @return array{0: WorkOrder, 1: Certificate, 2: Contract, 3: WorkOrderAdequation, 4: WorkOrderAdequation, 5: PaymentDetail}
     */
    private function criarCenarioDoCliente(Client $cliente): array
    {
        $endereco = Address::factory()->create(['client_id' => $cliente->id]);

        $visita = WorkOrder::factory()->create([
            'client_id' => $cliente->id,
            'address_id' => $endereco->id,
            'scheduled_date' => '2026-08-01',
            'status' => 'scheduled',
        ]);

        $certificado = Certificate::factory()->create([
            'client_id' => $cliente->id,
            'address_id' => $endereco->id,
            'status' => 'active',
        ]);

        $contrato = Contract::create([
            'address_id' => $endereco->id,
            'contract_number' => 'CONT-'.$cliente->id,
            'start_date' => '2026-01-01',
            'end_date' => '2027-01-01',
            'service_value' => 1200.50,
            'service_type' => 'periodico',
            'visit_frequency_valor' => 3,
            'visit_frequency_unidade' => 'meses',
            'pest_target' => 'Baratas',
            'payment_method' => 'PIX',
        ]);

        $adequacaoAberta = WorkOrderAdequation::create([
            'work_order_id' => $visita->id,
            'type' => 'estrutural',
            'description' => 'Vedar frestas na parede',
            'priority' => 'alta',
            'status' => 'pendente',
            'deadline' => '2026-09-01',
        ]);

        $adequacaoConcluida = WorkOrderAdequation::create([
            'work_order_id' => $visita->id,
            'type' => 'estrutural',
            'description' => 'Adequação já resolvida',
            'priority' => 'baixa',
            'status' => 'concluido',
            'deadline' => '2026-07-01',
        ]);

        $fatura = PaymentDetail::factory()->create([
            'work_order_id' => $visita->id,
            'payment_due_date' => '2026-08-10',
        ]);

        return [$visita, $certificado, $contrato, $adequacaoAberta, $adequacaoConcluida, $fatura];
    }

    private function portalDe(ClientUser $clientUser): PortalService
    {
        return new PortalService($clientUser);
    }

    // -----------------------------------------------------------------
    // Escopo duplo: cliente da mesma empresa nunca vê o dado do outro
    // -----------------------------------------------------------------

    public function test_visitas_do_cliente_a_nao_incluem_a_visita_do_cliente_b_da_mesma_empresa(): void
    {
        $visitas = TenantAtual::comTenant(
            $this->empresaUm->id,
            fn (): array => $this->portalDe($this->clientUserA)->visitas()
        );

        $ids = array_column($visitas, 'id');

        $this->assertContains($this->visitaA->id, $ids);
        $this->assertNotContains($this->visitaB->id, $ids);
    }

    public function test_certificados_do_cliente_a_nao_incluem_o_certificado_do_cliente_b(): void
    {
        $certificados = TenantAtual::comTenant(
            $this->empresaUm->id,
            fn (): array => $this->portalDe($this->clientUserA)->certificados()
        );

        $ids = array_column($certificados, 'id');

        $this->assertContains($this->certificadoA->id, $ids);
        $this->assertNotContains($this->certificadoB->id, $ids);
    }

    public function test_contratos_do_cliente_a_nao_incluem_o_contrato_do_cliente_b(): void
    {
        $contratos = TenantAtual::comTenant(
            $this->empresaUm->id,
            fn (): array => $this->portalDe($this->clientUserA)->contratos()
        );

        $ids = array_column($contratos, 'id');

        $this->assertContains($this->contratoA->id, $ids);
        $this->assertNotContains($this->contratoB->id, $ids);
    }

    public function test_adequacoes_em_aberto_do_cliente_a_nao_incluem_as_do_cliente_b_nem_as_ja_concluidas(): void
    {
        $adequacoes = TenantAtual::comTenant(
            $this->empresaUm->id,
            fn (): array => $this->portalDe($this->clientUserA)->adequacoesEmAberto()
        );

        $ids = array_column($adequacoes, 'id');

        $this->assertContains($this->adequacaoAbertaA->id, $ids);
        $this->assertNotContains($this->adequacaoConcluidaA->id, $ids, 'adequação já concluída não é "em aberto"');
        $this->assertNotContains($this->adequacaoAbertaB->id, $ids);
    }

    public function test_faturas_do_cliente_a_nao_incluem_a_fatura_do_cliente_b(): void
    {
        $faturas = TenantAtual::comTenant(
            $this->empresaUm->id,
            fn (): array => $this->portalDe($this->clientUserA)->faturas()
        );

        $ids = array_column($faturas, 'id');

        $this->assertContains($this->faturaA->id, $ids);
        $this->assertNotContains($this->faturaB->id, $ids);
    }

    public function test_proximas_visitas_traz_a_visita_futura_do_proprio_cliente_e_nao_a_do_outro(): void
    {
        $proximas = TenantAtual::comTenant(
            $this->empresaUm->id,
            fn (): array => $this->portalDe($this->clientUserA)->proximasVisitas()
        );

        $ids = array_column($proximas, 'id');

        $this->assertContains($this->visitaA->id, $ids);
        $this->assertNotContains($this->visitaB->id, $ids);
    }

    // -----------------------------------------------------------------
    // Acessar id de outro cliente (ou inexistente) devolve 404, nunca 403
    // -----------------------------------------------------------------

    public function test_visita_de_outro_cliente_lanca_model_not_found(): void
    {
        $this->expectException(ModelNotFoundException::class);

        TenantAtual::comTenant(
            $this->empresaUm->id,
            fn () => $this->portalDe($this->clientUserA)->visita($this->visitaB->id)
        );
    }

    public function test_visita_inexistente_lanca_model_not_found(): void
    {
        $this->expectException(ModelNotFoundException::class);

        TenantAtual::comTenant(
            $this->empresaUm->id,
            fn () => $this->portalDe($this->clientUserA)->visita(999999)
        );
    }

    public function test_documento_certificado_de_outro_cliente_lanca_model_not_found(): void
    {
        $this->expectException(ModelNotFoundException::class);

        TenantAtual::comTenant(
            $this->empresaUm->id,
            fn () => $this->portalDe($this->clientUserA)->documento('certificado', $this->certificadoB->id)
        );
    }

    public function test_documento_contrato_de_outro_cliente_lanca_model_not_found(): void
    {
        $this->expectException(ModelNotFoundException::class);

        TenantAtual::comTenant(
            $this->empresaUm->id,
            fn () => $this->portalDe($this->clientUserA)->documento('contrato', $this->contratoB->id)
        );
    }

    public function test_documento_fatura_de_outro_cliente_lanca_model_not_found(): void
    {
        $this->expectException(ModelNotFoundException::class);

        TenantAtual::comTenant(
            $this->empresaUm->id,
            fn () => $this->portalDe($this->clientUserA)->documento('fatura', $this->faturaB->id)
        );
    }

    public function test_documento_com_tipo_desconhecido_lanca_model_not_found(): void
    {
        $this->expectException(ModelNotFoundException::class);

        TenantAtual::comTenant(
            $this->empresaUm->id,
            fn () => $this->portalDe($this->clientUserA)->documento('tipo-que-nao-existe', $this->visitaA->id)
        );
    }

    // -----------------------------------------------------------------
    // Documento em rascunho não aparece, nem em listagem nem por id direto
    //
    // Achado ao implementar: o enum de `certificates.status` foi consolidado
    // em `active/expired/cancelled` pela migration
    // `2025_08_26_200507_update_certificates_status_values`. `draft` não é
    // mais um valor válido no banco (inserir um certificado com esse status
    // falha com "Data truncated for column 'status'"). Ver o cabeçalho de
    // `PortalService` para a explicação completa. Como não é possível
    // persistir essa linha hoje, o teste abaixo confirma que a exclusão
    // continua escrita na consulta (defesa pronta para se o enum reintroduzir
    // o valor), em vez de comparar contra um registro real de rascunho.
    // -----------------------------------------------------------------

    public function test_consulta_de_certificados_exclui_rascunho_por_construcao(): void
    {
        TenantAtual::comTenant($this->empresaUm->id, function (): void {
            $portal = $this->portalDe($this->clientUserA);

            $metodo = new \ReflectionMethod($portal, 'consultaDeCertificados');
            $metodo->setAccessible(true);

            /** @var \Illuminate\Database\Eloquent\Builder $consulta */
            $consulta = $metodo->invoke($portal);

            $this->assertStringContainsString('status', $consulta->toSql());
            $this->assertContains(
                'draft',
                $consulta->getBindings(),
                'a consulta de certificados precisa excluir o status "draft" por construção, mesmo que o enum atual do banco não aceite mais esse valor'
            );
        });
    }

    // -----------------------------------------------------------------
    // Nenhum campo de custo, margem ou comissão sai no retorno
    // -----------------------------------------------------------------

    /**
     * @return array<int, array<int, string>>
     */
    public static function camposProibidosProvider(): array
    {
        return [
            'custo total' => ['total_cost'],
            'desconto' => ['discount_amount'],
            'valor final bruto' => ['final_amount'],
            'materiais usados' => ['materials_used'],
            'observação interna' => ['observations'],
            'nota de conclusão' => ['completion_notes'],
            'nota interna de pagamento' => ['payment_notes'],
            'dados bancários do contrato' => ['payment_details'],
            'margem (inglês)' => ['margin'],
            'margem (português)' => ['margem'],
            'comissão (inglês)' => ['commission'],
            'comissão (português)' => ['comissao'],
        ];
    }

    #[DataProvider('camposProibidosProvider')]
    public function test_nenhum_retorno_expoe_campos_de_custo_margem_ou_comissao(string $campoProibido): void
    {
        TenantAtual::comTenant($this->empresaUm->id, function () use ($campoProibido): void {
            $portal = $this->portalDe($this->clientUserA);

            $registros = array_merge(
                $portal->visitas(),
                $portal->certificados(),
                $portal->contratos(),
                $portal->adequacoesEmAberto(),
                $portal->faturas(),
            );

            $this->assertNotEmpty($registros, 'o cenário de teste precisa ter pelo menos um registro de cada entidade');

            foreach ($registros as $registro) {
                $this->assertArrayNotHasKey($campoProibido, $registro, "o campo '{$campoProibido}' não deveria sair no retorno do portal");
            }
        });
    }

    // -----------------------------------------------------------------
    // Campo novo no model não aparece sem entrar na lista de permissão
    // -----------------------------------------------------------------

    public function test_atributo_dinamico_novo_no_model_nao_aparece_no_retorno_mapeado(): void
    {
        TenantAtual::comTenant($this->empresaUm->id, function (): void {
            $visita = $this->visitaA->fresh();
            $visita->setAttribute('novo_campo_secreto', 'valor-que-nao-poderia-vazar');

            $resultado = CamposVisiveisAoCliente::visita($visita);

            $this->assertArrayNotHasKey('novo_campo_secreto', $resultado);
            $this->assertSame(CamposVisiveisAoCliente::VISITA, array_keys($resultado));
        });
    }

    public function test_retorno_de_cada_entidade_contem_exatamente_as_chaves_declaradas_na_lista_de_permissao(): void
    {
        TenantAtual::comTenant($this->empresaUm->id, function (): void {
            $this->assertSame(
                CamposVisiveisAoCliente::VISITA,
                array_keys(CamposVisiveisAoCliente::visita($this->visitaA->fresh()))
            );
            $this->assertSame(
                CamposVisiveisAoCliente::CERTIFICADO,
                array_keys(CamposVisiveisAoCliente::certificado($this->certificadoA->fresh()))
            );
            $this->assertSame(
                CamposVisiveisAoCliente::CONTRATO,
                array_keys(CamposVisiveisAoCliente::contrato($this->contratoA->fresh()))
            );
            $this->assertSame(
                CamposVisiveisAoCliente::ADEQUACAO,
                array_keys(CamposVisiveisAoCliente::adequacao($this->adequacaoAbertaA->fresh()))
            );
            $this->assertSame(
                CamposVisiveisAoCliente::FATURA,
                array_keys(CamposVisiveisAoCliente::fatura($this->faturaA->fresh()))
            );
        });
    }

    // -----------------------------------------------------------------
    // Desligar o escopo global não faz o Service vazar dado
    // -----------------------------------------------------------------

    /**
     * O teste mais importante da task. Cria uma segunda empresa com a própria
     * visita, desliga completamente o tenant resolvido (`limpar()`) e o
     * escopo global de leitura por empresa (`semEscopo()`), e confirma que o
     * `PortalService` do cliente A continua devolvendo só a própria visita:
     * nem a do cliente B (mesma empresa), nem a da empresa 2. Isso só é
     * possível porque o filtro por `client_id`/`company_id` está escrito
     * explicitamente na consulta do Service, e não depende do scope
     * automático do Eloquent para existir.
     */
    public function test_com_escopo_global_desligado_e_sem_tenant_resolvido_o_service_nao_vaza_dado_de_outra_empresa_nem_de_outro_cliente(): void
    {
        $empresaDois = Company::create(['name' => 'Empresa Dois', 'email' => 'contato@empresadois.test']);

        $visitaEmpresaDois = TenantAtual::comTenant($empresaDois->id, function (): WorkOrder {
            $clienteDois = ClientFactory::new()->create();
            $enderecoDois = Address::factory()->create(['client_id' => $clienteDois->id]);

            return WorkOrder::factory()->create([
                'client_id' => $clienteDois->id,
                'address_id' => $enderecoDois->id,
            ]);
        });

        // Sem tenant nenhum resolvido, e com o escopo global inteiro desligado.
        TenantAtual::limpar();

        $visitas = TenantAtual::semEscopo(
            fn (): array => $this->portalDe($this->clientUserA)->visitas()
        );

        $ids = array_column($visitas, 'id');

        $this->assertContains($this->visitaA->id, $ids, 'a própria visita do cliente precisa continuar aparecendo');
        $this->assertNotContains($this->visitaB->id, $ids, 'vazou dado de outro cliente da mesma empresa com o escopo global desligado');
        $this->assertNotContains($visitaEmpresaDois->id, $ids, 'vazou dado de outra empresa com o escopo global desligado');
    }

    public function test_com_escopo_global_desligado_visita_de_outro_cliente_continua_devolvendo_404(): void
    {
        TenantAtual::limpar();

        $this->expectException(ModelNotFoundException::class);

        TenantAtual::semEscopo(
            fn () => $this->portalDe($this->clientUserA)->visita($this->visitaB->id)
        );
    }
}
