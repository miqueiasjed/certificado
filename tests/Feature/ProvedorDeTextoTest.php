<?php

namespace Tests\Feature;

use App\Exceptions\IaIndisponivelException;
use App\Exceptions\IaLimiteDeTaxaException;
use App\Exceptions\IaRecusouException;
use App\Models\AiUsage;
use App\Models\Company;
use App\Services\Ai\ProvedorAnthropic;
use App\Services\Ai\ProvedorDeTexto;
use App\Support\TenantAtual;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Task 25.2 do Plano 25: o provedor de texto contra a API da Anthropic.
 *
 * Nenhum teste aqui chama a API real: `Http::fake()` intercepta a requisição e
 * devolve a resposta. O custo é por chamada, e um teste que gasta dinheiro
 * deixa de ser rodado — e um teste que deixa de ser rodado não protege nada.
 *
 * O que estes testes travam:
 *
 * - o corpo enviado (prefixo cacheado, ausência de parâmetro de amostragem);
 * - a tradução de cada resposta de erro para a exceção de domínio certa;
 * - o registro de `ai_usages` em toda tentativa, inclusive na que falhou;
 * - a chave de API não escapar para o corpo da requisição.
 */
class ProvedorDeTextoTest extends TestCase
{
    use RefreshDatabase;

    private const CHAVE = 'sk-ant-chave-de-teste-que-nao-pode-vazar';

    private Company $empresa;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->empresa = Company::query()->firstOrFail();

        config()->set('ai.anthropic.chave', self::CHAVE);
        config()->set('ai.anthropic.base_url', 'https://api.anthropic.test');
        config()->set('ai.modelo', 'claude-opus-5');
        config()->set('ai.max_tokens', 4096);
        config()->set('ai.esforco', 'medium');
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Corpo da requisição
    // -----------------------------------------------------------------

    public function test_prefixo_de_sistema_vai_marcado_para_cache_e_o_dado_variavel_vai_na_mensagem(): void
    {
        Http::fake([
            '*' => Http::response($this->respostaDeSucesso('Parecer gerado.')),
        ]);

        $this->provedor()->gerar('PREFIXO ESTÁVEL', 'Dados da OS 123');

        Http::assertSent(function (Request $requisicao): bool {
            $corpo = $requisicao->data();

            $this->assertSame('PREFIXO ESTÁVEL', $corpo['system'][0]['text']);
            $this->assertSame(
                ['type' => 'ephemeral'],
                $corpo['system'][0]['cache_control'],
                'O prefixo de sistema precisa ir marcado para cache: é ele que se repete em toda geração.'
            );
            $this->assertSame('Dados da OS 123', $corpo['messages'][0]['content']);
            $this->assertSame('user', $corpo['messages'][0]['role']);

            return true;
        });
    }

    public function test_nenhum_parametro_de_amostragem_e_enviado(): void
    {
        Http::fake(['*' => Http::response($this->respostaDeSucesso('Parecer.'))]);

        $this->provedor()->gerar('sistema', 'entrada');

        Http::assertSent(function (Request $requisicao): bool {
            $corpo = $requisicao->data();

            foreach (['temperature', 'top_p', 'top_k'] as $proibido) {
                $this->assertArrayNotHasKey(
                    $proibido,
                    $corpo,
                    "O modelo em uso recusa \"{$proibido}\" com erro 400."
                );
            }

            return true;
        });
    }

    public function test_modelo_e_teto_de_saida_vem_da_configuracao(): void
    {
        config()->set('ai.modelo', 'claude-sonnet-5');
        config()->set('ai.max_tokens', 1234);

        Http::fake(['*' => Http::response($this->respostaDeSucesso('Parecer.', 'claude-sonnet-5'))]);

        $provedor = $this->provedor();

        $this->assertSame('claude-sonnet-5', $provedor->modelo());

        $provedor->gerar('sistema', 'entrada');

        Http::assertSent(function (Request $requisicao): bool {
            $corpo = $requisicao->data();

            $this->assertSame('claude-sonnet-5', $corpo['model']);
            $this->assertSame(1234, $corpo['max_tokens']);
            $this->assertSame('medium', $corpo['output_config']['effort']);

            return true;
        });
    }

    public function test_a_chave_de_api_vai_no_cabecalho_e_nunca_no_corpo(): void
    {
        Http::fake(['*' => Http::response($this->respostaDeSucesso('Parecer.'))]);

        $this->provedor()->gerar('sistema', 'entrada');

        Http::assertSent(function (Request $requisicao): bool {
            $this->assertSame(self::CHAVE, $requisicao->header('x-api-key')[0]);
            $this->assertStringNotContainsString(self::CHAVE, $requisicao->body());

            return true;
        });
    }

    // -----------------------------------------------------------------
    // Resposta de sucesso
    // -----------------------------------------------------------------

    public function test_resposta_devolve_texto_modelo_e_os_quatro_contadores_de_token(): void
    {
        Http::fake(['*' => Http::response($this->respostaDeSucesso('Texto do parecer.'))]);

        $resposta = $this->provedor()->gerar('sistema', 'entrada');

        $this->assertSame('Texto do parecer.', $resposta->texto);
        $this->assertSame('claude-opus-5', $resposta->modelo);
        $this->assertSame(1200, $resposta->tokensEntrada);
        $this->assertSame(300, $resposta->tokensSaida);
        $this->assertSame(800, $resposta->tokensCacheLeitura);
        $this->assertSame(0, $resposta->tokensCacheEscrita);
        $this->assertTrue($resposta->leuDoCache());
    }

    public function test_blocos_que_nao_sao_texto_ficam_de_fora_do_rascunho(): void
    {
        Http::fake(['*' => Http::response([
            'model' => 'claude-opus-5',
            'stop_reason' => 'end_turn',
            'content' => [
                ['type' => 'thinking', 'thinking' => ''],
                ['type' => 'text', 'text' => 'Primeiro trecho. '],
                ['type' => 'text', 'text' => 'Segundo trecho.'],
            ],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ])]);

        $resposta = $this->provedor()->gerar('sistema', 'entrada');

        $this->assertSame('Primeiro trecho. Segundo trecho.', $resposta->texto);
    }

    // -----------------------------------------------------------------
    // Erros
    // -----------------------------------------------------------------

    public function test_limite_de_taxa_vira_excecao_propria(): void
    {
        Http::fake(['*' => Http::response(
            ['error' => ['message' => 'rate limit']],
            429,
            ['retry-after' => '42']
        )]);

        $this->expectException(IaLimiteDeTaxaException::class);
        $this->expectExceptionMessageMatches('/42 segundo/');

        $this->provedor()->gerar('sistema', 'entrada');
    }

    public function test_erro_do_provedor_vira_indisponibilidade(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'boom']], 503)]);

        $this->expectException(IaIndisponivelException::class);

        $this->provedor()->gerar('sistema', 'entrada');
    }

    public function test_requisicao_invalida_vira_recusa(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'parâmetro inválido']], 400)]);

        $this->expectException(IaRecusouException::class);

        $this->provedor()->gerar('sistema', 'entrada');
    }

    public function test_recusa_do_modelo_e_conferida_antes_de_ler_o_conteudo(): void
    {
        Http::fake(['*' => Http::response([
            'model' => 'claude-opus-5',
            'stop_reason' => 'refusal',
            'stop_details' => ['type' => 'refusal', 'category' => 'cyber'],
            'content' => [],
            'usage' => ['input_tokens' => 900, 'output_tokens' => 0],
        ])]);

        $this->expectException(IaRecusouException::class);

        $this->provedor()->gerar('sistema', 'entrada');
    }

    public function test_sem_chave_configurada_a_geracao_falha_sem_chamar_a_api(): void
    {
        config()->set('ai.anthropic.chave', null);
        Http::fake();

        try {
            $this->provedor()->gerar('sistema', 'entrada');
            $this->fail('Esperava IaIndisponivelException por falta de chave.');
        } catch (IaIndisponivelException) {
            Http::assertNothingSent();
        }
    }

    // -----------------------------------------------------------------
    // Medição
    // -----------------------------------------------------------------

    public function test_sucesso_grava_uso_no_tenant_corrente(): void
    {
        Http::fake(['*' => Http::response($this->respostaDeSucesso('Parecer.'))]);

        TenantAtual::comTenant($this->empresa->id, function (): void {
            $this->provedor()->gerar('sistema', 'entrada', ['tipo' => 'parecer_os']);
        });

        $uso = AiUsage::query()->sole();

        $this->assertSame($this->empresa->id, $uso->company_id);
        $this->assertSame('parecer_os', $uso->tipo);
        $this->assertSame('claude-opus-5', $uso->modelo);
        $this->assertSame(1200, $uso->tokens_entrada);
        $this->assertSame(300, $uso->tokens_saida);
        $this->assertSame(800, $uso->tokens_cache_leitura);
        $this->assertTrue($uso->sucesso);
        $this->assertNull($uso->erro);
    }

    public function test_falha_tambem_grava_uso_com_o_motivo(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'parâmetro inválido']], 400)]);

        TenantAtual::comTenant($this->empresa->id, function (): void {
            try {
                $this->provedor()->gerar('sistema', 'entrada', ['tipo' => 'parecer_os']);
            } catch (IaRecusouException) {
                // A gravação do uso é o que este teste confere.
            }
        });

        $uso = AiUsage::query()->sole();

        $this->assertSame($this->empresa->id, $uso->company_id);
        $this->assertFalse($uso->sucesso);
        $this->assertNotNull($uso->erro);
        $this->assertStringNotContainsString(self::CHAVE, $uso->erro);
    }

    public function test_uso_de_uma_empresa_nao_aparece_para_a_outra(): void
    {
        Http::fake(['*' => Http::response($this->respostaDeSucesso('Parecer.'))]);

        $outra = Company::create(['name' => 'Empresa Dois', 'email' => 'dois@exemplo.test']);

        TenantAtual::comTenant($this->empresa->id, function (): void {
            $this->provedor()->gerar('sistema', 'entrada', ['tipo' => 'parecer_os']);
        });

        $visiveisNaOutra = TenantAtual::comTenant(
            $outra->id,
            fn (): int => AiUsage::query()->count()
        );

        $this->assertSame(0, $visiveisNaOutra);
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function provedor(): ProvedorDeTexto
    {
        // Instância nova a cada chamada porque o provedor lê a configuração no
        // construtor, e cada teste ajusta `config()` antes de gerar.
        return new ProvedorAnthropic;
    }

    /**
     * @return array<string, mixed>
     */
    private function respostaDeSucesso(string $texto, string $modelo = 'claude-opus-5'): array
    {
        return [
            'id' => 'msg_teste',
            'model' => $modelo,
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => $texto]],
            'usage' => [
                'input_tokens' => 1200,
                'output_tokens' => 300,
                'cache_read_input_tokens' => 800,
                'cache_creation_input_tokens' => 0,
            ],
        ];
    }
}
