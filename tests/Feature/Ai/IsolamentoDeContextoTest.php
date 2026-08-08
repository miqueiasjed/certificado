<?php

namespace Tests\Feature\Ai;

use App\Models\ActiveIngredient;
use App\Models\Address;
use App\Models\AiUsage;
use App\Models\Antidote;
use App\Models\ChemicalGroup;
use App\Models\Client;
use App\Models\Company;
use App\Models\PestSighting;
use App\Models\Product;
use App\Models\Room;
use App\Models\WorkOrder;
use App\Services\Ai\MontadorDeContexto;
use App\Services\Ai\ProvedorAnthropic;
use App\Support\TenantAtual;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Task 25.7 do Plano 25: o teste mais importante do plano.
 *
 * Um dado de outro tenant dentro do prompt é vazamento com todas as
 * consequências — e é um vazamento que **não aparece em revisão de código por
 * leitura**, porque o montador de contexto parece correto e o texto só é
 * inspecionado em execução. Por isso o teste é literal: monta o contexto da
 * empresa A e varre o texto inteiro procurando cada string da empresa B.
 *
 * Cobre também o isolamento dentro da mesma empresa (cliente A não vaza para
 * o parecer do cliente B), a estabilidade byte a byte do prefixo cacheado, a
 * gravação de uso na chamada que falhou e a ausência da chave de API no log.
 *
 * Nenhum teste aqui chama a API real: `Http::fake()` intercepta tudo.
 */
class IsolamentoDeContextoTest extends TestCase
{
    use RefreshDatabase;

    private const CHAVE = 'sk-ant-chave-secreta-que-nunca-pode-vazar';

    private Company $empresaA;

    private Company $empresaB;

    private MontadorDeContexto $montador;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->empresaA = Company::query()->firstOrFail();
        $this->empresaB = Company::create([
            'name' => 'Dedetizadora Concorrente',
            'email' => 'contato@concorrente.test',
        ]);

        $this->montador = app(MontadorDeContexto::class);

        config()->set('ai.anthropic.chave', self::CHAVE);
        config()->set('ai.anthropic.base_url', 'https://api.anthropic.test');
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Isolamento entre empresas
    // -----------------------------------------------------------------

    public function test_contexto_da_empresa_a_nao_contem_nenhuma_string_da_empresa_b(): void
    {
        $marcasDaEmpresaB = [
            'Supermercado Beta Concorrente',
            'Rua Secreta da Concorrente',
            'Deltametrina Exclusiva Beta',
            'Depósito Refrigerado Beta',
            'Escorpião Amarelo Beta',
        ];

        TenantAtual::comTenant($this->empresaB->id, function (): void {
            $this->montarOsCompleta(
                cliente: 'Supermercado Beta Concorrente',
                rua: 'Rua Secreta da Concorrente',
                produto: 'Deltametrina Exclusiva Beta',
                comodo: 'Depósito Refrigerado Beta',
                praga: 'Escorpião Amarelo Beta',
            );
        });

        $contexto = TenantAtual::comTenant($this->empresaA->id, function (): string {
            $os = $this->montarOsCompleta(
                cliente: 'Padaria Alfa',
                rua: 'Rua das Acácias',
                produto: 'Fipronil Alfa',
                comodo: 'Cozinha Alfa',
                praga: 'Barata Alfa',
            );

            return $this->montador->paraOs($os);
        });

        foreach ($marcasDaEmpresaB as $marca) {
            $this->assertStringNotContainsString(
                $marca,
                $contexto,
                "Vazamento entre empresas: \"{$marca}\" pertence à empresa B e apareceu no contexto montado para a empresa A."
            );
        }

        // Contraprova: o dado da própria empresa precisa estar lá, senão o
        // teste passaria com um montador que devolve string vazia.
        $this->assertStringContainsString('Fipronil Alfa', $contexto);
        $this->assertStringContainsString('Cozinha Alfa', $contexto);
        $this->assertStringContainsString('Barata Alfa', $contexto);
    }

    public function test_contexto_de_um_cliente_nao_contem_dado_de_outro_cliente_da_mesma_empresa(): void
    {
        $contexto = TenantAtual::comTenant($this->empresaA->id, function (): string {
            $this->montarOsCompleta(
                cliente: 'Restaurante Vizinho',
                rua: 'Rua do Vizinho',
                produto: 'Cipermetrina do Vizinho',
                comodo: 'Salão do Vizinho',
                praga: 'Mosca do Vizinho',
            );

            $os = $this->montarOsCompleta(
                cliente: 'Padaria Alfa',
                rua: 'Rua das Acácias',
                produto: 'Fipronil Alfa',
                comodo: 'Cozinha Alfa',
                praga: 'Barata Alfa',
            );

            return $this->montador->paraOs($os);
        });

        foreach (['Restaurante Vizinho', 'Cipermetrina do Vizinho', 'Salão do Vizinho', 'Mosca do Vizinho'] as $marca) {
            $this->assertStringNotContainsString(
                $marca,
                $contexto,
                "\"{$marca}\" é de outro cliente da mesma empresa e não pode entrar neste parecer."
            );
        }
    }

    public function test_montador_nao_alcanca_ordem_de_servico_de_outra_empresa(): void
    {
        $idDaOsDaEmpresaB = TenantAtual::comTenant(
            $this->empresaB->id,
            fn (): int => $this->montarOsCompleta(
                cliente: 'Supermercado Beta Concorrente',
                rua: 'Rua Secreta da Concorrente',
                produto: 'Deltametrina Exclusiva Beta',
                comodo: 'Depósito Refrigerado Beta',
                praga: 'Escorpião Amarelo Beta',
            )->id
        );

        $encontrada = TenantAtual::comTenant(
            $this->empresaA->id,
            fn (): ?WorkOrder => WorkOrder::query()->find($idDaOsDaEmpresaB)
        );

        $this->assertNull(
            $encontrada,
            'A OS da empresa B foi encontrada dentro do tenant da empresa A: o escopo global falhou.'
        );
    }

    // -----------------------------------------------------------------
    // Prefixo cacheado
    // -----------------------------------------------------------------

    public function test_prefixo_de_sistema_e_identico_byte_a_byte_entre_duas_chamadas(): void
    {
        Http::fake(['*' => Http::response($this->respostaDeSucesso())]);

        $provedor = new ProvedorAnthropic;

        TenantAtual::comTenant($this->empresaA->id, function () use ($provedor): void {
            $provedor->gerar(MontadorDeContexto::PREFIXO_DE_SISTEMA, 'Primeira entrada');
            $provedor->gerar(MontadorDeContexto::PREFIXO_DE_SISTEMA, 'Segunda entrada, diferente');
        });

        $prefixos = [];

        Http::assertSent(function ($requisicao) use (&$prefixos): bool {
            $prefixos[] = $requisicao->data()['system'][0]['text'];

            return true;
        });

        $this->assertCount(2, $prefixos);
        $this->assertSame(
            $prefixos[0],
            $prefixos[1],
            'O prefixo de sistema mudou entre duas chamadas: o cache do provedor é casamento de prefixo byte a byte, '
            .'e qualquer variação (data, nome do tenant, identificador) invalida o cache e multiplica a conta.'
        );
    }

    public function test_prefixo_de_sistema_nao_interpola_data_nem_identificador(): void
    {
        $prefixo = MontadorDeContexto::PREFIXO_DE_SISTEMA
            .MontadorDeContexto::PREFIXO_DE_SISTEMA_DO_RESUMO;

        // Um ano de quatro dígitos ou um nome de empresa dentro do prefixo é o
        // sintoma típico de interpolação acidental.
        $this->assertDoesNotMatchRegularExpression('/\b20\d{2}\b/', $prefixo);
        $this->assertStringNotContainsString((string) $this->empresaB->name, $prefixo);
    }

    // -----------------------------------------------------------------
    // Medição da falha e sigilo da chave
    // -----------------------------------------------------------------

    public function test_falha_do_provedor_grava_uso_com_sucesso_falso(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'indisponível']], 503)]);

        TenantAtual::comTenant($this->empresaA->id, function (): void {
            try {
                (new ProvedorAnthropic)->gerar('sistema', 'entrada', ['tipo' => 'parecer_os']);
            } catch (\Throwable) {
                // A gravação do uso é o que este teste confere.
            }
        });

        $uso = TenantAtual::comTenant(
            $this->empresaA->id,
            fn (): ?AiUsage => AiUsage::query()->latest('id')->first()
        );

        $this->assertNotNull($uso, 'Chamada que falhou também precisa ser medida: ela consumiu dinheiro.');
        $this->assertFalse($uso->sucesso);
        $this->assertNotNull($uso->erro);
        $this->assertSame($this->empresaA->id, $uso->company_id);
    }

    public function test_a_chave_de_api_nao_aparece_em_log_nem_em_mensagem_de_erro(): void
    {
        $registrados = [];

        Log::listen(function ($mensagem) use (&$registrados): void {
            $registrados[] = $mensagem->message.' '.json_encode($mensagem->context);
        });

        // Sequência, e não dois `Http::fake()` seguidos: chamadas repetidas a
        // `fake()` com array acumulam os stubs, e o primeiro registrado é o
        // que responde. A primeira chamada falha (mensagem de erro), a
        // segunda passa (linha de log de sucesso), e as duas são inspecionadas
        // atrás da chave.
        // As três primeiras respostas cobrem as três tentativas que o provedor
        // faz para erro 5xx; a quarta atende a segunda geração.
        Http::fakeSequence()
            ->push(['error' => ['message' => 'falhou']], 500)
            ->push(['error' => ['message' => 'falhou']], 500)
            ->push(['error' => ['message' => 'falhou']], 500)
            ->push($this->respostaDeSucesso(), 200);

        $mensagemDeErro = '';

        TenantAtual::comTenant($this->empresaA->id, function () use (&$mensagemDeErro): void {
            try {
                (new ProvedorAnthropic)->gerar('sistema', 'entrada');
            } catch (\Throwable $e) {
                $mensagemDeErro = $e->getMessage();
            }

            (new ProvedorAnthropic)->gerar('sistema', 'entrada');
        });

        $this->assertStringNotContainsString(self::CHAVE, $mensagemDeErro);

        foreach ($registrados as $linha) {
            $this->assertStringNotContainsString(
                self::CHAVE,
                $linha,
                'A chave de API apareceu no log da aplicação.'
            );
        }

        $uso = TenantAtual::comTenant(
            $this->empresaA->id,
            fn () => AiUsage::query()->get()
        );

        foreach ($uso as $linha) {
            $this->assertStringNotContainsString(self::CHAVE, (string) $linha->erro);
        }
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * OS completa: cliente, endereço, cômodo, produto aplicado e praga
     * encontrada. Criada dentro do tenant corrente, então cada campo carrega o
     * `company_id` de quem chamou.
     */
    private function montarOsCompleta(
        string $cliente,
        string $rua,
        string $produto,
        string $comodo,
        string $praga,
    ): WorkOrder {
        $registro = Client::create([
            'name' => $cliente,
            'email' => str()->random(10).'@exemplo.test',
            'phone' => '11912340000',
            'cnpj' => fake()->numerify('##.###.###/0001-##'),
        ]);

        $endereco = Address::create([
            'client_id' => $registro->id,
            'nickname' => $cliente,
            'street' => $rua,
            'number' => '100',
            'district' => 'Centro',
            'city' => 'São Paulo',
            'state' => 'SP',
            'zip' => '01000-000',
            'active' => true,
        ]);

        $sala = Room::create([
            'address_id' => $endereco->id,
            'name' => $comodo,
            'active' => true,
        ]);

        $sufixo = str()->random(6);
        $item = Product::create([
            'name' => $produto,
            'active_ingredient_id' => ActiveIngredient::create(['name' => 'Princípio '.$sufixo])->id,
            'chemical_group_id' => ChemicalGroup::create(['name' => 'Grupo '.$sufixo])->id,
            'antidote_id' => Antidote::create(['name' => 'Antídoto '.$sufixo])->id,
            'controla_estoque' => false,
            'unidade' => 'L',
        ]);

        $os = WorkOrder::create([
            'client_id' => $registro->id,
            'address_id' => $endereco->id,
            'order_number' => 'OS-'.str()->random(8),
            'scheduled_date' => '2026-07-01',
            'status' => 'completed',
            'description' => 'Atendimento de controle de pragas.',
            'active' => true,
        ]);

        $os->products()->attach($item->id, ['quantity' => '2', 'unit' => 'L']);
        $os->rooms()->attach($sala->id, ['observation' => 'Sem restrição']);

        // `pest_sightings.pest_type` é enum fechado no schema, então a marca
        // que este teste procura vai nos campos de texto livre do avistamento
        // — que é justamente por onde o contexto passa.
        PestSighting::create([
            'address_id' => $endereco->id,
            'work_order_id' => $os->id,
            'sighting_date' => '2026-07-01 10:00:00',
            'pest_type' => 'other',
            'severity_level' => 'medium',
            'location_description' => 'Área de '.$praga,
            'description' => $praga,
            'active' => true,
        ]);

        return $os->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function respostaDeSucesso(): array
    {
        return [
            'model' => 'claude-opus-5',
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => 'Parecer de teste.']],
            'usage' => [
                'input_tokens' => 100,
                'output_tokens' => 50,
                'cache_read_input_tokens' => 900,
                'cache_creation_input_tokens' => 0,
            ],
        ];
    }
}
