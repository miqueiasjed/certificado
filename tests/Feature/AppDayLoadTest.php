<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\BaitType;
use App\Models\Client;
use App\Models\Company;
use App\Models\Device;
use App\Models\Room;
use App\Models\Service;
use App\Models\Technician;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\BusinessDate;
use App\Support\TenantAtual;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 12.3 do Plano 12: carga do dia do aplicativo do técnico.
 *
 * Cobre o período padrão (hoje até 3 dias à frente no fuso do negócio), a
 * estrutura de cada ordem de serviço, o filtro incremental por `desde` com a
 * lista de removidos, o escopo por técnico, o isolamento entre empresas e a
 * ausência de qualquer campo financeiro no retorno.
 */
class AppDayLoadTest extends TestCase
{
    use RefreshDatabase;

    private const SENHA = 'senha-do-tecnico-123';

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();

        $this->seed(RolesAndPermissionsSeeder::class);

        // Fixa "hoje" no fuso do negócio para o teste não depender do
        // instante real de execução.
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-07-28 10:00:00', BusinessDate::fuso()));
    }

    protected function tearDown(): void
    {
        \Carbon\Carbon::setTestNow();
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Período padrão
    // -----------------------------------------------------------------

    public function test_carga_devolve_ordens_do_tecnico_de_hoje_ate_tres_dias_a_frente(): void
    {
        [$empresa, $usuario, $tecnico] = $this->criarEmpresaComTecnico('Periodo');

        $token = $this->logar($usuario, 'Aparelho do período');

        TenantAtual::comTenant($empresa->id, function () use ($tecnico) {
            $this->criarOrdemCompleta('DentroHoje', $tecnico, '2026-07-28');
            $this->criarOrdemCompleta('DentroLimite', $tecnico, '2026-07-31');
            $this->criarOrdemCompleta('ForaDepois', $tecnico, '2026-08-01');
            $this->criarOrdemCompleta('ForaAntes', $tecnico, '2026-07-27');
        });

        $resposta = $this->withToken($token)->getJson('/api/app/carga');

        $resposta->assertOk();

        $numeros = collect($resposta->json('ordens'))->pluck('numero')->all();

        $this->assertContains('OS-DentroHoje', $numeros);
        $this->assertContains('OS-DentroLimite', $numeros);
        $this->assertNotContains('OS-ForaDepois', $numeros);
        $this->assertNotContains('OS-ForaAntes', $numeros);

        $this->assertSame('2026-07-28', $resposta->json('periodo.de'));
        $this->assertSame('2026-07-31', $resposta->json('periodo.ate'));
    }

    /**
     * Task 12.12: fixa o instante em 22h de Brasília, e não nos 10h usados no
     * teste acima. América/São_Paulo é UTC-3 o ano inteiro desde o fim do
     * horário de verão em 2019, então 22h de Brasília já é 01h do dia
     * seguinte em UTC. Se o código algum dia usar `now()`/`Carbon::now()` cru
     * (calendário UTC) em vez de `BusinessDate::hoje()`, a carga passaria a
     * entregar 29/07 como "hoje", quando em Brasília ainda é 28/07. O teste
     * das 10h não expõe esse bug porque, às 10h, o dia em UTC e o dia em
     * Brasília coincidem.
     */
    public function test_a_carga_as_22h_de_brasilia_usa_o_dia_de_brasilia_e_nao_o_dia_utc(): void
    {
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-07-28 22:00:00', BusinessDate::fuso()));

        [$empresa, $usuario, $tecnico] = $this->criarEmpresaComTecnico('Fuso22h');

        $token = $this->logar($usuario, 'Aparelho das 22h de Brasília');

        TenantAtual::comTenant($empresa->id, function () use ($tecnico) {
            $this->criarOrdemCompleta('DoDiaDeBrasilia', $tecnico, '2026-07-28');
            $this->criarOrdemCompleta('DoDiaAnterior', $tecnico, '2026-07-27');
        });

        $resposta = $this->withToken($token)->getJson('/api/app/carga');

        $resposta->assertOk();

        $this->assertSame(
            '2026-07-28',
            $resposta->json('periodo.de'),
            'às 22h de Brasília o dia de início deveria ser 28/07 (o dia de Brasília), e não 29/07 (o dia em UTC no mesmo instante)'
        );
        $this->assertSame('2026-07-31', $resposta->json('periodo.ate'));

        $numeros = collect($resposta->json('ordens'))->pluck('numero')->all();

        $this->assertContains(
            'OS-DoDiaDeBrasilia',
            $numeros,
            'a OS agendada para o dia de Brasília (28/07) deveria entrar na carga feita às 22h de Brasília'
        );
        $this->assertNotContains(
            'OS-DoDiaAnterior',
            $numeros,
            'a OS do dia anterior ao de Brasília não deveria entrar na carga'
        );
    }

    // -----------------------------------------------------------------
    // Estrutura de cada ordem
    // -----------------------------------------------------------------

    public function test_ordem_traz_endereco_comodos_dispositivos_com_codigo_publico_servicos_e_produtos(): void
    {
        [$empresa, $usuario, $tecnico] = $this->criarEmpresaComTecnico('Estrutura');

        $token = $this->logar($usuario, 'Aparelho da estrutura');

        TenantAtual::comTenant($empresa->id, function () use ($tecnico) {
            $this->criarOrdemCompleta('Estrutura', $tecnico, '2026-07-28');
        });

        $resposta = $this->withToken($token)->getJson('/api/app/carga');

        $resposta->assertOk();

        $ordem = collect($resposta->json('ordens'))->firstWhere('numero', 'OS-Estrutura');

        $this->assertNotNull($ordem);
        $this->assertSame('Cliente Estrutura', $ordem['cliente']['nome']);
        $this->assertSame('Unidade Estrutura', $ordem['endereco']['apelido']);
        $this->assertCount(1, $ordem['comodos']);
        $this->assertSame('Comodo Estrutura', $ordem['comodos'][0]['nome']);
        $this->assertCount(1, $ordem['dispositivos']);
        $this->assertNotEmpty($ordem['dispositivos'][0]['codigo_publico']);
        $this->assertCount(1, $ordem['servicos']);
        $this->assertSame('Servico Estrutura', $ordem['servicos'][0]['nome']);
        $this->assertCount(1, $ordem['produtos_previstos']);
        $this->assertSame('Produto Estrutura', $ordem['produtos_previstos'][0]['nome']);
    }

    // -----------------------------------------------------------------
    // Filtro incremental por "desde"
    // -----------------------------------------------------------------

    public function test_desde_reduz_o_retorno_ao_que_mudou_e_lista_os_removidos(): void
    {
        [$empresa, $usuario, $tecnico] = $this->criarEmpresaComTecnico('Incremental');

        $token = $this->logar($usuario, 'Aparelho incremental');

        [$ordemAlterada, $ordemRemovida] = TenantAtual::comTenant($empresa->id, function () use ($tecnico) {
            return [
                $this->criarOrdemCompleta('Alterada', $tecnico, '2026-07-28'),
                $this->criarOrdemCompleta('Removida', $tecnico, '2026-07-28'),
            ];
        });

        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-07-28 12:00:00', BusinessDate::fuso()));
        $marcoDesde = \Carbon\Carbon::now()->timestamp;

        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-07-28 13:00:00', BusinessDate::fuso()));

        TenantAtual::comTenant($empresa->id, function () use ($ordemAlterada, $ordemRemovida) {
            $ordemAlterada->update(['description' => 'Descricao atualizada depois do marco']);
            $ordemRemovida->delete();
        });

        $resposta = $this->withToken($token)->getJson('/api/app/carga?desde='.$marcoDesde);

        $resposta->assertOk();

        $numeros = collect($resposta->json('ordens'))->pluck('numero')->all();

        $this->assertContains('OS-Alterada', $numeros);
        $this->assertNotContains('OS-Removida', $numeros);
        $this->assertContains($ordemRemovida->id, $resposta->json('removidos.ordens'));
    }

    // -----------------------------------------------------------------
    // Escopo por técnico
    // -----------------------------------------------------------------

    public function test_tecnico_nao_recebe_ordem_de_outro_tecnico(): void
    {
        [$empresa, $usuarioUm, $tecnicoUm] = $this->criarEmpresaComTecnico('TecUm');
        [, , $tecnicoDois] = $this->criarEmpresaComTecnico('TecDois', empresaExistente: $empresa);

        $token = $this->logar($usuarioUm, 'Aparelho do técnico um');

        TenantAtual::comTenant($empresa->id, function () use ($tecnicoUm, $tecnicoDois) {
            $this->criarOrdemCompleta('DoTecnicoUm', $tecnicoUm, '2026-07-28');
            $this->criarOrdemCompleta('DoTecnicoDois', $tecnicoDois, '2026-07-28');
        });

        $resposta = $this->withToken($token)->getJson('/api/app/carga');

        $resposta->assertOk();

        $numeros = collect($resposta->json('ordens'))->pluck('numero')->all();

        $this->assertContains('OS-DoTecnicoUm', $numeros);
        $this->assertNotContains('OS-DoTecnicoDois', $numeros);
    }

    // -----------------------------------------------------------------
    // Isolamento entre empresas
    // -----------------------------------------------------------------

    public function test_token_de_um_tenant_nao_recebe_nada_da_outra_empresa(): void
    {
        [$empresaUm, $usuarioUm, $tecnicoUm] = $this->criarEmpresaComTecnico('TenantUm');
        [$empresaDois, , $tecnicoDois] = $this->criarEmpresaComTecnico('TenantDois');

        $token = $this->logar($usuarioUm, 'Aparelho do tenant um');

        TenantAtual::comTenant($empresaUm->id, function () use ($tecnicoUm) {
            $this->criarOrdemCompleta('EmpresaUm', $tecnicoUm, '2026-07-28');
        });

        TenantAtual::comTenant($empresaDois->id, function () use ($tecnicoDois) {
            $this->criarOrdemCompleta('EmpresaDois', $tecnicoDois, '2026-07-28');
        });

        $resposta = $this->withToken($token)->getJson('/api/app/carga');

        $resposta->assertOk();

        $numeros = collect($resposta->json('ordens'))->pluck('numero')->all();

        $this->assertContains('OS-EmpresaUm', $numeros);
        $this->assertNotContains('OS-EmpresaDois', $numeros);
        $this->assertSame($empresaUm->id, $resposta->json('empresa.id'));
    }

    // -----------------------------------------------------------------
    // Nenhum campo financeiro
    // -----------------------------------------------------------------

    public function test_nenhum_campo_de_valor_custo_ou_margem_aparece_no_retorno(): void
    {
        [$empresa, $usuario, $tecnico] = $this->criarEmpresaComTecnico('Financeiro');

        $token = $this->logar($usuario, 'Aparelho financeiro');

        TenantAtual::comTenant($empresa->id, function () use ($tecnico) {
            $this->criarOrdemCompleta('Financeiro', $tecnico, '2026-07-28');
        });

        $resposta = $this->withToken($token)->getJson('/api/app/carga');

        $resposta->assertOk();

        $corpo = mb_strtolower($resposta->getContent());

        $camposFinanceiros = [
            'total_cost', 'discount_amount', 'final_amount', 'payment_status',
            'total_paid', 'remaining_amount', 'effective_amount',
            'valor', 'custo', 'margem',
        ];

        foreach ($camposFinanceiros as $campoFinanceiro) {
            $this->assertStringNotContainsString($campoFinanceiro, $corpo, "o campo financeiro \"{$campoFinanceiro}\" não deveria aparecer na carga do aplicativo");
        }

        // Task 12.12: a asserção acima é textual, sobre o corpo bruto, e
        // passaria mesmo se o campo proibido estivesse escondido dentro de um
        // valor de string (por exemplo uma observação que mencionasse
        // "valor" em português comum). A asserção abaixo é estrutural: decodifica
        // o JSON e percorre toda chave, em qualquer nível de aninhamento,
        // exigindo ausência exata das chaves proibidas como CHAVE de algum
        // nó da árvore, não como substring.
        $chaves = $this->todasAsChavesDoJson($resposta->json());

        foreach ($camposFinanceiros as $campoFinanceiro) {
            $this->assertNotContains(
                $campoFinanceiro,
                $chaves,
                "a chave financeira \"{$campoFinanceiro}\" apareceu em algum nível do JSON da carga do aplicativo"
            );
        }
    }

    /**
     * Percorre recursivamente uma estrutura decodificada de JSON (arrays
     * associativos e sequenciais, aninhados em qualquer profundidade) e
     * devolve toda chave de string encontrada, em minúsculas. Usada para
     * afirmar por ausência de chave, e não por busca de substring no corpo
     * bruto da resposta.
     *
     * @return array<int, string>
     */
    private function todasAsChavesDoJson(mixed $dado): array
    {
        $chaves = [];

        if (is_array($dado)) {
            foreach ($dado as $chave => $valor) {
                if (is_string($chave)) {
                    $chaves[] = mb_strtolower($chave);
                }

                $chaves = array_merge($chaves, $this->todasAsChavesDoJson($valor));
            }
        }

        return $chaves;
    }

    // -----------------------------------------------------------------
    // Volume
    // -----------------------------------------------------------------

    /**
     * Verificação de desempenho aproximada: 200 ordens completas (com
     * cliente, endereço, cômodo, dispositivo, serviço e produto cada) mais o
     * limite de paginação. O critério de aceitação fala em banco "com volume
     * real"; aqui o volume é o suficiente para expor uma consulta N+1, que é
     * o risco concreto desta task, sem depender de infraestrutura de teste de
     * carga.
     */
    public function test_carga_com_muitas_ordens_responde_rapido_e_sinaliza_que_ha_mais(): void
    {
        [$empresa, $usuario, $tecnico] = $this->criarEmpresaComTecnico('Volume');

        $token = $this->logar($usuario, 'Aparelho de volume');

        TenantAtual::comTenant($empresa->id, function () use ($tecnico) {
            for ($i = 1; $i <= 205; $i++) {
                $this->criarOrdemCompleta('Volume'.$i, $tecnico, '2026-07-28');
            }
        });

        $inicio = microtime(true);
        $resposta = $this->withToken($token)->getJson('/api/app/carga');
        $duracao = microtime(true) - $inicio;

        $resposta->assertOk();

        $this->assertCount(200, $resposta->json('ordens'));
        $this->assertTrue($resposta->json('ordens_tem_mais'));
        $this->assertLessThan(2.0, $duracao, 'a carga do dia com 200 ordens deveria responder em menos de 2 segundos');
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * @return array{0: Company, 1: User, 2: Technician}
     */
    private function criarEmpresaComTecnico(string $marca, ?Company $empresaExistente = null): array
    {
        $empresa = $empresaExistente ?? Company::create(['name' => 'Empresa '.$marca]);

        [$usuario, $tecnico] = TenantAtual::comTenant($empresa->id, function () use ($marca) {
            $usuario = User::factory()->create([
                'name' => 'Tecnico '.$marca,
                'email' => 'tecnico-'.strtolower($marca).'-'.uniqid().'@exemplo.test',
                'password' => self::SENHA,
                'is_active' => true,
            ]);

            $usuario->assignRole('tecnico');

            $tecnico = Technician::create([
                'name' => 'Cadastro Tecnico '.$marca,
                'email' => 'cadastro-'.strtolower($marca).'-'.uniqid().'@exemplo.test',
                'phone' => '11999990000',
                'specialty' => 'Controle de pragas',
                'is_active' => true,
                'user_id' => $usuario->id,
            ]);

            return [$usuario, $tecnico];
        });

        return [$empresa, $usuario->fresh(), $tecnico];
    }

    private static int $contadorDeCnpj = 0;

    private function cnpjUnico(): string
    {
        self::$contadorDeCnpj++;

        return '33.333.333/0001-'.str_pad((string) self::$contadorDeCnpj, 2, '0', STR_PAD_LEFT);
    }

    private function logar(User $usuario, string $dispositivo): string
    {
        $resposta = $this->postJson('/api/app/login', [
            'email' => $usuario->email,
            'password' => self::SENHA,
            'dispositivo' => $dispositivo,
        ]);

        $resposta->assertOk();

        return (string) $resposta->json('token');
    }

    /**
     * Ordem de serviço completa (cliente, endereço, cômodo, dispositivo,
     * serviço e produto próprios da marca), atribuída ao técnico informado,
     * dentro da empresa corrente.
     */
    private function criarOrdemCompleta(string $marca, Technician $tecnico, string $dataAgendada): WorkOrder
    {
        $baixo = strtolower($marca);

        $cliente = Client::create([
            'name' => 'Cliente '.$marca,
            'email' => 'cliente-'.$baixo.'-'.uniqid().'@exemplo.test',
            'phone' => '11912340000',
            'cnpj' => $this->cnpjUnico(),
        ]);

        $endereco = Address::create([
            'client_id' => $cliente->id,
            'nickname' => 'Unidade '.$marca,
            'street' => 'Rua '.$marca,
            'number' => '100',
            'district' => 'Bairro '.$marca,
            'city' => 'Cidade '.$marca,
            'state' => 'SP',
            'zip' => '01000-000',
            'active' => true,
        ]);

        $comodo = Room::create([
            'address_id' => $endereco->id,
            'name' => 'Comodo '.$marca,
            'active' => true,
        ]);

        $tipoDeIsca = BaitType::create([
            'name' => 'Isca '.$marca.'-'.uniqid(),
            'brand' => 'Marca '.$marca,
        ]);

        $dispositivo = Device::create([
            'address_id' => $endereco->id,
            'bait_type_id' => $tipoDeIsca->id,
            'label' => 'Dispositivo '.$marca,
            'number' => 'DISP-'.$marca.'-'.uniqid(),
            'active' => true,
        ]);

        $servico = Service::create([
            'name' => 'Servico '.$marca,
            'description' => 'Descricao do servico '.$marca,
            'price' => 300,
            'is_active' => true,
        ]);

        $principioAtivo = \App\Models\ActiveIngredient::create(['name' => 'Principio '.$marca.'-'.uniqid()]);
        $grupoQuimico = \App\Models\ChemicalGroup::create(['name' => 'Grupo '.$marca.'-'.uniqid()]);
        $antidoto = \App\Models\Antidote::create(['name' => 'Antidoto '.$marca.'-'.uniqid()]);
        $registroOrgao = \App\Models\OrganRegistration::create(['record' => 'REGISTRO-'.$marca.'-'.uniqid()]);

        $produto = \App\Models\Product::create([
            'name' => 'Produto '.$marca,
            'active_ingredient_id' => $principioAtivo->id,
            'chemical_group_id' => $grupoQuimico->id,
            'antidote_id' => $antidoto->id,
            'organ_registration_id' => $registroOrgao->id,
        ]);

        $ordem = WorkOrder::create([
            'order_number' => 'OS-'.$marca,
            'client_id' => $cliente->id,
            'address_id' => $endereco->id,
            'technician_id' => $tecnico->id,
            'scheduled_date' => $dataAgendada,
            'status' => 'scheduled',
            'description' => 'Ordem de trabalho '.$marca,
            'total_cost' => 500,
            'discount_amount' => 0,
            'final_amount' => 500,
            'payment_status' => 'pending',
            'active' => true,
        ]);

        $ordem->rooms()->attach($comodo->id);
        $ordem->devices()->attach($dispositivo->id);
        $ordem->services()->attach($servico->id);
        $ordem->products()->attach($produto->id, ['quantity' => 1, 'unit' => 'un']);

        return $ordem;
    }
}
