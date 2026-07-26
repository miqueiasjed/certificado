<?php

namespace Tests\Feature;

use App\Models\FinancialEntry;
use App\Models\PaymentDetail;
use App\Models\User;
use Database\Factories\PaymentDetailFactory;
use Database\Factories\WorkOrderFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Task 2.15 do Plano 2: garante por teste automatizado que papel sem
 * permissão financeira recebe 403 em cada rota de dinheiro, uma a uma, e que
 * nenhuma rota financeira nova entra sem middleware de autorização.
 *
 * Task 2.20 do Plano 2: `payment-details` não tinha ação `index` no
 * controller, então a rota `GET /payment-details` foi removida do resource
 * (só sobraram `store`, `show`, `update` e `destroy`). Ela saiu da lista de
 * rotas de leitura abaixo.
 */
class AcessoFinanceiroTest extends TestCase
{
    use RefreshDatabase;

    /**
     * As seis rotas de leitura do módulo financeiro que continuam
     * registradas após a task 2.20.
     */
    private const ROTAS_FINANCEIRAS = [
        '/financial-dashboard',
        '/cash-flow',
        '/cash-flow/stats',
        '/cash-flow/export',
        '/financial-entries',
        '/financial-withdrawals',
    ];

    private const PAPEIS_SEM_ACESSO_FINANCEIRO = ['tecnico', 'comercial', 'leitura'];

    private const PAPEIS_COM_ACESSO_FINANCEIRO = ['financeiro', 'administrador'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    // -----------------------------------------------------------------
    // Leitura: 403 para quem não tem acesso financeiro
    // -----------------------------------------------------------------

    public function test_papel_sem_acesso_financeiro_recebe_403_em_cada_rota_financeira(): void
    {
        foreach (self::PAPEIS_SEM_ACESSO_FINANCEIRO as $papel) {
            $usuario = $this->criarUsuarioComPapel($papel);

            foreach (self::ROTAS_FINANCEIRAS as $rota) {
                $resposta = $this->actingAs($usuario)->get($rota);

                $resposta->assertForbidden("papel '{$papel}' deveria receber 403 em {$rota}");
            }
        }
    }

    // -----------------------------------------------------------------
    // Leitura: papel autorizado não é barrado
    // -----------------------------------------------------------------

    public function test_papel_com_acesso_financeiro_nao_e_barrado_em_cada_rota_financeira(): void
    {
        foreach (self::PAPEIS_COM_ACESSO_FINANCEIRO as $papel) {
            $usuario = $this->criarUsuarioComPapel($papel);

            foreach (self::ROTAS_FINANCEIRAS as $rota) {
                $resposta = $this->actingAs($usuario)->get($rota);

                $this->assertNotSame(
                    403,
                    $resposta->status(),
                    "papel '{$papel}' não deveria receber 403 em {$rota}"
                );

                $this->assertContains(
                    $resposta->status(),
                    [200, 302],
                    "papel '{$papel}' recebeu status inesperado {$resposta->status()} em {$rota}"
                );
            }
        }
    }

    // -----------------------------------------------------------------
    // Escrita: cada ação negada para quem não tem a permissão específica
    // -----------------------------------------------------------------

    /**
     * POST /financial-entries exige financeiro-lancamento-criar. Testa que
     * quem não tem a permissão é barrado e que quem tem consegue criar.
     */
    public function test_criar_entrada_financeira_e_negado_para_quem_nao_tem_a_permissao(): void
    {
        $dadosValidos = [
            'source' => 'manual',
            'amount' => 150.00,
            'description' => 'Entrada de teste',
            'entry_date' => '2026-07-20',
        ];

        foreach (self::PAPEIS_SEM_ACESSO_FINANCEIRO as $papel) {
            $usuario = $this->criarUsuarioComPapel($papel);

            $resposta = $this->actingAs($usuario)->post('/financial-entries', $dadosValidos);

            $resposta->assertForbidden("papel '{$papel}' não deveria conseguir criar entrada financeira");
        }

        $this->assertSame(0, FinancialEntry::query()->count(), 'papel sem permissão não deveria ter criado entrada financeira');

        foreach (self::PAPEIS_COM_ACESSO_FINANCEIRO as $papel) {
            $usuario = $this->criarUsuarioComPapel($papel);

            $resposta = $this->actingAs($usuario)->post('/financial-entries', $dadosValidos);

            $this->assertNotSame(
                403,
                $resposta->status(),
                "papel '{$papel}' deveria conseguir criar entrada financeira"
            );
        }

        $this->assertSame(
            count(self::PAPEIS_COM_ACESSO_FINANCEIRO),
            FinancialEntry::query()->count(),
            'cada papel autorizado deveria ter criado uma entrada financeira'
        );
    }

    /**
     * DELETE /financial-entries/{id} exige financeiro-lancamento-excluir.
     * A entrada usada é de origem manual, a única que o controller permite
     * excluir.
     */
    public function test_excluir_entrada_financeira_e_negado_para_quem_nao_tem_a_permissao(): void
    {
        foreach (self::PAPEIS_SEM_ACESSO_FINANCEIRO as $papel) {
            $entrada = $this->criarEntradaFinanceiraManual();
            $usuario = $this->criarUsuarioComPapel($papel);

            $resposta = $this->actingAs($usuario)->delete("/financial-entries/{$entrada->id}");

            $resposta->assertForbidden("papel '{$papel}' não deveria conseguir excluir entrada financeira");
            $this->assertNotNull($entrada->fresh(), "a entrada financeira não deveria ter sido excluída pelo papel '{$papel}'");
        }

        foreach (self::PAPEIS_COM_ACESSO_FINANCEIRO as $papel) {
            $entrada = $this->criarEntradaFinanceiraManual();
            $usuario = $this->criarUsuarioComPapel($papel);

            $resposta = $this->actingAs($usuario)->delete("/financial-entries/{$entrada->id}");

            $this->assertNotSame(
                403,
                $resposta->status(),
                "papel '{$papel}' deveria conseguir excluir entrada financeira"
            );
            $this->assertNull($entrada->fresh(), "a entrada financeira deveria ter sido excluída pelo papel '{$papel}'");
        }
    }

    /**
     * POST /payment-details/{id}/reopen exige pagamento-reabrir.
     */
    public function test_reabrir_pagamento_e_negado_para_quem_nao_tem_a_permissao(): void
    {
        foreach (self::PAPEIS_SEM_ACESSO_FINANCEIRO as $papel) {
            $parcela = $this->criarParcelaPaga();
            $usuario = $this->criarUsuarioComPapel($papel);

            $resposta = $this->actingAs($usuario)->post("/payment-details/{$parcela->id}/reopen");

            $resposta->assertForbidden("papel '{$papel}' não deveria conseguir reabrir pagamento");
            $this->assertSame('paid', $parcela->fresh()->getRawOriginal('payment_status'), "o pagamento não deveria ter sido reaberto pelo papel '{$papel}'");
        }

        foreach (self::PAPEIS_COM_ACESSO_FINANCEIRO as $papel) {
            $parcela = $this->criarParcelaPaga();
            $usuario = $this->criarUsuarioComPapel($papel);

            $resposta = $this->actingAs($usuario)->post("/payment-details/{$parcela->id}/reopen");

            $this->assertNotSame(
                403,
                $resposta->status(),
                "papel '{$papel}' deveria conseguir reabrir pagamento"
            );
            $this->assertSame('pending', $parcela->fresh()->getRawOriginal('payment_status'), "o pagamento deveria ter sido reaberto pelo papel '{$papel}'");
        }
    }

    // -----------------------------------------------------------------
    // Regressão: usuário sem papel nenhum
    // -----------------------------------------------------------------

    /**
     * Usuário autenticado sem nenhum papel atribuído continua sem acesso
     * financeiro algum. Este é o cenário que mais falha em silêncio: alguém
     * assume que "autenticado" já basta.
     */
    public function test_usuario_sem_papel_nenhum_recebe_403_em_todas_as_rotas_financeiras(): void
    {
        $usuario = User::factory()->create();

        foreach (self::ROTAS_FINANCEIRAS as $rota) {
            $resposta = $this->actingAs($usuario)->get($rota);

            $resposta->assertForbidden("usuário sem papel deveria receber 403 em {$rota}, nunca 200");
        }
    }

    // -----------------------------------------------------------------
    // Estrutural: nenhuma rota financeira sem middleware de permissão
    // -----------------------------------------------------------------

    /**
     * Percorre toda a tabela de rotas registradas e falha se qualquer rota
     * cujo caminho contenha "financial", "cash-flow" ou "payment" não tiver
     * middleware de permissão ou papel do Spatie. É esta asserção que barra
     * alguém de acrescentar rota financeira desprotegida no futuro: remover
     * o middleware de uma rota financeira faz este teste falhar.
     */
    public function test_toda_rota_financeira_registrada_tem_middleware_de_autorizacao(): void
    {
        $rotasFinanceiras = collect(Route::getRoutes())
            ->filter(fn ($rota): bool => (bool) preg_match('/financial|cash-flow|payment/i', $rota->uri()));

        $this->assertGreaterThan(0, $rotasFinanceiras->count(), 'nenhuma rota financeira foi encontrada, o filtro pode ter quebrado');

        foreach ($rotasFinanceiras as $rota) {
            $middlewares = $rota->gatherMiddleware();

            $temMiddlewareDeAutorizacao = collect($middlewares)->contains(
                fn (string $middleware): bool => str_starts_with($middleware, 'permission:') || str_starts_with($middleware, 'role:')
            );

            $this->assertTrue(
                $temMiddlewareDeAutorizacao,
                "a rota '{$rota->uri()}' [" . implode(',', $rota->methods()) . '] não tem middleware de permissão ou papel'
            );
        }
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function criarUsuarioComPapel(string $papel): User
    {
        $usuario = User::factory()->create();
        $usuario->assignRole($papel);

        return $usuario;
    }

    private function criarEntradaFinanceiraManual(): FinancialEntry
    {
        return FinancialEntry::create([
            'source' => 'manual',
            'amount' => 100.00,
            'description' => 'Entrada manual de teste',
            'entry_date' => '2026-07-20',
            'status' => 'confirmed',
            'created_by' => User::factory()->create()->id,
        ]);
    }

    /**
     * Parcela já paga, pronta para o cenário de reabertura.
     */
    private function criarParcelaPaga(): PaymentDetail
    {
        $ordem = WorkOrderFactory::new()->create([
            'total_cost' => 500.00,
            'final_amount' => 500.00,
            'payment_status' => 'paid',
        ]);

        return PaymentDetailFactory::new()->create([
            'work_order_id' => $ordem->id,
            'payment_status' => 'paid',
            'payment_date' => '2026-07-01',
            'amount_paid' => 500.00,
        ]);
    }
}
