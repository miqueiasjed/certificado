<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\FiscalConfig;
use App\Models\Receivable;
use App\Models\ServiceInvoice;
use App\Models\WorkOrder;
use App\Support\TenantAtual;
use Database\Factories\ClientFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class FiscalModelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();

        parent::tearDown();
    }

    public function test_configuracao_cifra_credenciais_e_nasce_sem_emissao_automatica(): void
    {
        $empresa = Company::create(['name' => 'Empresa Fiscal']);
        $credenciais = [
            'token' => 'segredo-fiscal-que-nao-pode-vazar',
            'certificado' => 'conteudo-pfx',
        ];

        $configuracao = $this->comTenant($empresa, fn () => FiscalConfig::create([
            'provedor' => 'provedor-teste',
            'credenciais' => $credenciais,
            'regime_tributario' => 'simples_nacional',
            'codigo_servico' => '07.13',
            'aliquota_iss' => '5.00',
            'iss_retido' => 0,
            'natureza_operacao' => 'tributacao_no_municipio',
        ]))->refresh();

        $valorNoBanco = DB::table('fiscal_configs')
            ->where('id', $configuracao->id)
            ->value('credenciais');

        $this->assertSame($empresa->id, (int) $configuracao->company_id);
        $this->assertSame($credenciais, $configuracao->credenciais);
        $this->assertNotSame(json_encode($credenciais), $valorNoBanco);
        $this->assertStringNotContainsString($credenciais['token'], $valorNoBanco);
        $this->assertFalse($configuracao->emissao_automatica);
        $this->assertFalse((bool) DB::table('fiscal_configs')->where('id', $configuracao->id)->value('emissao_automatica'));
        $this->assertFalse($configuracao->iss_retido);
        $this->assertFalse($configuracao->ativo);
        $this->assertSame('5.00', $configuracao->aliquota_iss);
        $this->assertTrue($configuracao->company->is($empresa));
        $this->assertArrayNotHasKey('credenciais', $configuracao->toArray());
    }

    public function test_nota_aplica_casts_e_expoe_todas_as_relacoes(): void
    {
        $empresa = Company::create(['name' => 'Empresa das Relações']);

        $dados = $this->comTenant($empresa, function () {
            $cliente = ClientFactory::new()->create();
            $ordem = WorkOrder::factory()->create(['client_id' => $cliente->id]);
            $titulo = Receivable::create([
                'client_id' => $cliente->id,
                'work_order_id' => $ordem->id,
                'descricao' => 'Serviço prestado',
                'valor_total' => '250.00',
                'emitido_em' => '2026-08-01',
                'situacao' => 'aberto',
            ]);

            $substituta = $this->criarNota($cliente, [
                'numero' => '102',
                'situacao' => 'emitida',
            ]);

            $original = $this->criarNota($cliente, [
                'work_order_id' => $ordem->id,
                'receivable_id' => $titulo->id,
                'numero' => '101',
                'situacao' => 'substituida',
                'substituida_por_id' => $substituta->id,
                'emitida_em' => '2026-08-01 15:30:00',
                'cancelada_em' => '2026-08-02 10:20:00',
                'tentativas' => '2',
            ]);

            return compact('cliente', 'ordem', 'titulo', 'substituta', 'original');
        });

        /** @var ServiceInvoice $original */
        $original = $dados['original']->fresh();

        $this->assertSame('250.00', $original->valor_servico);
        $this->assertSame('12.50', $original->valor_iss);
        $this->assertSame('237.50', $original->valor_liquido);
        $this->assertSame(2, $original->tentativas);
        $this->assertInstanceOf(Carbon::class, $original->competencia);
        $this->assertSame('2026-08-01', $original->competencia->toDateString());
        $this->assertInstanceOf(Carbon::class, $original->emitida_em);
        $this->assertInstanceOf(Carbon::class, $original->cancelada_em);
        $this->assertTrue($original->company->is($empresa));
        $this->assertTrue($original->client->is($dados['cliente']));
        $this->assertInstanceOf(FiscalConfig::class, $original->fiscalConfig);
        $this->assertTrue($original->workOrder->is($dados['ordem']));
        $this->assertTrue($original->receivable->is($dados['titulo']));
        $this->assertTrue($original->substituidaPor->is($dados['substituta']));
        $this->assertTrue($dados['substituta']->notaSubstituida->is($original));
    }

    public function test_cliente_continua_salvando_com_ou_sem_dados_fiscais(): void
    {
        $empresa = Company::create(['name' => 'Empresa de Clientes']);

        [$clienteComDados, $clienteSemDados] = $this->comTenant($empresa, function () {
            $clienteComDados = Client::create([
                'name' => 'Cliente com dados fiscais',
                'email' => 'fiscal@cliente.test',
                'phone' => '11999999999',
                'cnpj' => '11.111.111/0001-11',
                'inscricao_municipal' => '123456',
                'inscricao_estadual' => 'ISENTO',
                'codigo_municipio_ibge' => '3550308',
                'regime_tributario' => 'simples_nacional',
                'email_nfe' => 'notas@cliente.test',
            ]);

            return [$clienteComDados, ClientFactory::new()->create()];
        });

        $this->assertSame('123456', $clienteComDados->inscricao_municipal);
        $this->assertSame('ISENTO', $clienteComDados->inscricao_estadual);
        $this->assertSame('3550308', $clienteComDados->codigo_municipio_ibge);
        $this->assertSame('simples_nacional', $clienteComDados->regime_tributario);
        $this->assertSame('notas@cliente.test', $clienteComDados->email_nfe);
        $this->assertNull($clienteSemDados->inscricao_municipal);
        $this->assertNull($clienteSemDados->email_nfe);
    }

    public function test_nota_nao_pode_ser_excluida_pelo_model(): void
    {
        $empresa = Company::create(['name' => 'Empresa Imutável']);

        $nota = $this->comTenant($empresa, function () {
            $cliente = ClientFactory::new()->create();

            return $this->criarNota($cliente);
        });

        try {
            $this->comTenant($empresa, fn () => $nota->delete());
            $this->fail('A exclusão da nota deveria ter sido bloqueada.');
        } catch (RuntimeException $excecao) {
            $this->assertSame('Nota fiscal de serviço não pode ser excluída.', $excecao->getMessage());
        }

        $this->assertTrue(
            $this->comTenant($empresa, fn () => ServiceInvoice::query()->whereKey($nota->id)->exists())
        );
    }

    public function test_nota_nao_pode_ser_excluida_pelo_query_builder(): void
    {
        $empresa = Company::create(['name' => 'Empresa com Exclusão em Massa']);

        $nota = $this->comTenant($empresa, function () {
            $cliente = ClientFactory::new()->create();

            return $this->criarNota($cliente);
        });

        try {
            $this->comTenant(
                $empresa,
                fn () => ServiceInvoice::query()->whereKey($nota->id)->delete()
            );
            $this->fail('A exclusão da nota pelo query builder deveria ter sido bloqueada.');
        } catch (QueryException $excecao) {
            $this->assertStringContainsString(
                'Nota fiscal de serviço não pode ser excluída.',
                $excecao->getMessage()
            );
        }

        $this->assertTrue(
            $this->comTenant($empresa, fn () => ServiceInvoice::query()->whereKey($nota->id)->exists())
        );
    }

    public function test_configuracoes_e_notas_ficam_isoladas_por_empresa(): void
    {
        $empresaUm = Company::create(['name' => 'Empresa Um']);
        $empresaDois = Company::create(['name' => 'Empresa Dois']);

        $configUm = $this->criarConfiguracao($empresaUm, 'token-um');
        $configDois = $this->criarConfiguracao($empresaDois, 'token-dois');
        $notaUm = $this->criarNotaDaEmpresa($empresaUm, 'Nota Um');
        $notaDois = $this->criarNotaDaEmpresa($empresaDois, 'Nota Dois');

        $this->comTenant($empresaUm, function () use ($configUm, $configDois, $notaUm, $notaDois): void {
            $this->assertSame([$configUm->id], FiscalConfig::query()->pluck('id')->all());
            $this->assertNull(FiscalConfig::query()->find($configDois->id));
            $this->assertSame([$notaUm->id], ServiceInvoice::query()->pluck('id')->all());
            $this->assertNull(ServiceInvoice::query()->find($notaDois->id));
        });

        $this->comTenant($empresaDois, function () use ($configDois, $notaDois): void {
            $this->assertSame([$configDois->id], FiscalConfig::query()->pluck('id')->all());
            $this->assertSame([$notaDois->id], ServiceInvoice::query()->pluck('id')->all());
        });
    }

    private function criarConfiguracao(Company $empresa, string $token): FiscalConfig
    {
        return $this->comTenant($empresa, fn () => FiscalConfig::create([
            'provedor' => 'provedor-teste',
            'credenciais' => ['token' => $token],
            'regime_tributario' => 'simples_nacional',
            'codigo_servico' => '07.13',
            'aliquota_iss' => '5.00',
            'natureza_operacao' => 'tributacao_no_municipio',
        ]));
    }

    private function criarNotaDaEmpresa(Company $empresa, string $descricao): ServiceInvoice
    {
        return $this->comTenant($empresa, function () use ($descricao) {
            $cliente = ClientFactory::new()->create();

            return $this->criarNota($cliente, ['descricao_servico' => $descricao]);
        });
    }

    private function criarNota(Client $cliente, array $sobrescritos = []): ServiceInvoice
    {
        $configuracao = FiscalConfig::query()->first() ?? FiscalConfig::create([
            'provedor' => 'provedor-teste',
            'credenciais' => ['token' => 'teste'],
            'regime_tributario' => 'simples_nacional',
            'codigo_servico' => '07.13',
            'aliquota_iss' => '5.00',
            'natureza_operacao' => 'tributacao_no_municipio',
        ]);

        return ServiceInvoice::create(array_merge([
            'fiscal_config_id' => $configuracao->id,
            'client_id' => $cliente->id,
            'situacao' => 'pendente',
            'valor_servico' => '250.00',
            'valor_iss' => '12.50',
            'valor_liquido' => '237.50',
            'descricao_servico' => 'Controle de pragas',
            'competencia' => '2026-08-01',
        ], $sobrescritos));
    }

    private function comTenant(Company $empresa, callable $acao): mixed
    {
        return TenantAtual::comTenant($empresa->id, $acao);
    }
}
