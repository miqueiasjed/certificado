<?php

namespace Tests\Feature;

use App\Console\Commands\SyncPermissions;
use App\Models\Address;
use App\Models\Company;
use App\Models\Compromisso;
use App\Models\Route;
use App\Models\Technician;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\AppDayLoadService;
use App\Services\Geo\ProvedorDeGeocodificacao;
use App\Support\BusinessDate;
use App\Support\Geo\ResultadoGeo;
use App\Support\TenantAtual;
use Carbon\Carbon;
use Database\Factories\AddressFactory;
use Database\Factories\ClientFactory;
use Database\Factories\CompromissoFactory;
use Database\Factories\WorkOrderFactory;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 22.5 do Plano 22: endpoints de roteiro, mapa e correção de coordenada.
 *
 * Cobre os oito critérios de aceitação da task: (a) o roteiro do dia é
 * montado e devolvido com a ordem, (b) otimizar recalcula e persiste, (c)
 * reordenação manual prevalece sobre uma nova busca e registra quem fez, (d)
 * o aplicativo recebe o roteiro na carga do dia, (e) técnico não acessa
 * roteiro de outro, (f) a lista de pendências de coordenada retorna os
 * endereços certos, (g) correção manual grava com origem `manual`, (h)
 * tenant sem o módulo vê a página de indisponível.
 */
class RouteEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const DATA_DO_ROTEIRO = '2026-08-10';

    private Company $empresa;

    private User $administrador;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ModulesSeeder::class);

        $this->empresa = Company::query()->firstOrFail();

        $this->administrador = TenantAtual::comTenant($this->empresa->id, function (): User {
            $admin = User::factory()->create(['is_active' => true]);
            $admin->assignRole('administrador');

            return $admin;
        });
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_catalogo_tem_as_tres_permissoes_novas(): void
    {
        $catalogo = collect(SyncPermissions::catalogo())->flatten()->all();

        $this->assertContains('roteiro-ver', $catalogo);
        $this->assertContains('roteiro-gerenciar', $catalogo);
        $this->assertContains('endereco-geo', $catalogo);
    }

    // -----------------------------------------------------------------
    // (a) O roteiro do dia é montado e devolvido com a ordem
    // -----------------------------------------------------------------

    public function test_roteiro_do_dia_e_montado_e_devolvido_com_a_ordem(): void
    {
        $tecnico = $this->criarTecnico('A');

        $perto = $this->criarOrdem($tecnico, 0.01, 0.0);
        $longe = $this->criarOrdem($tecnico, 0.05, 0.0);

        $resposta = $this->actingAs($this->administrador)->getJson(
            '/roteiros?data='.self::DATA_DO_ROTEIRO.'&technician_id='.$tecnico->id
        );

        $resposta->assertOk();

        $paradas = $resposta->json('paradas');
        $this->assertCount(2, $paradas);
        $this->assertSame([$perto->id, $longe->id], array_column($paradas, 'work_order_id'));
        $this->assertSame(1, $paradas[0]['ordem']);
        $this->assertSame(2, $paradas[1]['ordem']);
        $this->assertFalse($resposta->json('ordenacao_manual'));

        $this->assertDatabaseHas('routes', [
            'technician_id' => $tecnico->id,
            'data' => self::DATA_DO_ROTEIRO,
        ]);
    }

    // -----------------------------------------------------------------
    // (b) Otimizar recalcula e persiste
    // -----------------------------------------------------------------

    public function test_otimizar_recalcula_e_persiste_a_ordem(): void
    {
        $tecnico = $this->criarTecnico('B');

        $perto = $this->criarOrdem($tecnico, 0.01, 0.0);
        $meio = $this->criarOrdem($tecnico, 0.02, 0.0);
        $longe = $this->criarOrdem($tecnico, 0.03, 0.0);

        $resposta = $this->actingAs($this->administrador)->postJson('/roteiros/otimizar', [
            'data' => self::DATA_DO_ROTEIRO,
            'technician_id' => $tecnico->id,
        ]);

        $resposta->assertOk();
        $this->assertSame(
            [$perto->id, $meio->id, $longe->id],
            array_column($resposta->json('paradas'), 'work_order_id')
        );

        $rota = $this->comTenant(
            fn (): Route => Route::query()->where('technician_id', $tecnico->id)->firstOrFail()
        );
        $this->assertNotNull($rota->otimizada_em);
        $this->assertFalse((bool) $rota->ordenacao_manual);
    }

    // -----------------------------------------------------------------
    // (c) Reordenação manual prevalece e registra quem fez
    // -----------------------------------------------------------------

    public function test_reordenacao_manual_prevalece_sobre_nova_busca_e_registra_quem_fez(): void
    {
        $tecnico = $this->criarTecnico('C');

        $perto = $this->criarOrdem($tecnico, 0.01, 0.0);
        $meio = $this->criarOrdem($tecnico, 0.02, 0.0);
        $longe = $this->criarOrdem($tecnico, 0.03, 0.0);

        // Monta o roteiro (ordem geométrica esperada: perto, meio, longe).
        $montagem = $this->actingAs($this->administrador)->getJson(
            '/roteiros?data='.self::DATA_DO_ROTEIRO.'&technician_id='.$tecnico->id
        );
        $montagem->assertOk();
        $rotaId = $montagem->json('id');

        // Reordena manualmente na ordem inversa da geometria. Contrato da
        // Task 31.3: lista de strings `"os:{id}"`/`"compromisso:{id}"`, não
        // mais `work_order_ids: [int]` (sem período de transição entre os
        // dois formatos, ver `ReordenarRotaRequest`).
        $reordenacao = $this->actingAs($this->administrador)->putJson("/roteiros/{$rotaId}/ordem", [
            'paradas' => ["os:{$longe->id}", "os:{$meio->id}", "os:{$perto->id}"],
        ]);

        $reordenacao->assertOk();
        $this->assertSame(
            [$longe->id, $meio->id, $perto->id],
            array_column($reordenacao->json('paradas'), 'work_order_id')
        );
        $this->assertTrue($reordenacao->json('ordenacao_manual'));
        $this->assertSame($this->administrador->name, $reordenacao->json('reordenada_por'));
        $this->assertNotNull($reordenacao->json('reordenada_em'));

        $rota = $this->comTenant(fn (): Route => Route::query()->findOrFail($rotaId));
        $this->assertTrue((bool) $rota->ordenacao_manual);
        $this->assertSame($this->administrador->id, $rota->reordenada_por);
        $this->assertNotNull($rota->reordenada_em);

        // Busca de novo: a ordem manual continua, sem o otimizador ter
        // rodado por baixo - regra de negócio inegociável da Task 22.5.
        $novaBusca = $this->actingAs($this->administrador)->getJson(
            '/roteiros?data='.self::DATA_DO_ROTEIRO.'&technician_id='.$tecnico->id
        );
        $novaBusca->assertOk();
        $this->assertSame(
            [$longe->id, $meio->id, $perto->id],
            array_column($novaBusca->json('paradas'), 'work_order_id'),
            'a ordem manual precisa continuar depois de uma busca comum'
        );
        $this->assertTrue($novaBusca->json('ordenacao_manual'));

        // Só a reotimização explícita descarta a ordem manual.
        $reotimizacao = $this->actingAs($this->administrador)->postJson('/roteiros/otimizar', [
            'data' => self::DATA_DO_ROTEIRO,
            'technician_id' => $tecnico->id,
        ]);
        $reotimizacao->assertOk();
        $this->assertSame(
            [$perto->id, $meio->id, $longe->id],
            array_column($reotimizacao->json('paradas'), 'work_order_id'),
            'a reotimização explícita precisa voltar para a ordem geométrica'
        );
        $this->assertFalse($reotimizacao->json('ordenacao_manual'));
    }

    // -----------------------------------------------------------------
    // (d) O aplicativo recebe o roteiro na carga do dia
    // -----------------------------------------------------------------

    public function test_aplicativo_recebe_o_roteiro_na_carga_do_dia(): void
    {
        Carbon::setTestNow(Carbon::parse(self::DATA_DO_ROTEIRO.' 08:00:00', BusinessDate::fuso()));

        [$usuarioTecnico, $tecnico] = $this->criarTecnicoComUsuario('D');

        $ordem = $this->criarOrdem($tecnico, 0.01, 0.0);

        // Monta o roteiro do dia pelo endpoint real, não chamando o Service
        // direto - o que importa aqui é que a carga enxerga o que o endpoint
        // já gravou.
        $this->actingAs($this->administrador)->getJson(
            '/roteiros?data='.self::DATA_DO_ROTEIRO.'&technician_id='.$tecnico->id
        )->assertOk();

        $resultado = $this->comTenant(
            fn (): array => app(AppDayLoadService::class)->carregar($usuarioTecnico->fresh(), [])
        );

        $this->assertTrue($resultado['sucesso']);
        $roteiro = $resultado['dados']['roteiro'];

        $this->assertNotNull($roteiro, 'a carga do dia precisa trazer o roteiro do técnico');
        $this->assertSame(self::DATA_DO_ROTEIRO, $roteiro['data']);
        $this->assertCount(1, $roteiro['paradas']);
        $this->assertSame($ordem->id, $roteiro['paradas'][0]['work_order_id']);
    }

    // -----------------------------------------------------------------
    // (e) Técnico não acessa roteiro de outro
    // -----------------------------------------------------------------

    public function test_tecnico_nao_acessa_roteiro_de_outro(): void
    {
        [$usuarioTecnicoUm, $tecnicoUm] = $this->criarTecnicoComUsuario('E1');
        [, $tecnicoDois] = $this->criarTecnicoComUsuario('E2');

        $this->criarOrdem($tecnicoDois, 0.01, 0.0);

        $montagem = $this->actingAs($this->administrador)->getJson(
            '/roteiros?data='.self::DATA_DO_ROTEIRO.'&technician_id='.$tecnicoDois->id
        );
        $montagem->assertOk();
        $rotaDoTecnicoDois = $montagem->json('id');

        // O papel `tecnico` já tem `roteiro-ver`, então um 403 aqui só pode
        // vir da checagem de dono (`RouteController::garantirAcessoAoRoteiro()`),
        // nunca de falta de permissão.
        $this->actingAs($usuarioTecnicoUm)
            ->getJson("/roteiros/{$rotaDoTecnicoDois}/mapa")
            ->assertForbidden();

        // Uma busca do técnico 1 pedindo explicitamente o `technician_id` do
        // técnico 2 também não devolve nada do técnico 2: o parâmetro é
        // ignorado, e ele só enxerga o próprio roteiro.
        $buscaPropria = $this->actingAs($usuarioTecnicoUm)->getJson(
            '/roteiros?data='.self::DATA_DO_ROTEIRO.'&technician_id='.$tecnicoDois->id
        );
        $buscaPropria->assertOk();
        $this->assertSame($tecnicoUm->id, $buscaPropria->json('technician_id'));
    }

    // -----------------------------------------------------------------
    // (f) A lista de pendências de coordenada retorna os endereços certos
    // -----------------------------------------------------------------

    public function test_lista_de_pendencias_de_coordenada_retorna_os_enderecos_certos(): void
    {
        $cliente = $this->comTenant(fn () => ClientFactory::new()->create());

        $semCoordenada = $this->comTenant(
            fn (): Address => AddressFactory::new()->create(['client_id' => $cliente->id])
        );

        $comPrecisaoCidade = $this->comTenant(function () use ($cliente): Address {
            $endereco = AddressFactory::new()->create(['client_id' => $cliente->id]);
            $endereco->forceFill([
                'latitude' => -23.0,
                'longitude' => -46.0,
                'precisao_geocodificacao' => ResultadoGeo::PRECISAO_CIDADE,
                'origem_coordenada' => Address::ORIGEM_COORDENADA_AUTOMATICA,
                'geocodificado_em' => now(),
            ])->save();

            return $endereco;
        });

        $comBoaCoordenada = $this->comTenant(function () use ($cliente): Address {
            $endereco = AddressFactory::new()->create(['client_id' => $cliente->id]);
            $endereco->forceFill([
                'latitude' => -23.5,
                'longitude' => -46.5,
                'precisao_geocodificacao' => ResultadoGeo::PRECISAO_EXATA,
                'origem_coordenada' => Address::ORIGEM_COORDENADA_AUTOMATICA,
                'geocodificado_em' => now(),
            ])->save();

            return $endereco;
        });

        $resposta = $this->actingAs($this->administrador)->getJson('/enderecos/pendencias-geo');

        $resposta->assertOk();
        $ids = collect($resposta->json('data'))->pluck('id')->all();

        $this->assertContains($semCoordenada->id, $ids);
        $this->assertContains($comPrecisaoCidade->id, $ids);
        $this->assertNotContains($comBoaCoordenada->id, $ids);
    }

    // -----------------------------------------------------------------
    // (g) Correção manual grava com origem `manual`
    // -----------------------------------------------------------------

    public function test_correcao_manual_de_coordenada_grava_origem_manual(): void
    {
        $endereco = $this->comTenant(function (): Address {
            $cliente = ClientFactory::new()->create();
            $endereco = AddressFactory::new()->create(['client_id' => $cliente->id]);
            $endereco->forceFill([
                'latitude' => -23.0,
                'longitude' => -46.0,
                'precisao_geocodificacao' => ResultadoGeo::PRECISAO_APROXIMADA,
                'origem_coordenada' => Address::ORIGEM_COORDENADA_AUTOMATICA,
                'geocodificado_em' => now(),
            ])->save();

            return $endereco;
        });

        $resposta = $this->actingAs($this->administrador)->putJson("/enderecos/{$endereco->id}/coordenada", [
            'latitude' => -23.55,
            'longitude' => -46.63,
        ]);

        $resposta->assertOk();

        $endereco = $this->comTenant(fn (): Address => $endereco->fresh());
        $this->assertSame(Address::ORIGEM_COORDENADA_MANUAL, $endereco->origem_coordenada);
        $this->assertEqualsWithDelta(-23.55, (float) $endereco->latitude, 0.0001);
        $this->assertEqualsWithDelta(-46.63, (float) $endereco->longitude, 0.0001);
        $this->assertSame(ResultadoGeo::PRECISAO_EXATA, $endereco->precisao_geocodificacao);

        // `POST /geocodificar` sempre força, mesmo por cima de uma correção
        // manual: é uma ação explícita do usuário sobre este endereço
        // específico (diferente do comando em lote da Task 22.2).
        $fake = $this->ligarProvedorFake([
            new ResultadoGeo(-23.6, -46.7, ResultadoGeo::PRECISAO_APROXIMADA),
        ]);

        $geocodificacao = $this->actingAs($this->administrador)->postJson("/enderecos/{$endereco->id}/geocodificar");
        $geocodificacao->assertOk();
        $this->assertCount(1, $fake->chamadas, 'a correção manual anterior não pode impedir a nova tentativa forçada');

        $endereco = $this->comTenant(fn (): Address => $endereco->fresh());
        $this->assertSame(Address::ORIGEM_COORDENADA_AUTOMATICA, $endereco->origem_coordenada);
        $this->assertEqualsWithDelta(-23.6, (float) $endereco->latitude, 0.0001);
    }

    // -----------------------------------------------------------------
    // (h) Tenant sem o módulo vê a página de indisponível
    // -----------------------------------------------------------------

    public function test_tenant_sem_o_modulo_roteirizacao_ve_pagina_de_indisponivel(): void
    {
        $empresaSemModulo = Company::create([
            'name' => 'Empresa Sem Roteirizacao',
            'cnpj' => '77.777.777/0001-77',
            'email' => 'contato@sem-roteirizacao.test',
        ]);

        $adminSemModulo = TenantAtual::comTenant($empresaSemModulo->id, function (): User {
            $usuario = User::factory()->create(['is_active' => true]);
            $usuario->assignRole('administrador');

            return $usuario;
        });

        $paginaHtml = $this->actingAs($adminSemModulo)->get('/roteiros?data='.self::DATA_DO_ROTEIRO);
        $paginaHtml->assertRedirect(route('modulo-indisponivel', ['modulo' => 'roteirizacao']));

        $respostaJson = $this->actingAs($adminSemModulo)->getJson('/enderecos/pendencias-geo');
        $respostaJson->assertStatus(403);
        $respostaJson->assertJsonPath('modulo', 'roteirizacao');
    }

    // -----------------------------------------------------------------
    // (i) OS e compromisso avulso juntos no roteiro (Plano 31, Task 31.5)
    // -----------------------------------------------------------------

    public function test_roteiro_do_dia_com_os_e_compromisso_devolve_as_duas_paradas_com_tipo_item_correto(): void
    {
        $tecnico = $this->criarTecnico('MistoI1');

        $ordem = $this->criarOrdem($tecnico, 0.01, 0.0);
        $compromisso = $this->criarCompromisso($tecnico, 0.02, 0.0);

        $resposta = $this->actingAs($this->administrador)->getJson(
            '/roteiros?data='.self::DATA_DO_ROTEIRO.'&technician_id='.$tecnico->id
        );

        $resposta->assertOk();

        $paradas = $resposta->json('paradas');
        $this->assertCount(2, $paradas);

        $paradaOs = collect($paradas)->firstWhere('work_order_id', $ordem->id);
        $paradaCompromisso = collect($paradas)->firstWhere('compromisso_id', $compromisso->id);

        $this->assertNotNull($paradaOs, 'a parada da OS precisa estar na resposta');
        $this->assertSame('os', $paradaOs['tipo_item']);

        $this->assertNotNull($paradaCompromisso, 'a parada do compromisso precisa estar na resposta');
        $this->assertSame('compromisso', $paradaCompromisso['tipo_item']);
        $this->assertSame($compromisso->titulo, $paradaCompromisso['compromisso_titulo']);
        $this->assertSame($compromisso->tipo, $paradaCompromisso['compromisso_tipo']);
    }

    public function test_reordenar_com_lista_mista_de_os_e_compromisso_reordena_as_duas(): void
    {
        $tecnico = $this->criarTecnico('MistoI2');

        $ordem = $this->criarOrdem($tecnico, 0.01, 0.0);
        $compromisso = $this->criarCompromisso($tecnico, 0.02, 0.0);

        $montagem = $this->actingAs($this->administrador)->getJson(
            '/roteiros?data='.self::DATA_DO_ROTEIRO.'&technician_id='.$tecnico->id
        );
        $montagem->assertOk();
        $rotaId = $montagem->json('id');

        $reordenacao = $this->actingAs($this->administrador)->putJson("/roteiros/{$rotaId}/ordem", [
            'paradas' => ["compromisso:{$compromisso->id}", "os:{$ordem->id}"],
        ]);

        $reordenacao->assertOk();
        $this->assertTrue($reordenacao->json('ordenacao_manual'));

        $paradas = $reordenacao->json('paradas');
        $this->assertSame('compromisso', $paradas[0]['tipo_item']);
        $this->assertSame($compromisso->id, $paradas[0]['compromisso_id']);
        $this->assertSame(1, $paradas[0]['ordem']);
        $this->assertSame('os', $paradas[1]['tipo_item']);
        $this->assertSame($ordem->id, $paradas[1]['work_order_id']);
        $this->assertSame(2, $paradas[1]['ordem']);
    }

    /**
     * Lista que não bate exatamente com as paradas atuais continua sendo
     * recusada (mesma regra de negócio da Task 22.5, agora exercitada com as
     * duas origens misturadas): faltar o compromisso da lista é tão inválido
     * quanto faltar uma OS.
     */
    public function test_reordenar_com_lista_que_nao_bate_com_as_paradas_atuais_e_recusada(): void
    {
        $tecnico = $this->criarTecnico('MistoI3');

        $ordem = $this->criarOrdem($tecnico, 0.01, 0.0);
        $this->criarCompromisso($tecnico, 0.02, 0.0);

        $montagem = $this->actingAs($this->administrador)->getJson(
            '/roteiros?data='.self::DATA_DO_ROTEIRO.'&technician_id='.$tecnico->id
        );
        $montagem->assertOk();
        $rotaId = $montagem->json('id');

        $reordenacao = $this->actingAs($this->administrador)->putJson("/roteiros/{$rotaId}/ordem", [
            'paradas' => ["os:{$ordem->id}"],
        ]);

        $reordenacao->assertStatus(422);
    }

    /**
     * Isolamento multiempresa: um compromisso gravado sob a outra empresa,
     * mesmo referenciando o `technician_id` desta (cenário de vazamento por
     * fora do escopo automático), nunca aparece no roteiro de quem não é
     * dela. Mesmo critério de isolamento por escopo global já aplicado a
     * todo o resto do domínio (`permissoes-e-multitenancy`), agora provado
     * para `Compromisso`.
     */
    public function test_compromisso_de_outra_empresa_nunca_aparece_no_roteiro(): void
    {
        $tecnico = $this->criarTecnico('MistoI4');
        $ordem = $this->criarOrdem($tecnico, 0.01, 0.0);

        TenantAtual::comTenant($this->outraEmpresa()->id, fn (): Compromisso => CompromissoFactory::new()->create([
            'technician_id' => $tecnico->id,
            'data' => self::DATA_DO_ROTEIRO,
            'situacao' => 'agendado',
        ]));

        $resposta = $this->actingAs($this->administrador)->getJson(
            '/roteiros?data='.self::DATA_DO_ROTEIRO.'&technician_id='.$tecnico->id
        );

        $resposta->assertOk();

        $paradas = $resposta->json('paradas');
        $this->assertCount(1, $paradas, 'o compromisso da outra empresa não pode entrar no roteiro');
        $this->assertSame('os', $paradas[0]['tipo_item']);
        $this->assertSame($ordem->id, $paradas[0]['work_order_id']);
    }

    /**
     * Cascata de exclusão: excluir a OS remove a parada correspondente do
     * roteiro (`route_stops.work_order_id` com `cascadeOnDelete`, ver
     * `create_routes_table`). Primeiro teste deste padrão no arquivo -
     * confirmado no schema antes de escrever, para não provar algo que
     * passaria mesmo sem a restrição de fato existir.
     */
    public function test_excluir_a_os_remove_a_parada_correspondente_do_roteiro(): void
    {
        $tecnico = $this->criarTecnico('MistoI5');

        $ordem = $this->criarOrdem($tecnico, 0.01, 0.0);
        $compromisso = $this->criarCompromisso($tecnico, 0.02, 0.0);

        $montagem = $this->actingAs($this->administrador)->getJson(
            '/roteiros?data='.self::DATA_DO_ROTEIRO.'&technician_id='.$tecnico->id
        );
        $montagem->assertOk();
        $rotaId = $montagem->json('id');

        $this->comTenant(fn (): bool => (bool) WorkOrder::query()->findOrFail($ordem->id)->delete());

        $this->assertDatabaseMissing('route_stops', [
            'route_id' => $rotaId,
            'work_order_id' => $ordem->id,
        ]);

        $paradasRestantes = $this->comTenant(fn () => Route::query()->findOrFail($rotaId)->stops()->get());
        $this->assertCount(1, $paradasRestantes);
        $this->assertSame($compromisso->id, $paradasRestantes->first()->compromisso_id);
    }

    /**
     * Mesma cascata, para a origem `Compromisso`
     * (`route_stops.compromisso_id` com `cascadeOnDelete`, ver
     * `add_compromisso_to_route_stops_table`) - o comportamento que esta
     * task existe para provar.
     */
    public function test_excluir_o_compromisso_remove_a_parada_correspondente_do_roteiro(): void
    {
        $tecnico = $this->criarTecnico('MistoI6');

        $ordem = $this->criarOrdem($tecnico, 0.01, 0.0);
        $compromisso = $this->criarCompromisso($tecnico, 0.02, 0.0);

        $montagem = $this->actingAs($this->administrador)->getJson(
            '/roteiros?data='.self::DATA_DO_ROTEIRO.'&technician_id='.$tecnico->id
        );
        $montagem->assertOk();
        $rotaId = $montagem->json('id');

        $this->comTenant(fn (): bool => (bool) Compromisso::query()->findOrFail($compromisso->id)->delete());

        $this->assertDatabaseMissing('route_stops', [
            'route_id' => $rotaId,
            'compromisso_id' => $compromisso->id,
        ]);

        $paradasRestantes = $this->comTenant(fn () => Route::query()->findOrFail($rotaId)->stops()->get());
        $this->assertCount(1, $paradasRestantes);
        $this->assertSame($ordem->id, $paradasRestantes->first()->work_order_id);
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function comTenant(\Closure $callback): mixed
    {
        return TenantAtual::comTenant($this->empresa->id, $callback);
    }

    private function criarTecnico(string $sufixo): Technician
    {
        return $this->comTenant(fn (): Technician => Technician::create([
            'name' => "Técnico {$sufixo}",
            'email' => 'tecnico-'.strtolower($sufixo).'-'.uniqid().'@exemplo.test',
            'phone' => '11999990000',
            'is_active' => true,
        ]));
    }

    /**
     * @return array{0: User, 1: Technician}
     */
    private function criarTecnicoComUsuario(string $sufixo): array
    {
        return $this->comTenant(function () use ($sufixo): array {
            $usuario = User::factory()->create([
                'name' => 'Usuário Técnico '.$sufixo,
                'email' => 'usuario-tecnico-'.strtolower($sufixo).'-'.uniqid().'@exemplo.test',
                'is_active' => true,
            ]);
            $usuario->assignRole('tecnico');

            $tecnico = Technician::create([
                'name' => "Técnico {$sufixo}",
                'email' => 'tecnico-'.strtolower($sufixo).'-'.uniqid().'@exemplo.test',
                'phone' => '11999990000',
                'is_active' => true,
                'user_id' => $usuario->id,
            ]);

            return [$usuario, $tecnico];
        });
    }

    /**
     * Empresa concorrente, mesmo padrão de `EpiEndpointTest::outraEmpresa()`:
     * criada uma vez por teste, sob demanda, para os cenários de isolamento
     * multiempresa.
     */
    private function outraEmpresa(): Company
    {
        return Company::create([
            'name' => 'Concorrente do Roteiro',
            'cnpj' => '66.666.666/0001-66',
            'email' => 'contato@concorrente-roteiro.test',
        ]);
    }

    /**
     * OS agendada para `self::DATA_DO_ROTEIRO`, com endereço geocodificado a
     * `$latOffset`/`$lngOffset` graus da origem `(0, 0)` (só uma referência
     * para os deltas entre as próprias paradas do teste, sem relação com a
     * sede: `RouteController::sedeCoordenada()` devolve `null` até
     * `companies` ganhar coordenada própria, ver o cabeçalho daquela
     * classe - o roteiro é montado sem base conhecida).
     */
    private function criarOrdem(Technician $tecnico, float $latOffset, float $lngOffset, ?string $horario = null): WorkOrder
    {
        return $this->comTenant(function () use ($tecnico, $latOffset, $lngOffset, $horario): WorkOrder {
            $cliente = ClientFactory::new()->create();
            $endereco = AddressFactory::new()->create(['client_id' => $cliente->id]);

            $endereco->forceFill([
                'latitude' => $latOffset,
                'longitude' => $lngOffset,
                'precisao_geocodificacao' => ResultadoGeo::PRECISAO_EXATA,
                'origem_coordenada' => Address::ORIGEM_COORDENADA_AUTOMATICA,
                'geocodificado_em' => now(),
            ])->save();

            return WorkOrderFactory::new()->create([
                'client_id' => $cliente->id,
                'address_id' => $endereco->id,
                'technician_id' => $tecnico->id,
                'scheduled_date' => self::DATA_DO_ROTEIRO,
                'status' => 'scheduled',
                'start_time' => $horario !== null
                    ? Carbon::parse(self::DATA_DO_ROTEIRO.' '.$horario, BusinessDate::fuso())
                        ->setTimezone(config('app.timezone'))
                    : null,
            ]);
        });
    }

    /**
     * Compromisso avulso agendado para `self::DATA_DO_ROTEIRO`, irmão de
     * `criarOrdem()` para a origem `Compromisso` (Plano 31): mesmo padrão de
     * endereço geocodificado a `$latOffset`/`$lngOffset` graus da origem
     * `(0, 0)`.
     */
    private function criarCompromisso(
        Technician $tecnico,
        float $latOffset,
        float $lngOffset,
        ?string $horario = null,
    ): Compromisso {
        return $this->comTenant(function () use ($tecnico, $latOffset, $lngOffset, $horario): Compromisso {
            $cliente = ClientFactory::new()->create();
            $endereco = AddressFactory::new()->create(['client_id' => $cliente->id]);

            $endereco->forceFill([
                'latitude' => $latOffset,
                'longitude' => $lngOffset,
                'precisao_geocodificacao' => ResultadoGeo::PRECISAO_EXATA,
                'origem_coordenada' => Address::ORIGEM_COORDENADA_AUTOMATICA,
                'geocodificado_em' => now(),
            ])->save();

            return CompromissoFactory::new()->create([
                'client_id' => $cliente->id,
                'address_id' => $endereco->id,
                'technician_id' => $tecnico->id,
                'data' => self::DATA_DO_ROTEIRO,
                'situacao' => 'agendado',
                'hora_inicio' => $horario,
                'hora_fim' => null,
            ]);
        });
    }

    /**
     * Provedor de geocodificação simulado, mesmo padrão de
     * `GeocodificacaoServiceTest::ligarProvedorFake()`: nenhum teste deste
     * arquivo pode depender de uma chamada HTTP real ao Nominatim.
     */
    private function ligarProvedorFake(array $respostas = []): object
    {
        $fake = new class implements ProvedorDeGeocodificacao
        {
            /** @var array<int, ResultadoGeo|null> */
            public array $respostas = [];

            /** @var array<int, string> */
            public array $chamadas = [];

            public function buscar(string $endereco): ?ResultadoGeo
            {
                $this->chamadas[] = $endereco;

                if ($this->respostas === []) {
                    return null;
                }

                return array_shift($this->respostas);
            }
        };

        $fake->respostas = $respostas;

        $this->app->instance(ProvedorDeGeocodificacao::class, $fake);

        return $fake;
    }
}
