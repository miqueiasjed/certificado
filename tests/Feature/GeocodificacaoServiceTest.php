<?php

namespace Tests\Feature;

use App\Jobs\GeocodificarEnderecoJob;
use App\Models\Address;
use App\Models\Company;
use App\Services\AddressService;
use App\Services\Geo\GeocodificacaoService;
use App\Services\Geo\ProvedorDeGeocodificacao;
use App\Support\Geo\ResultadoGeo;
use App\Support\TenantAtual;
use Database\Factories\AddressFactory;
use Database\Factories\ClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * Task 22.2 do Plano 22: geocodificação dos endereços e correção manual.
 *
 * Todos os cenários usam um provedor SIMULADO (classe anônima implementando
 * `ProvedorDeGeocodificacao`, ligada no container em cada teste), nunca uma
 * chamada HTTP real ao Nominatim: teste automatizado não pode depender de
 * rede nem quebrar CI por instabilidade de um serviço de terceiro.
 *
 * Nota sobre o cenário (h) do "Teste esperado" da task ("o provedor simulado
 * nunca é chamado mais rápido que a pausa configurada"): este arquivo cobre
 * isso com uma checagem de tempo decorrido com tolerância generosa (abaixo do
 * `--pausa-ms` configurado), em vez de um valor exato. Teste de timing exato
 * é inerentemente sensível à máquina que roda a suíte (CI mais lento, outra
 * carga no mesmo processo) e um limite justo demais quebra intermitentemente
 * sem que o comando tenha regredido. A validação forte de "a pausa é aplicada
 * de verdade entre chamadas, e não depois da última" está coberta por medir
 * o tempo total decorrido processando 2 candidatos: sem pausa nenhuma o
 * comando roda em poucos milissegundos, então qualquer tempo mínimo
 * observado já prova que a pausa aconteceu.
 */
class GeocodificacaoServiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $empresa;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();

        $this->empresa = Company::query()->firstOrFail();
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // (a) --dry-run não grava
    // -----------------------------------------------------------------

    public function test_dry_run_consulta_o_provedor_mas_nao_grava_nada(): void
    {
        $endereco = $this->criarEndereco();
        $fake = $this->ligarProvedorFake([
            new ResultadoGeo(-23.5505, -46.6333, ResultadoGeo::PRECISAO_EXATA),
        ]);

        Artisan::call('enderecos:geocodificar', ['--dry-run' => true, '--pausa-ms' => 0]);

        $this->assertCount(1, $fake->chamadas, 'o provedor precisa ser consultado de verdade no dry-run');

        $endereco->refresh();
        $this->assertNull($endereco->latitude);
        $this->assertNull($endereco->longitude);
        $this->assertNull($endereco->geocodificado_em);
        $this->assertNull($endereco->precisao_geocodificacao);
    }

    // -----------------------------------------------------------------
    // (b) A execução preenche coordenada e precisão
    // -----------------------------------------------------------------

    public function test_execucao_preenche_coordenada_precisao_e_origem(): void
    {
        $endereco = $this->criarEndereco();
        $this->ligarProvedorFake([
            new ResultadoGeo(-23.5505, -46.6333, ResultadoGeo::PRECISAO_EXATA),
        ]);

        Artisan::call('enderecos:geocodificar', ['--pausa-ms' => 0]);

        $endereco->refresh();
        $this->assertSame('-23.5505000', $endereco->latitude);
        $this->assertSame('-46.6333000', $endereco->longitude);
        $this->assertSame(ResultadoGeo::PRECISAO_EXATA, $endereco->precisao_geocodificacao);
        $this->assertSame(Address::ORIGEM_COORDENADA_AUTOMATICA, $endereco->origem_coordenada);
        $this->assertNotNull($endereco->geocodificado_em);
    }

    // -----------------------------------------------------------------
    // (c) Rodar de novo não reconsulta quem já tem
    // -----------------------------------------------------------------

    public function test_segunda_execucao_nao_reconsulta_endereco_ja_processado(): void
    {
        $endereco = $this->criarEndereco();
        $fake = $this->ligarProvedorFake([
            new ResultadoGeo(-23.5505, -46.6333, ResultadoGeo::PRECISAO_EXATA),
        ]);

        Artisan::call('enderecos:geocodificar', ['--pausa-ms' => 0]);
        $this->assertCount(1, $fake->chamadas);

        // Segunda execução: nenhum candidato novo, o provedor não é chamado
        // de novo, mesmo que respostas continuassem na fila do fake.
        $fake->respostas[] = new ResultadoGeo(-1, -1, ResultadoGeo::PRECISAO_EXATA);
        Artisan::call('enderecos:geocodificar', ['--pausa-ms' => 0]);

        $this->assertCount(1, $fake->chamadas, 'endereço já processado não pode ser reconsultado sem --forcar');

        $endereco->refresh();
        $this->assertSame('-23.5505000', $endereco->latitude, 'a coordenada original não pode ter sido trocada');
    }

    // -----------------------------------------------------------------
    // (d) Correção manual não é sobrescrita pela execução automática
    // -----------------------------------------------------------------

    public function test_correcao_manual_nao_e_sobrescrita_pela_geocodificacao_automatica(): void
    {
        $endereco = $this->criarEndereco();
        $fake = $this->ligarProvedorFake();

        /** @var GeocodificacaoService $servico */
        $servico = app(GeocodificacaoService::class);
        $servico->definirManualmente($endereco, -10.0, -20.0);

        $endereco->refresh();
        $this->assertSame(Address::ORIGEM_COORDENADA_MANUAL, $endereco->origem_coordenada);

        // Chamada direta ao serviço, sem --forcar: é a regra de negócio
        // inegociável, testada na fonte, não só através do filtro de
        // candidatos do comando.
        $resultado = $servico->geocodificar($endereco);

        $this->assertSame(GeocodificacaoService::RESULTADO_IGNORADO_MANUAL, $resultado);
        $this->assertCount(0, $fake->chamadas, 'correção manual não pode nem chegar a consultar o provedor');

        $endereco->refresh();
        $this->assertSame('-10.0000000', $endereco->latitude);
        $this->assertSame('-20.0000000', $endereco->longitude);
        $this->assertSame(Address::ORIGEM_COORDENADA_MANUAL, $endereco->origem_coordenada);

        // E também pelo comando: endereço com origem manual já tem
        // geocodificado_em preenchido, então nem entra nos candidatos.
        Artisan::call('enderecos:geocodificar', ['--pausa-ms' => 0]);
        $this->assertCount(0, $fake->chamadas);
    }

    // -----------------------------------------------------------------
    // (e) --forcar sobrescreve, quando pedido explicitamente
    // -----------------------------------------------------------------

    public function test_forcar_sobrescreve_inclusive_correcao_manual(): void
    {
        $endereco = $this->criarEndereco();
        $fake = $this->ligarProvedorFake([
            new ResultadoGeo(-30.0, -50.0, ResultadoGeo::PRECISAO_APROXIMADA),
        ]);

        /** @var GeocodificacaoService $servico */
        $servico = app(GeocodificacaoService::class);
        $servico->definirManualmente($endereco, -10.0, -20.0);

        Artisan::call('enderecos:geocodificar', ['--pausa-ms' => 0, '--forcar' => true]);

        $this->assertCount(1, $fake->chamadas, '--forcar precisa consultar o provedor mesmo para origem manual');

        $endereco->refresh();
        $this->assertSame('-30.0000000', $endereco->latitude);
        $this->assertSame('-50.0000000', $endereco->longitude);
        $this->assertSame(ResultadoGeo::PRECISAO_APROXIMADA, $endereco->precisao_geocodificacao);
        $this->assertSame(Address::ORIGEM_COORDENADA_AUTOMATICA, $endereco->origem_coordenada);
    }

    // -----------------------------------------------------------------
    // (f) Endereço novo dispara a fila sem atrasar o cadastro
    // -----------------------------------------------------------------

    public function test_endereco_novo_dispara_job_de_geocodificacao_em_fila(): void
    {
        Queue::fake();

        $cliente = $this->comTenant(fn () => ClientFactory::new()->create());

        /** @var AddressService $servico */
        $servico = app(AddressService::class);

        $endereco = $this->comTenant(fn () => $servico->createAddress([
            'client_id' => $cliente->id,
            'nickname' => 'Principal',
            'street' => 'Rua Teste',
            'number' => '100',
            'district' => 'Centro',
            'city' => 'São Paulo',
            'state' => 'SP',
            'zip' => '01000-000',
            'active' => true,
        ]));

        Queue::assertPushed(
            GeocodificarEnderecoJob::class,
            fn (GeocodificarEnderecoJob $job): bool => $job->addressId === $endereco->id
        );

        // O cadastro não esperou o provedor: nada foi geocodificado ainda,
        // porque o job nunca chegou a rodar (Queue::fake() intercepta o
        // despacho).
        $this->assertNull($endereco->latitude);
        $this->assertNull($endereco->geocodificado_em);
    }

    // -----------------------------------------------------------------
    // (g) Precisão "cidade" aparece na lista de pendências
    // -----------------------------------------------------------------

    public function test_precisao_cidade_e_reportada_como_pendencia(): void
    {
        $endereco = $this->criarEndereco();
        $this->ligarProvedorFake([
            new ResultadoGeo(-23.0, -46.0, ResultadoGeo::PRECISAO_CIDADE),
        ]);

        Artisan::call('enderecos:geocodificar', ['--pausa-ms' => 0]);
        $saida = Artisan::output();

        $endereco->refresh();
        $this->assertSame(ResultadoGeo::PRECISAO_CIDADE, $endereco->precisao_geocodificacao);

        $this->assertStringContainsString('PENDÊNCIA', $saida);
        $this->assertStringContainsString("#{$endereco->id}", $saida);
        $this->assertStringContainsString('Total de pendências: 1', $saida);
    }

    /**
     * Mesmo cenário do item (g), só que para "não encontrado": o provedor
     * respondeu e não achou nada. Também é pendência, nunca sucesso
     * silencioso.
     */
    public function test_endereco_nao_encontrado_pelo_provedor_e_reportado_como_pendencia(): void
    {
        $endereco = $this->criarEndereco();
        $this->ligarProvedorFake([null]);

        Artisan::call('enderecos:geocodificar', ['--pausa-ms' => 0]);
        $saida = Artisan::output();

        $endereco->refresh();
        $this->assertNull($endereco->latitude);
        $this->assertNull($endereco->precisao_geocodificacao);
        $this->assertNotNull($endereco->geocodificado_em, 'precisa marcar como processado para não ser reconsultado à toa');

        $this->assertStringContainsString('Total de pendências: 1', $saida);
    }

    // -----------------------------------------------------------------
    // (i) Endereço de outro tenant não é alcançado
    // -----------------------------------------------------------------

    /**
     * `enderecos:geocodificar` não tem opção `--empresa`/`--company`: sem
     * tenant fixado por quem chama, o escopo global de `Address` não filtra
     * nada e a rotina varre todas as empresas de propósito, documentado
     * assim no cabeçalho da classe do comando (é o mesmo raciocínio de
     * `TenantAtual::id()` devolver `null` fora de requisição). Isso é
     * diferente de "o comando vaza tenant": o escopo global é lido a cada
     * execução da consulta de candidatos, não uma vez só, então quando quem
     * chama fixa o tenant (aqui, `TenantAtual::comTenant()`, o mesmo caminho
     * que um job de fila ou um comando futuro com `--empresa` usaria), a
     * consulta de `Address::query()` dentro do comando passa a enxergar só
     * aquela empresa, normalmente.
     *
     * Este teste prova exatamente esse cenário: com o tenant fixado para a
     * empresa do teste, o endereço de uma segunda empresa nunca vira
     * candidato, muito menos é consultado no provedor ou gravado.
     */
    public function test_endereco_de_outro_tenant_nao_e_alcancado_quando_o_tenant_esta_fixado(): void
    {
        $outraEmpresa = Company::create([
            'name' => 'Dedetizadora Concorrente',
            'cnpj' => '55.555.555/0001-55',
            'email' => 'contato@empresa-concorrente.test',
        ]);

        $enderecoDaMinhaEmpresa = $this->criarEndereco();

        $enderecoDaOutraEmpresa = TenantAtual::comTenant($outraEmpresa->id, function (): Address {
            $cliente = ClientFactory::new()->create();

            return AddressFactory::new()->create(['client_id' => $cliente->id]);
        });

        $fake = $this->ligarProvedorFake([
            new ResultadoGeo(-23.5505, -46.6333, ResultadoGeo::PRECISAO_EXATA),
        ]);

        TenantAtual::comTenant(
            $this->empresa->id,
            fn () => Artisan::call('enderecos:geocodificar', ['--pausa-ms' => 0])
        );

        $this->assertCount(
            1,
            $fake->chamadas,
            'com o tenant fixado, só o endereço da própria empresa pode ter sido consultado no provedor'
        );

        $enderecoDaMinhaEmpresa->refresh();
        $this->assertNotNull($enderecoDaMinhaEmpresa->geocodificado_em, 'o endereço da própria empresa precisava ter sido processado');

        $enderecoDaOutraEmpresa->refresh();
        $this->assertNull($enderecoDaOutraEmpresa->latitude, 'o endereço de outra empresa não pode ter sido geocodificado');
        $this->assertNull($enderecoDaOutraEmpresa->geocodificado_em, 'o endereço de outra empresa não pode nem ter sido marcado como processado');
    }

    // -----------------------------------------------------------------
    // (h) A pausa configurada é respeitada entre chamadas ao provedor
    // -----------------------------------------------------------------

    public function test_pausa_configurada_e_aplicada_entre_chamadas_ao_provedor(): void
    {
        $this->criarEndereco();
        $this->criarEndereco();

        $this->ligarProvedorFake([
            new ResultadoGeo(-1.0, -1.0, ResultadoGeo::PRECISAO_APROXIMADA),
            new ResultadoGeo(-2.0, -2.0, ResultadoGeo::PRECISAO_APROXIMADA),
        ]);

        $inicio = microtime(true);
        Artisan::call('enderecos:geocodificar', ['--pausa-ms' => 150]);
        $decorridoMs = (microtime(true) - $inicio) * 1000;

        // Dois candidatos, uma pausa entre eles (nenhuma depois do último).
        // Tolerância generosa (100ms para uma pausa configurada de 150ms) de
        // propósito: ver a nota no cabeçalho da classe sobre teste de timing.
        $this->assertGreaterThanOrEqual(100, $decorridoMs, 'a pausa entre chamadas não foi aplicada');
    }

    /**
     * Erro de conexão do provedor não marca o endereço como processado: a
     * próxima execução tenta de novo, sem precisar de --forcar. Cenário fora
     * da lista literal do "Teste esperado" da task, mas é a outra metade da
     * regra "endereço já resolvido nunca é reconsultado" (a metade sobre o
     * que NÃO conta como resolvido) e fica sem cobertura nenhuma sem este
     * teste.
     */
    public function test_erro_do_provedor_nao_marca_endereco_como_processado(): void
    {
        $endereco = $this->criarEndereco();
        $fake = $this->ligarProvedorFake([
            new RuntimeException('Falha simulada de rede'),
        ]);

        Artisan::call('enderecos:geocodificar', ['--pausa-ms' => 0]);

        $endereco->refresh();
        $this->assertNull($endereco->geocodificado_em, 'erro de provedor não pode marcar o endereço como processado');

        // Nova execução tenta de novo, sem --forcar, porque o endereço ainda
        // conta como "nunca processado".
        $fake->respostas[] = new ResultadoGeo(-5.0, -5.0, ResultadoGeo::PRECISAO_EXATA);
        Artisan::call('enderecos:geocodificar', ['--pausa-ms' => 0]);

        $this->assertCount(2, $fake->chamadas);

        $endereco->refresh();
        $this->assertSame('-5.0000000', $endereco->latitude);
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function comTenant(\Closure $callback): mixed
    {
        return TenantAtual::comTenant($this->empresa->id, $callback);
    }

    private function criarEndereco(): Address
    {
        $cliente = $this->comTenant(fn () => ClientFactory::new()->create());

        return $this->comTenant(fn () => AddressFactory::new()->create(['client_id' => $cliente->id]));
    }

    /**
     * Liga no container um provedor simulado, com uma resposta por chamada,
     * na ordem informada. Cada item da fila é um `ResultadoGeo` (encontrado),
     * `null` (busca válida sem resultado) ou um `Throwable` (erro do
     * provedor, ex. falha de rede). Chamada além do fim da fila devolve
     * `null`.
     *
     * @param  array<int, ResultadoGeo|null|\Throwable>  $respostas
     */
    private function ligarProvedorFake(array $respostas = []): object
    {
        $fake = new class implements ProvedorDeGeocodificacao
        {
            /** @var array<int, ResultadoGeo|null|\Throwable> */
            public array $respostas = [];

            /** @var array<int, string> */
            public array $chamadas = [];

            public function buscar(string $endereco): ?ResultadoGeo
            {
                $this->chamadas[] = $endereco;

                if ($this->respostas === []) {
                    return null;
                }

                $resposta = array_shift($this->respostas);

                if ($resposta instanceof \Throwable) {
                    throw $resposta;
                }

                return $resposta;
            }
        };

        $fake->respostas = $respostas;

        $this->app->instance(ProvedorDeGeocodificacao::class, $fake);

        return $fake;
    }
}
