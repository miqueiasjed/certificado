<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\WorkOrder;
use App\Models\User;
use App\Services\WorkOrderService;
use Database\Factories\WorkOrderFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use RuntimeException;
use Tests\TestCase;

/**
 * Recibo de pagamento por URL assinada.
 *
 * A rota `service-orders.receipt` é a única do sistema que responde sem login,
 * porque quem abre o recibo é o cliente final, que não tem conta. Antes ela
 * estava aberta a qualquer um que conhecesse um id de OS, e o recibo traz nome
 * do cliente, endereço e valores pagos.
 *
 * Este teste prende três coisas:
 *
 * 1. Sem assinatura válida a rota não responde, e a pessoa vê um aviso legível
 *    em português, não a página crua de 403.
 * 2. Com a assinatura emitida pelo sistema o recibo sai.
 * 3. A empresa impressa no recibo é a dona da ordem de serviço, e não a empresa
 *    1 por padrão. Com dois tenants, cair na empresa 1 emitiria documento com o
 *    CNPJ da concorrente.
 */
class ReciboAssinadoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    // -----------------------------------------------------------------
    // Sem assinatura: a rota não responde
    // -----------------------------------------------------------------

    public function test_link_com_id_puro_nao_abre_mais_o_recibo(): void
    {
        $ordem = $this->criarOrdemPaga();

        $resposta = $this->get("/service-orders/{$ordem->id}/receipt");

        $resposta->assertForbidden();
        $resposta->assertSee('Link expirado ou inválido');
        $resposta->assertDontSee('Invalid signature');
    }

    public function test_link_com_assinatura_adulterada_nao_abre_o_recibo(): void
    {
        $ordem = $this->criarOrdemPaga();

        $url = $this->urlAssinada($ordem);

        // Troca o último caractere da assinatura: qualquer alteração na URL,
        // inclusive no id da ordem, invalida o hash.
        $adulterada = substr($url, 0, -1) . (str_ends_with($url, 'a') ? 'b' : 'a');

        $this->get($adulterada)->assertForbidden();
    }

    public function test_link_expirado_nao_abre_o_recibo(): void
    {
        $ordem = $this->criarOrdemPaga();

        $url = $this->urlAssinada($ordem);

        $this->travel(WorkOrderService::DIAS_DE_VALIDADE_DO_LINK_DO_RECIBO + 1)->days();

        $resposta = $this->get($url);

        $resposta->assertForbidden();
        $resposta->assertSee('Link expirado ou inválido');
    }

    public function test_link_dentro_da_validade_ainda_abre_o_recibo(): void
    {
        $ordem = $this->criarOrdemPaga();

        $url = $this->urlAssinada($ordem);

        $this->travel(WorkOrderService::DIAS_DE_VALIDADE_DO_LINK_DO_RECIBO - 1)->days();

        $this->get($url)->assertOk();
    }

    // -----------------------------------------------------------------
    // Com assinatura válida: o recibo sai, sem usuário autenticado
    // -----------------------------------------------------------------

    public function test_link_assinado_emite_o_recibo_sem_login(): void
    {
        $ordem = $this->criarOrdemPaga();

        $resposta = $this->get($this->urlAssinada($ordem));

        $resposta->assertOk();
        $resposta->assertHeader('content-type', 'application/pdf');
    }

    public function test_ordem_nao_paga_devolve_aviso_em_vez_de_recibo(): void
    {
        $ordem = $this->criarOrdemPaga(['payment_status' => 'pending']);

        $resposta = $this->get($this->urlAssinada($ordem));

        $resposta->assertStatus(409);
        $resposta->assertSee('Recibo indisponível');
    }

    // -----------------------------------------------------------------
    // Empresa emitente: a dona da OS, nunca a empresa 1 por padrão
    // -----------------------------------------------------------------

    public function test_recibo_sai_com_a_empresa_dona_da_ordem_e_nao_com_a_empresa_1(): void
    {
        $segunda = $this->criarSegundaEmpresa();

        $this->assertNotSame(1, $segunda->id, 'o cenário exige uma empresa diferente da fundadora');

        $ordem = $this->criarOrdemPaga(['company_id' => $segunda->id]);

        $empresaImpressa = null;

        View::composer('pdf.receipt', function ($view) use (&$empresaImpressa) {
            $empresaImpressa = $view->getData()['company'] ?? null;
        });

        $this->get($this->urlAssinada($ordem))->assertOk();

        $this->assertNotNull($empresaImpressa, 'o recibo deveria ter sido renderizado com uma empresa');
        $this->assertSame(
            $segunda->id,
            $empresaImpressa->id,
            'o recibo saiu com a empresa errada: sem usuário autenticado o tenant tem de vir do company_id da OS'
        );
        $this->assertSame('Segunda Dedetizadora', $empresaImpressa->name);
        $this->assertSame('99.999.999/0001-99', $empresaImpressa->cnpj);
    }

    /**
     * Ordem sem empresa não vira recibo da empresa 1: a emissão para.
     */
    public function test_ordem_sem_empresa_nao_cai_na_empresa_1(): void
    {
        $ordemSemEmpresa = new WorkOrder();
        $ordemSemEmpresa->id = 4242;

        $this->assertNull($ordemSemEmpresa->company_id, 'o cenário exige uma ordem sem company_id');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('está sem empresa (company_id)');

        app(WorkOrderService::class)->empresaEmitenteDoRecibo($ordemSemEmpresa);
    }

    // -----------------------------------------------------------------
    // Tela da OS: o botão recebe a URL assinada pronta
    // -----------------------------------------------------------------

    public function test_tela_da_ordem_entrega_o_link_do_recibo_ja_assinado(): void
    {
        $ordem = $this->criarOrdemPaga();

        $administrador = User::factory()->create();
        $administrador->assignRole('administrador');

        $resposta = $this->actingAs($administrador)->get("/work-orders/{$ordem->id}");

        $resposta->assertOk();

        $reciboUrl = $resposta->inertiaProps('reciboUrl');

        $this->assertIsString($reciboUrl, 'a tela da OS paga precisa receber a URL do recibo');
        $this->assertStringContainsString('signature=', $reciboUrl);
        $this->assertStringContainsString('expires=', $reciboUrl);

        // O link entregue pela tela precisa funcionar de fato.
        $this->get($reciboUrl)->assertOk();
    }

    public function test_tela_da_ordem_nao_paga_nao_entrega_link_de_recibo(): void
    {
        $ordem = $this->criarOrdemPaga(['payment_status' => 'pending']);

        $administrador = User::factory()->create();
        $administrador->assignRole('administrador');

        $resposta = $this->actingAs($administrador)->get("/work-orders/{$ordem->id}");

        $resposta->assertOk();
        $this->assertNull($resposta->inertiaProps('reciboUrl'));
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function criarOrdemPaga(array $atributos = []): WorkOrder
    {
        return WorkOrderFactory::new()->create(array_merge([
            'payment_status' => 'paid',
        ], $atributos));
    }

    private function criarSegundaEmpresa(): Company
    {
        Company::query()->whereKey(1)->update(['name' => 'Fundadora', 'cnpj' => '11.111.111/0001-11']);

        return Company::create([
            'name' => 'Segunda Dedetizadora',
            'cnpj' => '99.999.999/0001-99',
        ]);
    }

    private function urlAssinada(WorkOrder $ordem): string
    {
        return app(WorkOrderService::class)->urlAssinadaDoRecibo($ordem);
    }
}
