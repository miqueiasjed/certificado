<?php

namespace Tests\Feature\Portal;

use App\Models\Address;
use App\Models\Certificate;
use App\Models\ClientRequest;
use App\Models\ClientUser;
use App\Models\Company;
use App\Models\Contract;
use App\Models\FiscalConfig;
use App\Models\MonitoringReport;
use App\Models\PaymentDetail;
use App\Models\ServiceInvoice;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderAdequation;
use App\Support\TenantAtual;
use Carbon\Carbon;
use Database\Factories\ClientFactory;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Task 15.9 do Plano 15: rede sistemática de isolamento do portal do cliente.
 *
 * `PortalAuthTest`, `PortalServiceTest`, `PortalEndpointTest` e
 * `ClientRequestTest` (tests/Feature/, criados nas Tasks 15.2 a 15.5) já
 * cobrem: convite/token expirado/reutilizado/mensagem idêntica de login/limite
 * de tentativas/desativação imediata (15.2); escopo duplo empresa+cliente com
 * o escopo global desligado, e campo novo no model que não vaza (15.3); 404
 * para id de outro cliente nas cinco telas e no download, um a um (15.4); e o
 * fluxo de solicitação de atendimento (15.5). Nenhum desses critérios é
 * duplicado aqui.
 *
 * O que esta classe acrescenta, e que nenhuma task anterior cobre de forma
 * sistemática:
 *
 * - **Varredura automática de `routes/portal.php`**: lê `Route::getRoutes()`
 *   e classifica toda rota nomeada `portal.*` em uma de três categorias
 *   (pública/fora da varredura, listagem, detalhe). Rota nova em
 *   `routes/portal.php` que não entrar em nenhuma das três derruba
 *   {@see self::test_a_varredura_de_rotas_do_portal_classifica_todas_as_rotas_declaradas()},
 *   e é isso que impede uma rota nova nascer vazando sem ninguém perceber.
 * - **Cenário com duas empresas e dois clientes em cada**, todos com dado real
 *   nas seis entidades do portal (visita, certificado, contrato, adequação,
 *   fatura, solicitação), para que a varredura tenha o que vazar se algo
 *   quebrar.
 * - **id de outra empresa** nas rotas de detalhe (a Task 15.4 só cobriu "outro
 *   cliente da mesma empresa").
 * - **Guarda cruzado**: cliente autenticado batendo em rota de `web.php`, e
 *   funcionário autenticado batendo em rota do portal, cada um com o guard do
 *   outro conferido explicitamente.
 *
 * Documento em rascunho (outro item da especificação da Task 15.9) não ganha
 * teste próprio aqui: como registrado no cabeçalho de `PortalService`, o enum
 * atual de `certificates.status` não aceita mais `draft`, e
 * `PortalServiceTest::test_consulta_de_certificados_exclui_rascunho_por_construcao()`
 * já confere, por inspeção da consulta montada, que a exclusão continua
 * escrita por construção. Repetir a mesma inspeção aqui seria duplicar teste
 * já existente com outro nome.
 */
class IsolamentoDoPortalTest extends TestCase
{
    use RefreshDatabase;

    private const SENHA = 'senha-teste-123';

    /**
     * Rotas públicas do portal, e a única ação de escrita sem parâmetro
     * (`solicitacoes.store`), cada uma com o motivo de ficar fora da
     * varredura de isolamento. Nenhuma delas tem "dado de outro cliente" para
     * vazar: as de login/senha não exigem sessão, `logout` encerraria a
     * sessão e derrubaria as chamadas seguintes da mesma varredura (mesmo
     * critério de `VazamentoEntreEmpresasTest::ROTAS_FORA_DA_VARREDURA`),
     * `modulo-indisponivel` é página estática sem consulta a model de
     * domínio, e `solicitacoes.store` só grava a própria solicitação do
     * cliente autenticado (a leitura dela já é coberta por
     * `solicitacoes.index`/`solicitacoes.show`, que estão na varredura).
     *
     * @var array<string, string>
     */
    private const ROTAS_PUBLICAS_OU_FORA_DA_VARREDURA = [
        'portal.login' => 'Tela pública de login, sem sessão e sem dado de cliente.',
        'portal.login.post' => 'Autenticação; a mensagem devolvida é testada em PortalAuthTest.',
        'portal.logout' => 'Encerra a sessão; entrar na varredura derrubaria as chamadas seguintes com o mesmo cliente autenticado.',
        'portal.senha.esqueci' => 'Tela pública de recuperação de senha, sem dado de outro cliente.',
        'portal.senha.esqueci.post' => 'Envio do pedido de recuperação; mensagem genérica testada em PortalAuthTest.',
        'portal.senha.definir' => 'Tela pública de definição de senha, token na URL, sem dado de outro cliente.',
        'portal.senha.definir.post' => 'Efetiva a senha nova; token de uso único testado em PortalAuthTest.',
        'portal.modulo-indisponivel' => 'Página estática de aviso, sem consulta a model de domínio.',
        'portal.solicitacoes.store' => 'Ação de criação da própria solicitação; a leitura dela está em solicitacoes.index/solicitacoes.show, que entram na varredura.',
        // Plano 19, Task 19.6: ação de escrita (gera a cobrança da própria
        // fatura), mesmo critério de portal.solicitacoes.store acima. O dono
        // da fatura é conferido por PortalService::parcelaParaCobranca()
        // antes de qualquer emissão (404 para fatura de outro cliente/outra
        // empresa, coberto por ChargeEndpointTest); a leitura da fatura em si
        // já está em portal.faturas, que entra na varredura.
        'portal.faturas.cobranca' => 'Ação de escrita (gera a cobrança da própria fatura); o dono é conferido antes de emitir, e a leitura da fatura está em portal.faturas, já na varredura.',
    ];

    /**
     * Rotas de listagem: nome da rota => tipo de entidade (chave do conjunto
     * de fixture) => prop da resposta onde aquele tipo aparece. `portal.painel`
     * e `portal.faturas` combinam mais de um tipo na mesma resposta; escopar
     * a busca de id à prop certa evita falso positivo de vazamento quando
     * duas tabelas diferentes (ex.: work_orders e work_order_adequations)
     * reaproveitam o mesmo id por coincidência de autoincremento entre
     * testes - o id em si deixa de ser um identificador confiável de tipo
     * assim que mais de uma entidade divide a mesma resposta.
     *
     * @var array<string, array<string, string>>
     */
    private const ROTAS_DE_LISTAGEM = [
        'portal.painel' => ['visita' => 'proximasVisitas', 'adequacao' => 'adequacoesEmAberto', 'fatura' => 'faturasVencendo'],
        'portal.visitas' => ['visita' => 'visitas'],
        'portal.certificados' => ['certificado' => 'certificados'],
        'portal.contratos' => ['contrato' => 'contratos'],
        'portal.adequacoes' => ['adequacao' => 'adequacoes'],
        'portal.faturas' => ['fatura' => 'faturas', 'nota' => 'notas_fiscais'],
        'portal.notas.index' => ['nota' => 'notas_fiscais'],
        'portal.solicitacoes.index' => ['solicitacao' => 'solicitacoes'],
        // Plano 21, Task 21.5: relatório de monitoramento publicado. A
        // varredura genérica (`idsNoArray()`) percorre a estrutura de
        // paginação sem precisar de tratamento especial - encontra o "id" de
        // cada relatório dentro de `relatorios.data`, no mesmo jeito que já
        // faz com o restante das listagens.
        'portal.relatorios.index' => ['relatorio' => 'relatorios'],
    ];

    /**
     * Rotas de detalhe (recebem id, e devolvem 404 para id que não é do
     * cliente autenticado).
     *
     * @var array<int, string>
     */
    private const ROTAS_DE_DETALHE = [
        'portal.visitas.show',
        'portal.solicitacoes.show',
        'portal.documentos.download',
        'portal.notas.pdf',
        'portal.notas.xml',
        // Plano 21, Task 21.5: só relatório publicado é acessível (o não
        // publicado já é 404 por construção, coberto em
        // `MonitoringReportEndpointTest`); aqui o conjunto de fixture só tem
        // relatório publicado, então o que esta classe testa é o mesmo
        // critério das demais rotas de detalhe - id de outro cliente/outra
        // empresa devolve 404.
        'portal.relatorios.show',
        'portal.relatorios.pdf',
        // Plano 26, Task 26.4: via assinada do contrato. Recebe o id do
        // contrato e resolve o pedido por `PortalService::pedidoDeAssinaturaAssinado()`,
        // que passa pelo mesmo `buscarContrato()` das demais rotas de detalhe
        // (escopo por empresa E por cliente). Contrato de outro cliente devolve
        // 404, que é exatamente o que esta varredura confere.
        'portal.contratos.assinado',
    ];

    /**
     * Campos de custo, margem ou comissão que não podem aparecer em nenhum
     * nível do JSON devolvido ao portal. Mesma lista de
     * `PortalServiceTest::camposProibidosProvider()`, conferida aqui contra o
     * JSON de resposta das rotas HTTP, e não contra o array devolvido pelo
     * Service diretamente.
     *
     * @var array<int, string>
     */
    private const CAMPOS_PROIBIDOS = [
        'total_cost', 'discount_amount', 'final_amount', 'materials_used',
        'observations', 'completion_notes', 'payment_notes', 'payment_details',
        'margin', 'margem', 'commission', 'comissao',
    ];

    private Company $empresaUm;

    private Company $empresaDois;

    /** @var array<string, array<string, mixed>> */
    private array $um = [];

    /** @var array<string, array<string, mixed>> */
    private array $dois = [];

    private User $administrador;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();
        Carbon::setTestNow('2026-07-28 10:00:00');
        Storage::fake('local');

        $this->seed(RolesAndPermissionsSeeder::class);

        // O tenant fundador (empresa 1) entra no "Plano Interno" com todos os
        // módulos liberados, inclusive portal_cliente - sem isto nenhuma rota
        // atrás de EnsurePortalModuleIsActive responderia.
        $this->seed(ModulesSeeder::class);

        $this->empresaUm = Company::query()->firstOrFail();
        $this->empresaUm->update(['name' => 'Empresa Um', 'email' => 'contato@empresaum.test']);
        $this->empresaUm = $this->empresaUm->fresh();

        $this->empresaDois = Company::create(['name' => 'Empresa Dois', 'email' => 'contato@empresadois.test']);

        // Duas empresas, dois clientes em cada, todos com dado real nas seis
        // entidades do portal.
        $this->um = [
            'a' => $this->criarConjuntoCompleto($this->empresaUm->id, 'UmA'),
            'b' => $this->criarConjuntoCompleto($this->empresaUm->id, 'UmB'),
        ];
        $this->dois = [
            'a' => $this->criarConjuntoCompleto($this->empresaDois->id, 'DoisA'),
            'b' => $this->criarConjuntoCompleto($this->empresaDois->id, 'DoisB'),
        ];

        $this->administrador = TenantAtual::comTenant(
            $this->empresaUm->id,
            fn (): User => User::factory()->create(['is_active' => true])
        );
        $this->administrador->assignRole('administrador');

        TenantAtual::limpar();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // O coração da task: a varredura automática de routes/portal.php
    // -----------------------------------------------------------------

    /**
     * Lê o roteador de verdade (`Route::getRoutes()`), filtra pelo prefixo de
     * nome `portal.` e exige que toda rota encontrada esteja em uma das três
     * listas de classificação declaradas nesta classe. Rota nova em
     * `routes/portal.php` sem entrada em nenhuma das três derruba este teste;
     * é este o teste que impede uma rota nova nascer vazando.
     */
    public function test_a_varredura_de_rotas_do_portal_classifica_todas_as_rotas_declaradas(): void
    {
        $encontradas = $this->nomesDasRotasDoPortal();

        $classificadas = array_merge(
            array_keys(self::ROTAS_PUBLICAS_OU_FORA_DA_VARREDURA),
            array_keys(self::ROTAS_DE_LISTAGEM),
            self::ROTAS_DE_DETALHE
        );
        sort($classificadas);

        $naoClassificadas = array_values(array_diff($encontradas, $classificadas));
        $obsoletas = array_values(array_diff($classificadas, $encontradas));

        $this->assertSame(
            [],
            $naoClassificadas,
            'Rota(s) nova(s) em routes/portal.php sem classificação neste teste: '.implode(', ', $naoClassificadas).'. '
            .'Classifique cada uma em ROTAS_PUBLICAS_OU_FORA_DA_VARREDURA (com o motivo), ROTAS_DE_LISTAGEM ou '
            .'ROTAS_DE_DETALHE, em '.self::class.', antes de seguir em frente.'
        );

        $this->assertSame(
            [],
            $obsoletas,
            'Rota(s) classificada(s) neste teste que não existem mais em routes/portal.php: '.implode(', ', $obsoletas).'. '
            .'Remova a entrada obsoleta da classificação.'
        );

        $this->assertGreaterThanOrEqual(
            19,
            count($encontradas),
            'a varredura encontrou poucas rotas: o filtro por nome "portal." provavelmente quebrou'
        );
    }

    // -----------------------------------------------------------------
    // Rotas de listagem: id de outro cliente nunca aparece, o próprio sempre aparece
    // -----------------------------------------------------------------

    public function test_rotas_de_listagem_nao_trazem_dado_de_outro_cliente_da_mesma_empresa(): void
    {
        $this->autenticarComo($this->um['a']);

        foreach (self::ROTAS_DE_LISTAGEM as $nomeRota => $tipos) {
            [, $dados] = $this->respostaDaListagem($nomeRota);

            foreach ($tipos as $tipo => $propKey) {
                $ids = $this->idsDoTipo($dados, $propKey, $nomeRota);

                $this->assertContains(
                    $this->um['a'][$tipo]->id,
                    $ids,
                    "'{$nomeRota}' deveria trazer o próprio {$tipo} (rede contra a varredura passar à toa por lista vazia)"
                );
                $this->assertNotContains(
                    $this->um['b'][$tipo]->id,
                    $ids,
                    "'{$nomeRota}' vazou o {$tipo} de outro cliente da mesma empresa"
                );
            }
        }
    }

    public function test_rotas_de_listagem_nao_trazem_dado_de_cliente_de_outra_empresa(): void
    {
        $this->autenticarComo($this->um['a']);

        foreach (self::ROTAS_DE_LISTAGEM as $nomeRota => $tipos) {
            [, $dados] = $this->respostaDaListagem($nomeRota);

            foreach ($tipos as $tipo => $propKey) {
                $ids = $this->idsDoTipo($dados, $propKey, $nomeRota);

                $this->assertNotContains($this->dois['a'][$tipo]->id, $ids, "'{$nomeRota}' vazou o {$tipo} de outra empresa (cliente A)");
                $this->assertNotContains($this->dois['b'][$tipo]->id, $ids, "'{$nomeRota}' vazou o {$tipo} de outra empresa (cliente B)");
            }
        }
    }

    // -----------------------------------------------------------------
    // Rotas de detalhe: id de outro cliente e de outra empresa devolvem 404
    // -----------------------------------------------------------------

    /**
     * Critério "id de outro cliente devolve 404 em todos os endpoints de
     * detalhe". `PortalEndpointTest` já cobre isso rota a rota, à mão; aqui a
     * cobertura sai da classificação automática (`ROTAS_DE_DETALHE`), então
     * rota de detalhe nova entra sozinha.
     */
    public function test_rotas_de_detalhe_com_id_de_outro_cliente_da_mesma_empresa_devolvem_404(): void
    {
        $this->autenticarComo($this->um['a']);

        foreach (self::ROTAS_DE_DETALHE as $nomeRota) {
            foreach ($this->urlsDeDetalheParaOutro($nomeRota, $this->um['b']) as $url) {
                $this->assertSame(
                    404,
                    $this->get($url)->status(),
                    "'{$nomeRota}' ({$url}) deveria devolver 404 para dado de outro cliente da mesma empresa"
                );
            }
        }
    }

    /**
     * Critério "id de outra empresa devolve 404". Cobre também "documento de
     * outro cliente devolve 404" para o caso entre empresas, via
     * `portal.documentos.download` dentro da classificação.
     */
    public function test_rotas_de_detalhe_com_id_de_outra_empresa_devolvem_404(): void
    {
        $this->autenticarComo($this->um['a']);

        foreach (self::ROTAS_DE_DETALHE as $nomeRota) {
            $urls = array_merge(
                $this->urlsDeDetalheParaOutro($nomeRota, $this->dois['a']),
                $this->urlsDeDetalheParaOutro($nomeRota, $this->dois['b'])
            );

            foreach ($urls as $url) {
                $this->assertSame(
                    404,
                    $this->get($url)->status(),
                    "'{$nomeRota}' ({$url}) deveria devolver 404 para dado de outra empresa"
                );
            }
        }
    }

    /**
     * Rede contra as duas asserções acima passarem à toa com uma rota
     * quebrada que devolve 404 para todo mundo, inclusive o dono.
     */
    public function test_o_dono_continua_abrindo_as_proprias_rotas_de_detalhe(): void
    {
        $this->autenticarComo($this->um['a']);

        $this->assertNotSame(
            404,
            $this->get(route('portal.visitas.show', ['id' => $this->um['a']['visita']->id]))->status()
        );
        $this->assertNotSame(
            404,
            $this->get(route('portal.solicitacoes.show', ['id' => $this->um['a']['solicitacao']->id]))->status()
        );
        $this->get(route('portal.documentos.download', ['tipo' => 'certificado', 'id' => $this->um['a']['certificado']->id]))
            ->assertOk();
        $this->get(route('portal.notas.pdf', ['nota' => $this->um['a']['nota']->id]))->assertOk();
        $this->get(route('portal.notas.xml', ['nota' => $this->um['a']['nota']->id]))->assertOk();
        $this->assertNotSame(
            404,
            $this->get(route('portal.relatorios.show', ['monitoringReport' => $this->um['a']['relatorio']->id]))->status()
        );
        $this->get(route('portal.relatorios.pdf', ['monitoringReport' => $this->um['a']['relatorio']->id]))->assertOk();
    }

    // -----------------------------------------------------------------
    // Nenhuma resposta expõe campo de custo, margem ou comissão
    // -----------------------------------------------------------------

    /**
     * Percorre o JSON (`inertiaProps()`, já decodificado) de toda rota de
     * listagem e das rotas de detalhe que devolvem página Inertia (o download
     * de documento fica de fora: devolve um PDF binário, não JSON), com a
     * mesma função recursiva `todasAsChavesDoJson()` de
     * `AppDayLoadTest` (Plano 12, Task 12.12), replicada aqui porque é um
     * teste HTTP, e não um teste de Service como `PortalServiceTest`.
     */
    public function test_nenhuma_rota_do_portal_expoe_campo_de_custo_margem_ou_comissao_no_json(): void
    {
        $this->autenticarComo($this->um['a']);

        foreach (array_keys(self::ROTAS_DE_LISTAGEM) as $nomeRota) {
            [, $dados] = $this->respostaDaListagem($nomeRota);
            $chaves = $this->todasAsChavesDoJson($dados);

            foreach (self::CAMPOS_PROIBIDOS as $campo) {
                $this->assertNotContains($campo, $chaves, "a chave financeira \"{$campo}\" apareceu em '{$nomeRota}'");
            }
        }

        foreach ([
            route('portal.visitas.show', ['id' => $this->um['a']['visita']->id]),
            route('portal.solicitacoes.show', ['id' => $this->um['a']['solicitacao']->id]),
            route('portal.relatorios.show', ['monitoringReport' => $this->um['a']['relatorio']->id]),
        ] as $url) {
            $resposta = $this->get($url)->assertOk();
            $chaves = $this->todasAsChavesDoJson($resposta->inertiaProps());

            foreach (self::CAMPOS_PROIBIDOS as $campo) {
                $this->assertNotContains($campo, $chaves, "a chave financeira \"{$campo}\" apareceu em '{$url}'");
            }
        }
    }

    // -----------------------------------------------------------------
    // Guarda cruzado: cliente não alcança web.php, funcionário não alcança o portal
    // -----------------------------------------------------------------

    /**
     * `PortalAuthTest::test_cliente_autenticado_nao_alcanca_rota_de_web_php()`
     * já cobre `/dashboard`. Esta acrescenta uma rota representativa de cada
     * área principal (dashboard, clientes, financeiro), com o guard `web`
     * conferido explicitamente como nunca autenticado.
     */
    public function test_cliente_autenticado_recebe_redirecionamento_em_rotas_representativas_de_web_php(): void
    {
        $this->autenticarComo($this->um['a']);

        $this->assertTrue(auth('cliente')->check());
        $this->assertFalse(auth('web')->check());

        foreach (['/dashboard', '/clients', '/financial-dashboard'] as $uri) {
            $resposta = $this->get($uri);

            $this->assertContains(
                $resposta->status(),
                [302, 403],
                "'{$uri}' deveria recusar o guard cliente com 403 ou redirecionamento, devolveu {$resposta->status()}"
            );

            if ($resposta->status() === 302) {
                $resposta->assertRedirect('/login');
            }

            $this->assertFalse(auth('web')->check(), "'{$uri}': o guard web não deveria ter sido autenticado");
        }
    }

    /**
     * `PortalAuthTest::test_funcionario_autenticado_nao_alcanca_portal()` já
     * cobre `/portal/logout`. Esta percorre as rotas de listagem (a
     * classificação automática de `ROTAS_DE_LISTAGEM`) mais uma rota de
     * detalhe, com o guard `cliente` conferido explicitamente como nunca
     * autenticado.
     */
    public function test_funcionario_autenticado_nao_alcanca_nenhuma_rota_protegida_do_portal(): void
    {
        $urls = array_merge(
            array_map(fn (string $nome): string => route($nome), array_keys(self::ROTAS_DE_LISTAGEM)),
            [route('portal.visitas.show', ['id' => $this->um['a']['visita']->id])]
        );

        foreach ($urls as $url) {
            $resposta = $this->actingAs($this->administrador)->get($url);

            $this->assertFalse(auth('cliente')->check(), "'{$url}': o guard cliente não deveria estar autenticado");
            $resposta->assertRedirect(route('portal.login'));
        }
    }

    // -----------------------------------------------------------------
    // Apoio: fixture
    // -----------------------------------------------------------------

    /**
     * Monta cliente, endereço, visita, certificado, contrato, adequação em
     * aberto, fatura, usuário de acesso ao portal e solicitação de
     * atendimento, todos vinculados entre si, dentro do tenant informado. Roda
     * no espírito de `VazamentoEntreEmpresasTest::criarConjuntoCompleto()`,
     * adaptado às seis entidades que o portal expõe.
     *
     * @return array<string, mixed>
     */
    private function criarConjuntoCompleto(int $empresaId, string $marca): array
    {
        return TenantAtual::comTenant($empresaId, function () use ($marca): array {
            $baixo = strtolower($marca);

            $cliente = ClientFactory::new()->create(['name' => 'Cliente '.$marca]);

            $endereco = Address::factory()->create([
                'client_id' => $cliente->id,
                'nickname' => 'Unidade '.$marca,
            ]);

            $visita = WorkOrder::factory()->create([
                'client_id' => $cliente->id,
                'address_id' => $endereco->id,
                'order_number' => 'OT-'.$marca,
                'scheduled_date' => '2026-08-01',
                'status' => 'scheduled',
            ]);

            $certificado = Certificate::factory()->create([
                'client_id' => $cliente->id,
                'address_id' => $endereco->id,
                'certificate_number' => 'CERT-'.$marca,
                'status' => 'active',
                'execution_date' => '2026-07-20',
            ]);

            $contrato = Contract::create([
                'address_id' => $endereco->id,
                'contract_number' => 'CONT-'.$marca,
                'start_date' => '2026-01-01',
                'end_date' => '2027-01-01',
                'service_value' => 1000,
                'service_type' => 'periodico',
                'visit_frequency_valor' => 3,
                'visit_frequency_unidade' => 'meses',
                'pest_target' => 'Praga '.$marca,
                'payment_method' => 'PIX',
            ]);

            $adequacao = WorkOrderAdequation::create([
                'work_order_id' => $visita->id,
                'type' => 'estrutural',
                'description' => 'Adequação '.$marca,
                'priority' => 'alta',
                'status' => 'pendente',
                'deadline' => '2026-09-01',
            ]);

            $fatura = PaymentDetail::factory()->create([
                'work_order_id' => $visita->id,
                'payment_due_date' => '2026-08-10',
            ]);

            $configuracaoFiscal = FiscalConfig::query()->first() ?? FiscalConfig::create([
                'provedor' => 'nuvem_fiscal',
                'ambiente' => 'homologacao',
                'credenciais' => ['client_id' => 'teste', 'client_secret' => 'teste'],
                'regime_tributario' => 'simples_nacional',
                'codigo_servico' => '07.13',
                'aliquota_iss' => '5.00',
                'natureza_operacao' => 'tributacao_no_municipio',
                'ativo' => true,
            ]);
            $nota = ServiceInvoice::create([
                'fiscal_config_id' => $configuracaoFiscal->id,
                'client_id' => $cliente->id,
                'address_id' => $endereco->id,
                'work_order_id' => $visita->id,
                'numero' => 'NFSE-'.$marca,
                'situacao' => 'emitida',
                'valor_servico' => '100.00',
                'valor_iss' => '5.00',
                'valor_liquido' => '100.00',
                'descricao_servico' => 'Serviço fiscal '.$marca,
                'competencia' => '2026-07-28',
            ]);
            $nota->update([
                'pdf_path' => "fiscal/empresa-{$nota->company_id}/nota-{$nota->id}/nfse.pdf",
                'xml_path' => "fiscal/empresa-{$nota->company_id}/nota-{$nota->id}/nfse.xml",
            ]);
            Storage::disk('local')->put($nota->pdf_path, '%PDF');
            Storage::disk('local')->put($nota->xml_path, '<nota/>');

            $clientUser = ClientUser::create([
                'client_id' => $cliente->id,
                'nome' => 'Usuário '.$marca,
                'email' => 'usuario-'.$baixo.'@exemplo.test',
                'password' => Hash::make(self::SENHA),
                'ativo' => true,
                'email_verificado_em' => now(),
            ]);

            $solicitacao = ClientRequest::create([
                'client_id' => $cliente->id,
                'client_user_id' => $clientUser->id,
                'assunto' => 'Assunto '.$marca,
                'descricao' => 'Descrição da solicitação '.$marca,
                'situacao' => ClientRequest::SITUACAO_ABERTA,
                'prioridade' => ClientRequest::PRIORIDADE_NORMAL,
            ]);

            // Plano 21, Task 21.5: relatório de monitoramento já publicado -
            // só relatório publicado aparece no portal, e é esse o cenário
            // que a varredura de isolamento precisa exercitar.
            $autorDoRelatorio = User::factory()->create(['is_active' => true]);
            $relatorio = MonitoringReport::query()->create([
                'client_id' => $cliente->id,
                'address_id' => $endereco->id,
                'periodo_inicio' => '2026-07-01',
                'periodo_fim' => '2026-07-31',
                'gerado_em' => now(),
                'gerado_por' => $autorDoRelatorio->id,
                'dados' => [
                    'client_id' => $cliente->id,
                    'address_id' => $endereco->id,
                    'periodo' => ['de' => '2026-07-01', 'ate' => '2026-07-31'],
                    'gerado_em' => now()->toIso8601String(),
                    'visitas' => ['quantidade' => 1, 'datas' => []],
                    'tendencia' => [],
                    'ranking_pontos_criticos' => [],
                    'mapa_de_calor' => [],
                    'ocorrencia_por_especie' => [],
                    'adequacoes' => [],
                ],
                'publicado_no_portal' => true,
            ]);

            return compact(
                'cliente', 'endereco', 'visita', 'certificado', 'contrato',
                'adequacao', 'fatura', 'nota', 'clientUser', 'solicitacao', 'relatorio'
            );
        });
    }

    private function autenticarComo(array $conjunto): void
    {
        /** @var ClientUser $clientUser */
        $clientUser = $conjunto['clientUser'];

        $this->post('/portal/login', [
            'email' => $clientUser->email,
            'password' => self::SENHA,
        ])->assertRedirect();
    }

    // -----------------------------------------------------------------
    // Apoio: roteador e URLs de teste
    // -----------------------------------------------------------------

    /**
     * Nome de toda rota nomeada `portal.*` registrada no roteador, pela
     * leitura de verdade de `Route::getRoutes()` (e não por uma lista fixa).
     *
     * @return array<int, string>
     */
    private function nomesDasRotasDoPortal(): array
    {
        $nomes = [];

        foreach (Route::getRoutes() as $rota) {
            $nome = $rota->getName();

            if ($nome !== null && str_starts_with($nome, 'portal.')) {
                $nomes[] = $nome;
            }
        }

        sort($nomes);

        return array_values(array_unique($nomes));
    }

    /** @return array{\Illuminate\Testing\TestResponse, array<string, mixed>} */
    private function respostaDaListagem(string $nomeRota): array
    {
        if ($nomeRota === 'portal.notas.index') {
            $resposta = $this->getJson(route($nomeRota))->assertOk();

            return [$resposta, $resposta->json()];
        }

        $resposta = $this->get(route($nomeRota))->assertOk();

        return [$resposta, $resposta->inertiaProps()];
    }

    /**
     * URL(s) de uma rota de detalhe do portal, com o id (e, quando aplicável,
     * o tipo) apontando para uma entidade do conjunto de fixture informado
     * (tipicamente "o outro cliente"). Rota de detalhe nova em
     * `routes/portal.php`, sem um `case` aqui, derruba o teste que a usa com
     * uma mensagem clara, em vez de silenciosamente não testar nada.
     *
     * @param  array<string, mixed>  $conjunto
     * @return array<int, string>
     */
    private function urlsDeDetalheParaOutro(string $nomeRota, array $conjunto): array
    {
        return match ($nomeRota) {
            'portal.visitas.show' => [route($nomeRota, ['id' => $conjunto['visita']->id])],
            'portal.solicitacoes.show' => [route($nomeRota, ['id' => $conjunto['solicitacao']->id])],
            'portal.documentos.download' => [
                route($nomeRota, ['tipo' => 'visita', 'id' => $conjunto['visita']->id]),
                route($nomeRota, ['tipo' => 'certificado', 'id' => $conjunto['certificado']->id]),
                route($nomeRota, ['tipo' => 'contrato', 'id' => $conjunto['contrato']->id]),
                route($nomeRota, ['tipo' => 'fatura', 'id' => $conjunto['fatura']->id]),
            ],
            'portal.notas.pdf', 'portal.notas.xml' => [
                route($nomeRota, ['nota' => $conjunto['nota']->id]),
            ],
            'portal.relatorios.show', 'portal.relatorios.pdf' => [
                route($nomeRota, ['monitoringReport' => $conjunto['relatorio']->id]),
            ],
            default => throw new RuntimeException(
                "Rota de detalhe '{$nomeRota}' não tem URL de teste mapeada em ".self::class.'::urlsDeDetalheParaOutro(). '
                .'Rota nova em ROTAS_DE_DETALHE precisa ganhar um case aqui antes de entrar na classificação, '
                .'senão fica classificada sem cobertura de isolamento nenhuma.'
            ),
        };
    }

    // -----------------------------------------------------------------
    // Apoio: travessia recursiva de array
    // -----------------------------------------------------------------

    /**
     * Percorre recursivamente um array (aninhado em qualquer profundidade,
     * incluindo a estrutura de paginação do `LengthAwarePaginator` serializada
     * pelo Inertia) e devolve todo valor encontrado sob uma chave literal
     * "id". Usada para afirmar ausência de id de outro cliente nas rotas de
     * listagem, sem depender de conhecer o formato exato de cada prop.
     *
     * @return array<int, int|string>
     */
    /**
     * Props que embutem outro tipo de entidade dentro de si (ex.:
     * `portal.contratos` acrescenta `proximas_visitas`, de
     * `PortalController::comProximasVisitas()`, dentro de cada contrato). Um
     * id de visita nessa prop aninhada não identifica o contrato, e contar
     * com ele quebraria a comparação por id assim que o autoincremento de
     * `work_orders` e de `contracts` divergir entre os testes.
     *
     * @var array<string, array<int, string>>
     */
    private const CHAVES_ANINHADAS_A_IGNORAR = [
        'portal.contratos' => ['proximas_visitas'],
    ];

    /**
     * Ids de um tipo específico dentro da resposta de uma rota de listagem,
     * restrito à prop que carrega aquele tipo (ver `ROTAS_DE_LISTAGEM`) e sem
     * as props aninhadas de outro tipo (ver `CHAVES_ANINHADAS_A_IGNORAR`).
     *
     * @param  array<string, mixed>  $dados
     * @return array<int, int|string>
     */
    private function idsDoTipo(array $dados, string $propKey, string $nomeRota): array
    {
        $itens = $dados[$propKey] ?? [];
        $chavesAIgnorar = self::CHAVES_ANINHADAS_A_IGNORAR[$nomeRota] ?? [];

        return $this->idsNoArray($this->semChaves($itens, $chavesAIgnorar));
    }

    private function semChaves(mixed $dado, array $chaves): mixed
    {
        if (! is_array($dado) || $chaves === []) {
            return $dado;
        }

        $resultado = [];

        foreach ($dado as $chave => $valor) {
            if (is_string($chave) && in_array($chave, $chaves, true)) {
                continue;
            }

            $resultado[$chave] = $this->semChaves($valor, $chaves);
        }

        return $resultado;
    }

    private function idsNoArray(mixed $dado): array
    {
        $ids = [];

        if (is_array($dado)) {
            foreach ($dado as $chave => $valor) {
                if ($chave === 'id') {
                    $ids[] = $valor;
                }

                $ids = array_merge($ids, $this->idsNoArray($valor));
            }
        }

        return $ids;
    }

    /**
     * Mesmo padrão de `AppDayLoadTest::todasAsChavesDoJson()` (Plano 12, Task
     * 12.12): percorre recursivamente uma estrutura decodificada (arrays
     * associativos e sequenciais, em qualquer profundidade) e devolve toda
     * chave de string encontrada, em minúsculas.
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
}
