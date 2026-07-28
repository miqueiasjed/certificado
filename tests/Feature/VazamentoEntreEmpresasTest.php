<?php

namespace Tests\Feature;

use App\Models\AccessLog;
use App\Models\ActiveIngredient;
use App\Models\Address;
use App\Models\Antidote;
use App\Models\AuditLog;
use App\Models\BaitType;
use App\Models\Budget;
use App\Models\Certificate;
use App\Models\ChemicalGroup;
use App\Models\Client;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractVisitJustification;
use App\Models\DailyCashBalance;
use App\Models\Device;
use App\Models\DeviceEvent;
use App\Models\DeviceReplacement;
use App\Models\EventType;
use App\Models\FinancialEntry;
use App\Models\Invitation;
use App\Models\NotificationLog;
use App\Models\NotificationQueue;
use App\Models\NotificationTemplate;
use App\Models\OnboardingStep;
use App\Models\OrganRegistration;
use App\Models\PaymentDetail;
use App\Models\PestSighting;
use App\Models\Procedure;
use App\Models\Product;
use App\Models\Room;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\ServiceType;
use App\Models\Technician;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderAdequation;
use App\Models\WorkOrderDeviceEvent;
use App\Models\WorkOrderPhoto;
use App\Services\WorkOrderService;
use App\Support\DominioMultiempresa;
use App\Support\TenantAtual;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/**
 * Task 4.11 do Plano 4: portão do segundo tenant.
 *
 * O cenário é sempre o mesmo. Duas empresas, cada uma com um administrador e um
 * conjunto completo de dado (cliente, endereço, cômodo, dispositivo, OS,
 * certificado, contrato, orçamento, lançamento financeiro, produto, técnico e o
 * resto dos models de domínio). Tudo do tenant 2 nasce carimbado com a marca
 * `MARCAT2` e tudo do tenant 1 com `MARCAT1`. Autenticado como o administrador
 * do tenant 1, nenhuma tela, endpoint, busca, contador ou PDF pode devolver a
 * marca do tenant 2.
 *
 * Duas asserções diferentes rodam sobre cada resposta:
 *
 * 1. **Textual**: a marca do tenant 2 não aparece no corpo. Pega o registro que
 *    vazou inteiro, com nome, número e descrição.
 * 2. **Estrutural**: nenhum objeto serializado na resposta tem
 *    `company_id = 2`. Pega o vazamento que a marca textual não pegaria, por
 *    exemplo uma listagem que devolvesse só ids e chaves estrangeiras.
 *
 * A varredura de rotas do caso 1 sai da coleção de rotas registradas, e não de
 * uma lista escrita à mão: rota nova de domínio entra sozinha na próxima
 * execução.
 *
 * VAZAMENTO REAL ENCONTRADO POR ESTE TESTE
 * ----------------------------------------
 * `test_administrador_do_tenant_1_nao_altera_usuario_do_tenant_2` e
 * `test_administrador_do_tenant_1_nao_desativa_usuario_do_tenant_2` falham
 * hoje, e falham de propósito: elas pegaram um defeito de verdade nas rotas
 * `PUT /settings/users/{user}` e `PATCH /settings/users/{user}/status`. O
 * detalhe está no docblock de cada uma. Não corrija apagando o teste.
 *
 * Banco: esta suíte roda isolada, em `testing_p4b`
 * (`DB_DATABASE=testing_p4b php artisan test --filter=VazamentoEntreEmpresasTest`).
 */
class VazamentoEntreEmpresasTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Carimbo de cada tenant. Nenhum é substring do outro, então a busca
     * textual não confunde um com o outro. Sem acento e sem espaço de
     * propósito: JSON escapa acento em \uXXXX e a marca deixaria de casar.
     */
    private const MARCA_UM = 'MARCAT1';

    private const MARCA_DOIS = 'MARCAT2';

    /**
     * Rotas GET sem parâmetro que a varredura do caso 1 não visita, cada uma
     * com o motivo. Lista curta de propósito: o valor do caso 1 está em
     * percorrer tudo.
     *
     * @var array<string, string>
     */
    private const ROTAS_FORA_DA_VARREDURA = [
        'logout' => 'Encerra a sessão e derrubaria as rotas seguintes da mesma varredura.',
    ];

    /**
     * Rotas que já respondem com erro de servidor antes deste teste existir,
     * por defeito sem relação com multiempresa. Continuam sendo varridas em
     * busca de vazamento; o que a lista dispensa é a exigência de status < 500.
     *
     * Tirar uma daqui quando o defeito for corrigido é o comportamento
     * esperado: rota nova quebrada não entra sozinha.
     *
     * @var array<string, string>
     */
    private const ROTAS_COM_ERRO_CONHECIDO = [
        // Vazia por enquanto. As duas entradas de `device-events` saíram daqui
        // quando o eager load de `device.room` foi corrigido para `device.address`.
    ];

    /**
     * Recursos cujo acesso por id de outra empresa não devolve 404, com o
     * status que devolvem hoje e o motivo. Nenhum deles expõe ou altera o
     * registro (isso é conferido em
     * `test_acesso_direto_por_id_nao_expoe_nem_altera_registro_de_outra_empresa`),
     * mas todos fogem da convenção de resposta da skill.
     *
     * Chave: `<base do resource>.<ação>`.
     *
     * @var array<string, array{status: int, motivo: string}>
     */
    private const RESPOSTAS_FORA_DO_404 = [
        'clients.update' => [
            'status' => 302,
            'motivo' => 'ClientController@update recebe int $id, consulta pelo Service e devolve back()->with(error) quando não acha, em vez de 404.',
        ],
        'clients.destroy' => [
            'status' => 302,
            'motivo' => 'ClientController@destroy tem o mesmo caminho do update.',
        ],
        'products.update' => [
            'status' => 302,
            'motivo' => 'ProductController@update resolve o produto por id e redireciona com erro quando não acha.',
        ],
        'products.destroy' => [
            'status' => 302,
            'motivo' => 'ProductController@destroy tem o mesmo caminho do update.',
        ],
        'financial-withdrawals.update' => [
            'status' => 302,
            'motivo' => 'FinancialWithdrawalController resolve a saída por id e redireciona com erro quando não acha.',
        ],
        'financial-withdrawals.destroy' => [
            'status' => 302,
            'motivo' => 'Mesmo caminho do update.',
        ],
        'bait-types.show' => [
            'status' => 200,
            'motivo' => 'BaitTypeController ainda é o esqueleto do artisan: show/edit/update/destroy têm corpo vazio e devolvem 200 sem nada. Não vaza porque não responde nada; passa a vazar no dia em que alguém preencher os métodos sem escopo.',
        ],
        'bait-types.edit' => [
            'status' => 200,
            'motivo' => 'Método vazio do esqueleto do artisan.',
        ],
        'bait-types.update' => [
            'status' => 200,
            'motivo' => 'Método vazio do esqueleto do artisan.',
        ],
        'bait-types.destroy' => [
            'status' => 200,
            'motivo' => 'Método vazio do esqueleto do artisan: nada é excluído.',
        ],
    ];

    private Company $empresaUm;

    private Company $empresaDois;

    private User $administradorUm;

    private User $administradorDois;

    /** @var array<string, Model> */
    private array $dadosUm = [];

    /** @var array<string, Model> */
    private array $dadosDois = [];

    /** @var array<int, string> */
    private array $marcasDaEmpresaDois = [];

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();

        // Sem página de depuração. Ela imprime o código-fonte dos arquivos da
        // pilha, inclusive o deste teste, e as marcas dos dois tenants
        // apareceriam em qualquer 500 como falso positivo. Produção roda com
        // debug desligado, que é o cenário que interessa aqui.
        config(['app.debug' => false]);

        $this->seed(RolesAndPermissionsSeeder::class);

        // Task 6.4: `/financial-dashboard` e `/cash-flow/stats` acumulam
        // `module:financeiro`. `ModulesSeeder` vincula o tenant fundador
        // (empresa 1, preparada logo abaixo) ao "Plano Interno", com todos
        // os módulos liberados, sem o qual `administradorUm` seria barrado
        // mesmo tendo a permissão financeira.
        $this->seed(ModulesSeeder::class);

        $this->empresaUm = $this->prepararEmpresaFundadora();
        $this->empresaDois = Company::create([
            'name' => 'Dedetizadora '.self::MARCA_DOIS,
            'cnpj' => '22.222.222/0001-22',
            'email' => 'contato@'.strtolower(self::MARCA_DOIS).'.test',
        ]);

        $this->administradorUm = $this->criarAdministrador($this->empresaUm->id, self::MARCA_UM);
        $this->administradorDois = $this->criarAdministrador($this->empresaDois->id, self::MARCA_DOIS);

        $this->dadosUm = $this->criarConjuntoCompleto($this->empresaUm->id, self::MARCA_UM, $this->administradorUm);
        $this->dadosDois = $this->criarConjuntoCompleto($this->empresaDois->id, self::MARCA_DOIS, $this->administradorDois);

        $this->marcasDaEmpresaDois = $this->marcasTextuais(self::MARCA_DOIS, $this->administradorDois, $this->dadosDois);

        TenantAtual::limpar();
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Caso 1: listagens e telas sem parâmetro
    // -----------------------------------------------------------------

    /**
     * Percorre toda rota GET sem parâmetro protegida por `auth` e exige que
     * nenhuma devolva dado do tenant 2. A lista sai da coleção de rotas
     * registradas: index, create, dashboards, estatísticas, exportação e
     * configurações entram juntos, e rota nova entra sozinha.
     */
    public function test_nenhuma_tela_de_listagem_do_tenant_1_devolve_dado_do_tenant_2(): void
    {
        $rotas = $this->rotasDeVarredura();

        $this->assertGreaterThanOrEqual(
            50,
            count($rotas),
            'a varredura encontrou rotas de menos: o filtro da coleção de rotas provavelmente quebrou'
        );

        foreach ($rotas as $uri) {
            $resposta = $this->actingAs($this->administradorUm)->get("/{$uri}");

            if (! array_key_exists($uri, self::ROTAS_COM_ERRO_CONHECIDO)) {
                $this->assertLessThan(
                    500,
                    $resposta->status(),
                    "a rota GET /{$uri} respondeu {$resposta->status()}: erro de servidor esconde vazamento"
                );
            }

            $this->assertRespostaSemDadoDaEmpresaDois($resposta, "GET /{$uri}");
        }
    }

    /**
     * Rede contra varredura vazia. Se as telas do tenant 1 também não
     * mostrassem nada, o teste acima passaria sem provar coisa alguma.
     */
    public function test_a_varredura_enxerga_o_proprio_dado_do_tenant_1(): void
    {
        $encontradas = [];

        foreach ($this->rotasDeVarredura() as $uri) {
            $corpo = $this->corpoDaResposta($this->actingAs($this->administradorUm)->get("/{$uri}"));

            if (str_contains($corpo, self::MARCA_UM)) {
                $encontradas[] = $uri;
            }
        }

        $this->assertGreaterThanOrEqual(
            20,
            count($encontradas),
            'as telas do tenant 1 quase não mostraram o próprio dado: a varredura do caso 1 estaria passando à toa'
        );
    }

    /**
     * A listagem de usuários é o ponto mais frágil do isolamento, porque
     * `User` é o único model de domínio sem escopo global de leitura. A
     * proteção é o `daEmpresaAtual()` aplicado à mão no controller, e é ele
     * que este teste prende.
     */
    public function test_listagem_de_usuarios_nao_mostra_usuario_de_outra_empresa(): void
    {
        $resposta = $this->actingAs($this->administradorUm)->get('/settings/users');

        $resposta->assertOk();

        $emails = collect($resposta->inertiaProps('users'))->pluck('email')->all();

        $this->assertContains($this->administradorUm->email, $emails, 'o administrador do tenant 1 deveria aparecer na própria listagem');
        $this->assertNotContains(
            $this->administradorDois->email,
            $emails,
            'a tela de usuários mostrou o usuário de outra empresa: o daEmpresaAtual() do UserController caiu'
        );
    }

    // -----------------------------------------------------------------
    // Caso 2: acesso direto por id (route-model binding)
    // -----------------------------------------------------------------

    /**
     * A asserção que não admite exceção: GET, PUT e DELETE em cada recurso do
     * tenant 2, autenticado como tenant 1, não podem devolver nenhum dado dele
     * nem alterar o registro.
     *
     * Remover a trait `BelongsToCompany` de um model faz este teste falhar para
     * o recurso correspondente, que é o critério de aceitação da task.
     */
    public function test_acesso_direto_por_id_nao_expoe_nem_altera_registro_de_outra_empresa(): void
    {
        $conferidas = 0;

        foreach ($this->chamadasDiretasAosRecursosDoTenantDois() as [$metodo, $chaveDaRota, $url, $registro]) {
            $antes = $this->atributosNaEmpresaDois($registro);

            $resposta = $this->actingAs($this->administradorUm)->call($metodo, $url);

            $this->assertRespostaSemDadoDaEmpresaDois($resposta, "{$metodo} {$url}");

            $this->assertSame(
                $antes,
                $this->atributosNaEmpresaDois($registro),
                "{$metodo} {$url} alterou ou excluiu um registro da empresa 2"
            );

            $conferidas++;
        }

        $this->assertGreaterThanOrEqual(
            80,
            $conferidas,
            'poucas combinações de recurso e verbo foram conferidas: o mapa de recursos ficou desatualizado'
        );
    }

    /**
     * A convenção da skill: 404 para recurso de outra empresa, nunca 403 e
     * nunca 200. Os desvios conhecidos estão declarados em
     * `RESPOSTAS_FORA_DO_404`, com o status de hoje e o motivo. Desvio novo
     * derruba este teste; desvio corrigido também, e aí é só tirar da lista.
     */
    public function test_acesso_direto_por_id_a_recurso_de_outra_empresa_devolve_404(): void
    {
        foreach ($this->chamadasDiretasAosRecursosDoTenantDois() as [$metodo, $chaveDaRota, $url, $_]) {
            $resposta = $this->actingAs($this->administradorUm)->call($metodo, $url);

            $declarado = self::RESPOSTAS_FORA_DO_404[$chaveDaRota] ?? null;

            if ($declarado !== null) {
                $this->assertSame(
                    $declarado['status'],
                    $resposta->status(),
                    "{$metodo} {$url} mudou de comportamento. Desvio declarado: {$declarado['motivo']}"
                );

                continue;
            }

            $this->assertSame(
                404,
                $resposta->status(),
                "{$metodo} {$url} devolveu {$resposta->status()} para registro da empresa 2: o escopo por empresa não pegou o binding de {$chaveDaRota}"
            );
        }
    }

    /**
     * O mesmo acesso direto, feito pelo dono, precisa funcionar. Sem isto o
     * teste acima passaria com uma rota quebrada que devolve 404 para todo
     * mundo.
     */
    public function test_o_dono_continua_abrindo_os_proprios_recursos(): void
    {
        $abertos = 0;

        foreach ($this->basesDeRecurso() as $base => $chave) {
            if (! Route::has("{$base}.show")) {
                continue;
            }

            $registro = $this->dadosUm[$chave];

            $resposta = $this->actingAs($this->administradorUm)->get("/{$base}/{$registro->id}");

            $this->assertNotSame(
                404,
                $resposta->status(),
                "GET /{$base}/{$registro->id} devolveu 404 para o próprio dono: o escopo por empresa está barrando quem deveria passar"
            );

            $abertos++;
        }

        $this->assertGreaterThanOrEqual(15, $abertos, 'quase nenhum recurso foi aberto pelo dono: o mapa de recursos ficou desatualizado');
    }

    /**
     * Endpoints aninhados e de apoio que recebem um model por binding fora do
     * resource. São os que mais escapam da revisão, porque a tela nunca mostra
     * o link.
     */
    public function test_endpoints_aninhados_de_outra_empresa_devolvem_404(): void
    {
        $dois = $this->dadosDois;

        $casos = [
            ['GET', "/api/clients/{$dois['client']->id}/addresses"],
            ['GET', "/addresses/{$dois['address']->id}/devices"],
            ['GET', "/addresses/{$dois['address']->id}/contract/pdf"],
            ['GET', "/addresses/{$dois['address']->id}/contracts/create"],
            ['POST', "/addresses/{$dois['address']->id}/devices"],
            ['PUT', "/addresses/{$dois['address']->id}/devices/{$dois['device']->id}"],
            ['DELETE', "/addresses/{$dois['address']->id}/devices/{$dois['device']->id}"],
            ['DELETE', "/addresses/{$dois['address']->id}/rooms/{$dois['room']->id}"],
            ['GET', "/devices/{$dois['device']->id}/can-delete"],
            ['GET', "/contracts/{$dois['contract']->id}/visitas"],
            ['POST', "/contracts/{$dois['contract']->id}/visitas/gerar"],
            ['GET', "/work-orders/{$dois['workOrder']->id}/rooms/available"],
            ['GET', "/work-orders/{$dois['workOrder']->id}/payment-details"],
            ['POST', "/work-orders/{$dois['workOrder']->id}/certificates"],
            ['POST', "/work-orders/{$dois['workOrder']->id}/adequations"],
            ['PUT', "/work-orders/{$dois['workOrder']->id}/adequations/{$dois['adequation']->id}"],
            ['DELETE', "/work-orders/{$dois['workOrder']->id}/adequations/{$dois['adequation']->id}"],
            ['POST', "/work-orders/{$dois['workOrder']->id}/photos"],
            ['DELETE', "/work-orders/{$dois['workOrder']->id}/photos/{$dois['photo']->id}"],
            ['POST', "/work-orders/{$dois['workOrder']->id}/products/{$dois['product']->id}"],
            ['DELETE', "/work-orders/{$dois['workOrder']->id}/products/{$dois['product']->id}"],
            ['POST', "/work-orders/{$dois['workOrder']->id}/services/{$dois['service']->id}"],
            ['DELETE', "/work-orders/{$dois['workOrder']->id}/services/{$dois['service']->id}"],
            ['POST', "/work-orders/{$dois['workOrder']->id}/technicians/{$dois['technician']->id}"],
            ['DELETE', "/work-orders/{$dois['workOrder']->id}/technicians/{$dois['technician']->id}"],
            ['POST', "/work-orders/{$dois['workOrder']->id}/rooms"],
            ['PUT', "/work-orders/{$dois['workOrder']->id}/financial-info"],
            ['POST', "/payment-details/{$dois['paymentDetail']->id}/reopen"],
            ['POST', "/payment-details/{$dois['paymentDetail']->id}/create-financial-entry"],
            ['POST', "/budgets/{$dois['budget']->id}/convert"],
        ];

        foreach ($casos as [$metodo, $url]) {
            $resposta = $this->actingAs($this->administradorUm)->call($metodo, $url);

            $this->assertSame(
                404,
                $resposta->status(),
                "{$metodo} {$url} devolveu {$resposta->status()} para registro da empresa 2, e deveria devolver 404"
            );
        }
    }

    /**
     * O histórico de auditoria é consultado por tipo e id vindos da URL. O
     * escopo global de `AuditLog` é a única coisa que impede o administrador de
     * uma empresa de ler o que a outra alterou.
     */
    public function test_historico_de_auditoria_de_registro_de_outra_empresa_vem_vazio(): void
    {
        $resposta = $this->actingAs($this->administradorUm)
            ->getJson("/audit-logs/cliente/{$this->dadosDois['client']->id}");

        $resposta->assertOk();

        $this->assertSame(
            0,
            $resposta->json('total'),
            'o histórico de auditoria devolveu registros de um cliente de outra empresa'
        );

        $this->assertRespostaSemDadoDaEmpresaDois($resposta, 'GET /audit-logs/cliente/{id}');
    }

    // -----------------------------------------------------------------
    // Caso 3: buscas
    // -----------------------------------------------------------------

    /**
     * Busca de cliente por texto. A asserção é sobre a lista devolvida, e não
     * sobre o corpo inteiro: a tela devolve o termo pesquisado de volta na prop
     * `search`, então procurar a marca no HTML acusaria o próprio termo.
     */
    public function test_busca_de_cliente_por_texto_nao_encontra_cliente_de_outra_empresa(): void
    {
        $termos = [
            self::MARCA_DOIS,
            $this->dadosDois['client']->name,
            $this->dadosDois['client']->email,
            $this->dadosDois['client']->cnpj,
        ];

        foreach ($termos as $termo) {
            $resposta = $this->actingAs($this->administradorUm)->get('/clients?search='.rawurlencode($termo));

            $resposta->assertOk();

            $this->assertSame(
                [],
                $resposta->inertiaProps('clients.data'),
                "a busca por '{$termo}' devolveu cliente de outra empresa"
            );
        }
    }

    public function test_endpoints_de_busca_por_id_nao_devolvem_registro_de_outra_empresa(): void
    {
        $dois = $this->dadosDois;

        $buscas = [
            "/work-orders/client/{$dois['client']->id}",
            "/devices/address/{$dois['address']->id}",
            "/rooms/address/{$dois['address']->id}",
            "/addresses/client/{$dois['client']->id}",
            '/addresses/city/'.rawurlencode($dois['address']->city),
            '/addresses/state/'.rawurlencode($dois['address']->state),
            "/work-orders/rooms/by-client?client_id={$dois['client']->id}",
            "/work-orders/devices/by-address?address_id={$dois['address']->id}",
            "/service-orders/rooms/by-client?client_id={$dois['client']->id}",
        ];

        foreach ($buscas as $url) {
            $resposta = $this->actingAs($this->administradorUm)->get($url);

            $this->assertLessThan(
                500,
                $resposta->status(),
                "a busca {$url} respondeu {$resposta->status()}: erro de servidor esconde vazamento"
            );

            $this->assertRespostaSemDadoDaEmpresaDois($resposta, "GET {$url}");
        }
    }

    /**
     * A busca por dado do próprio tenant continua achando. Sem isto, as
     * asserções acima passariam com a busca quebrada.
     */
    public function test_a_busca_do_tenant_1_ainda_encontra_o_proprio_cliente(): void
    {
        $resposta = $this->actingAs($this->administradorUm)->get('/clients?search='.self::MARCA_UM);

        $resposta->assertOk();
        $this->assertCount(1, $resposta->inertiaProps('clients.data'));

        $porEndereco = $this->actingAs($this->administradorUm)
            ->getJson("/devices/address/{$this->dadosUm['address']->id}");

        $porEndereco->assertOk();
        $this->assertStringContainsString(self::MARCA_UM, $this->corpoDaResposta($porEndereco));
    }

    // -----------------------------------------------------------------
    // Caso 4: contadores e dashboards
    // -----------------------------------------------------------------

    public function test_contadores_do_dashboard_nao_somam_registro_de_outra_empresa(): void
    {
        $resposta = $this->actingAs($this->administradorUm)->get('/dashboard');

        $resposta->assertOk();

        $stats = $resposta->inertiaProps('stats');

        foreach ([
            'total_clients',
            'total_products',
            'total_technicians',
            'total_services',
            'total_work_orders',
            'total_certificates',
        ] as $contador) {
            $this->assertSame(
                1,
                $stats[$contador],
                "o contador {$contador} do dashboard somou registro de outra empresa: cada tenant tem exatamente 1"
            );
        }

        $this->assertRespostaSemDadoDaEmpresaDois($resposta, 'GET /dashboard');
    }

    public function test_totais_do_dashboard_financeiro_nao_incluem_valor_de_outra_empresa(): void
    {
        $resposta = $this->actingAs($this->administradorUm)->get('/financial-dashboard');

        $resposta->assertOk();

        $stats = $resposta->inertiaProps('stats');

        $this->assertSame(
            '111.11',
            (string) $stats['manual_amount'],
            'a entrada manual do dashboard financeiro somou o valor da outra empresa'
        );
        $this->assertSame(
            '222.22',
            (string) $stats['withdrawal_amount'],
            'a saída do dashboard financeiro somou o valor da outra empresa'
        );
        $this->assertSame(1, $stats['total_entries'], 'o dashboard financeiro contou lançamento de outra empresa');

        $this->assertRespostaSemDadoDaEmpresaDois($resposta, 'GET /financial-dashboard');
    }

    public function test_estatisticas_do_fluxo_de_caixa_nao_incluem_valor_de_outra_empresa(): void
    {
        $resposta = $this->actingAs($this->administradorUm)->getJson('/cash-flow/stats');

        $resposta->assertOk();

        $corpo = $this->corpoDaResposta($resposta);

        $this->assertStringNotContainsString('777.77', $corpo, 'o fluxo de caixa trouxe a entrada exclusiva da outra empresa');
        $this->assertStringNotContainsString('888.88', $corpo, 'o fluxo de caixa trouxe a saída exclusiva da outra empresa');
        $this->assertRespostaSemDadoDaEmpresaDois($resposta, 'GET /cash-flow/stats');
    }

    // -----------------------------------------------------------------
    // Caso 5: numeração de ordem de serviço
    // -----------------------------------------------------------------

    public function test_os_dois_tenants_podem_usar_o_mesmo_numero_de_ordem(): void
    {
        $numero = 'OS-COMPARTILHADA-0001';

        $daUm = TenantAtual::comTenant($this->empresaUm->id, fn () => WorkOrder::create([
            'order_number' => $numero,
            'client_id' => $this->dadosUm['client']->id,
            'address_id' => $this->dadosUm['address']->id,
            'scheduled_date' => '2026-07-10',
            'status' => 'pending',
        ]));

        $daDois = TenantAtual::comTenant($this->empresaDois->id, fn () => WorkOrder::create([
            'order_number' => $numero,
            'client_id' => $this->dadosDois['client']->id,
            'address_id' => $this->dadosDois['address']->id,
            'scheduled_date' => '2026-07-10',
            'status' => 'pending',
        ]));

        $this->assertNotSame($daUm->id, $daDois->id);
        $this->assertSame($this->empresaUm->id, (int) $daUm->company_id);
        $this->assertSame($this->empresaDois->id, (int) $daDois->company_id);
    }

    public function test_numero_de_ordem_repetido_dentro_do_mesmo_tenant_continua_falhando(): void
    {
        $this->expectException(QueryException::class);

        TenantAtual::comTenant($this->empresaUm->id, function (): void {
            foreach ([1, 2] as $_) {
                WorkOrder::create([
                    'order_number' => 'OS-REPETIDA-0001',
                    'client_id' => $this->dadosUm['client']->id,
                    'address_id' => $this->dadosUm['address']->id,
                    'scheduled_date' => '2026-07-10',
                    'status' => 'pending',
                ]);
            }
        });
    }

    public function test_os_dois_tenants_podem_usar_o_mesmo_numero_de_servico_agendado(): void
    {
        $numero = 'SA-COMPARTILHADO-0001';

        $daUm = TenantAtual::comTenant($this->empresaUm->id, fn () => ServiceOrder::create([
            'client_id' => $this->dadosUm['client']->id,
            'order_number' => $numero,
            'order_date' => '2026-07-10',
            'start_time' => '08:00',
            'end_time' => '09:00',
            'status' => 'pending',
        ]));

        $daDois = TenantAtual::comTenant($this->empresaDois->id, fn () => ServiceOrder::create([
            'client_id' => $this->dadosDois['client']->id,
            'order_number' => $numero,
            'order_date' => '2026-07-10',
            'start_time' => '08:00',
            'end_time' => '09:00',
            'status' => 'pending',
        ]));

        $this->assertNotSame($daUm->id, $daDois->id);
    }

    // -----------------------------------------------------------------
    // Caso 6: saldo diário e demais uniques compostas
    // -----------------------------------------------------------------

    /**
     * Caso apontado no `divida-tecnica.md` como falha silenciosa: com a unique
     * global em `balance_date`, o segundo tenant nunca conseguia abrir o caixa
     * do dia e o financeiro dele parava sem erro visível.
     */
    public function test_os_dois_tenants_podem_ter_saldo_diario_da_mesma_data(): void
    {
        $data = '2026-07-15';

        $daUm = TenantAtual::comTenant($this->empresaUm->id, fn () => DailyCashBalance::create([
            'balance_date' => $data,
            'opening_balance' => 10,
        ]));

        $daDois = TenantAtual::comTenant($this->empresaDois->id, fn () => DailyCashBalance::create([
            'balance_date' => $data,
            'opening_balance' => 20,
        ]));

        $this->assertNotSame($daUm->id, $daDois->id);
        $this->assertSame($this->empresaDois->id, (int) $daDois->company_id);

        $doTenantUm = TenantAtual::comTenant(
            $this->empresaUm->id,
            fn () => DailyCashBalance::query()->where('balance_date', $data)->get()
        );

        $this->assertCount(1, $doTenantUm, 'o tenant 1 enxergou o saldo diário do tenant 2');
    }

    public function test_saldo_diario_repetido_dentro_do_mesmo_tenant_continua_falhando(): void
    {
        $this->expectException(QueryException::class);

        TenantAtual::comTenant($this->empresaUm->id, function (): void {
            foreach ([1, 2] as $_) {
                DailyCashBalance::create(['balance_date' => '2026-07-16']);
            }
        });
    }

    public function test_os_dois_tenants_podem_ter_tecnico_com_o_mesmo_email(): void
    {
        $email = 'mesmo.tecnico@exemplo.test';

        $daUm = TenantAtual::comTenant($this->empresaUm->id, fn () => Technician::create([
            'name' => 'Técnico compartilhado',
            'email' => $email,
            'phone' => '11900000000',
        ]));

        $daDois = TenantAtual::comTenant($this->empresaDois->id, fn () => Technician::create([
            'name' => 'Técnico compartilhado',
            'email' => $email,
            'phone' => '11900000000',
        ]));

        $this->assertNotSame($daUm->id, $daDois->id);
    }

    public function test_os_dois_tenants_podem_ter_tipo_de_servico_com_o_mesmo_slug(): void
    {
        $nome = 'Desinsetizacao Compartilhada';

        $daUm = TenantAtual::comTenant($this->empresaUm->id, fn () => ServiceType::create(['name' => $nome]));
        $daDois = TenantAtual::comTenant($this->empresaDois->id, fn () => ServiceType::create(['name' => $nome]));

        $this->assertSame($daUm->slug, $daDois->slug, 'o cenário exige o mesmo slug nos dois tenants');
        $this->assertNotSame($daUm->id, $daDois->id);
    }

    /**
     * `users.email` continua unique global de propósito, porque o login é por
     * e-mail. A decisão está no PRD e em `DominioMultiempresa`, e o teste existe
     * para que ninguém a mude sem perceber.
     */
    public function test_email_de_usuario_continua_unico_entre_empresas(): void
    {
        $this->expectException(QueryException::class);

        TenantAtual::comTenant(
            $this->empresaDois->id,
            fn () => User::factory()->create(['email' => $this->administradorUm->email])
        );
    }

    /**
     * Toda unique declarada em `DominioMultiempresa::UNIQUES_A_COMPOR` já está
     * composta com `company_id` no banco. Sem isto, o segundo tenant colide com
     * o primeiro na primeira gravação.
     */
    public function test_as_uniques_de_dominio_estao_compostas_com_company_id(): void
    {
        foreach (DominioMultiempresa::UNIQUES_A_COMPOR as $tabela => $esperada) {
            $esperadas = array_merge([DominioMultiempresa::COLUNA_TENANT], $esperada['colunas']);
            sort($esperadas);

            $encontrada = false;

            foreach (DB::select("SHOW INDEX FROM {$tabela}") as $indice) {
                if ((int) $indice->Non_unique === 1 || $indice->Key_name === 'PRIMARY') {
                    continue;
                }

                $colunas = collect(DB::select("SHOW INDEX FROM {$tabela}"))
                    ->where('Key_name', $indice->Key_name)
                    ->sortBy('Seq_in_index')
                    ->pluck('Column_name')
                    ->all();

                sort($colunas);

                if ($colunas === $esperadas) {
                    $encontrada = true;
                    break;
                }
            }

            $this->assertTrue(
                $encontrada,
                "a unique de {$tabela} (".implode(', ', $esperada['colunas']).") não está composta com company_id. {$esperada['motivo']}"
            );
        }
    }

    // -----------------------------------------------------------------
    // Caso 7: documentos em PDF
    // -----------------------------------------------------------------

    public function test_pdf_de_documento_de_outra_empresa_devolve_404(): void
    {
        $dois = $this->dadosDois;

        $pdfs = [
            "/certificates/{$dois['certificate']->id}/pdf",
            "/work-orders/{$dois['workOrder']->id}/pdf",
            "/service-orders/{$dois['serviceOrder']->id}/pdf",
            "/budgets/{$dois['budget']->id}/pdf",
            "/addresses/{$dois['address']->id}/contract/pdf",
        ];

        foreach ($pdfs as $url) {
            $resposta = $this->actingAs($this->administradorUm)->get($url);

            $this->assertSame(
                404,
                $resposta->status(),
                "{$url} devolveu {$resposta->status()}: documento de outra empresa não pode ser emitido"
            );
        }
    }

    public function test_pdf_do_proprio_certificado_continua_saindo(): void
    {
        $resposta = $this->actingAs($this->administradorUm)
            ->get("/certificates/{$this->dadosUm['certificate']->id}/pdf");

        $resposta->assertOk();
        $resposta->assertHeader('content-type', 'application/pdf');
    }

    // -----------------------------------------------------------------
    // Recibo assinado: rota pública, tenant vindo da própria OS
    // -----------------------------------------------------------------

    /**
     * A rota do recibo é a única sem login, e resolve a empresa pelo
     * `company_id` da ordem. `ReciboAssinadoTest` já cobre a assinatura, a
     * validade e a empresa impressa no cabeçalho; o que falta, e é o que este
     * teste faz, é conferir que o conteúdo do recibo de uma ordem do tenant 2
     * não traz nenhum dado do tenant 1.
     */
    public function test_recibo_assinado_de_ordem_do_tenant_2_nao_traz_dado_do_tenant_1(): void
    {
        $url = $this->urlDoReciboDaOrdemDoTenantDois();

        $resposta = $this->get($url);

        $resposta->assertOk();

        $corpo = $this->corpoDaResposta($resposta);

        foreach ($this->marcasTextuais(self::MARCA_UM, $this->administradorUm, $this->dadosUm) as $marca) {
            $this->assertStringNotContainsString(
                $marca,
                $corpo,
                "o recibo da ordem do tenant 2 trouxe '{$marca}', que é dado do tenant 1"
            );
        }
    }

    /**
     * Com uma sessão do tenant 1 aberta no mesmo navegador, o link assinado do
     * tenant 2 devolve 404. O escopo global resolve o tenant pelo usuário
     * autenticado e o binding não encontra a ordem, o que é o lado seguro: o
     * recibo nunca sai misturando as duas empresas. Fica registrado porque é
     * comportamento observável, e não acidente.
     */
    public function test_recibo_do_tenant_2_nao_abre_com_sessao_do_tenant_1(): void
    {
        $url = $this->urlDoReciboDaOrdemDoTenantDois();

        $resposta = $this->actingAs($this->administradorUm)->get($url);

        $this->assertSame(404, $resposta->status());
    }

    // -----------------------------------------------------------------
    // Gestão de usuários: o model sem escopo global de leitura
    // -----------------------------------------------------------------

    /**
     * VAZAMENTO REAL. Este teste falha hoje.
     *
     * `User` é o único model de domínio com o escopo global de leitura
     * desligado, então o route-model binding de `{user}` encontra usuário de
     * qualquer empresa, e `UserController@update` age sobre ele sem conferir
     * `company_id`. O administrador da empresa A troca nome, e-mail e papel do
     * administrador da empresa B, e a resposta é 302 de sucesso para
     * `/settings/users`.
     *
     * Reprodução: autenticado como administrador da empresa A,
     * `PUT /settings/users/{id de um usuário da empresa B}` com nome, e-mail e
     * papel. O registro da empresa B fica com os valores enviados, e o
     * `company_id` dele nem muda, então a vítima continua na própria empresa,
     * agora com o e-mail de login trocado por outra pessoa.
     *
     * Correção sugerida: escopar o binding no controller, com
     * `User::daEmpresaAtual()->findOrFail()` no lugar do binding automático, ou
     * um `abort_unless($user->company_id === TenantAtual::exigirId(), 404)` na
     * entrada de `update()`, `alterarStatus()` e de qualquer rota nova que
     * receba `{user}`.
     */
    public function test_administrador_do_tenant_1_nao_altera_usuario_do_tenant_2(): void
    {
        $nomeOriginal = $this->administradorDois->name;
        $emailOriginal = $this->administradorDois->email;

        $resposta = $this->actingAs($this->administradorUm)
            ->put("/settings/users/{$this->administradorDois->id}", [
                'name' => 'Sequestrado pelo tenant 1',
                'email' => 'sequestrado@exemplo.test',
                'role' => 'leitura',
            ]);

        $depois = $this->administradorDois->fresh();

        $this->assertSame($nomeOriginal, $depois->name, 'o nome do usuário da outra empresa foi alterado');
        $this->assertSame($emailOriginal, $depois->email, 'o e-mail do usuário da outra empresa foi alterado');
        $this->assertTrue($depois->hasRole('administrador'), 'o papel do usuário da outra empresa foi trocado');

        $this->assertSame(
            404,
            $resposta->status(),
            'editar usuário de outra empresa devolveu '.$resposta->status().' em vez de 404'
        );
    }

    /**
     * VAZAMENTO REAL. Este teste falha hoje, pela mesma causa do anterior.
     *
     * Reprodução: autenticado como administrador da empresa A,
     * `PATCH /settings/users/{id de um usuário da empresa B}/status` com
     * `is_active = false`. A empresa B fica sem o administrador dela, e o
     * `garantirAdministradorRestante()` não segura, porque ele roda com o
     * tenant de quem pediu (empresa A), onde ainda sobram administradores.
     */
    public function test_administrador_do_tenant_1_nao_desativa_usuario_do_tenant_2(): void
    {
        $resposta = $this->actingAs($this->administradorUm)
            ->patch("/settings/users/{$this->administradorDois->id}/status", ['is_active' => false]);

        $this->assertTrue(
            (bool) $this->administradorDois->fresh()->is_active,
            'o usuário da outra empresa foi desativado por um administrador que não é da empresa dele'
        );

        $this->assertSame(
            404,
            $resposta->status(),
            'desativar usuário de outra empresa devolveu '.$resposta->status().' em vez de 404'
        );
    }

    /**
     * A empresa não pode ficar sem administrador ativo só porque a outra
     * empresa tem administradores. A contagem de
     * `UserService::garantirAdministradorRestante()` é escopada com
     * `daEmpresaAtual()`, e é isso que este teste prende.
     */
    public function test_nao_da_para_desativar_o_ultimo_administrador_de_uma_empresa(): void
    {
        $outroDoTenantUm = TenantAtual::comTenant(
            $this->empresaUm->id,
            fn () => User::factory()->create(['name' => 'Auxiliar '.self::MARCA_UM])
        );
        $outroDoTenantUm->assignRole('administrador');

        // Com dois administradores na empresa 1, desativar um é permitido.
        $this->actingAs($outroDoTenantUm)
            ->patch("/settings/users/{$this->administradorUm->id}/status", ['is_active' => false]);

        $this->assertFalse((bool) $this->administradorUm->fresh()->is_active);

        // Sobrou um. Desativá-lo deixaria a empresa 1 sem administrador, mesmo
        // com a empresa 2 tendo o dela.
        $resposta = $this->actingAs($this->administradorUm)
            ->patch("/settings/users/{$outroDoTenantUm->id}/status", ['is_active' => false]);

        $resposta->assertSessionHasErrors('error');

        $this->assertTrue(
            (bool) $outroDoTenantUm->fresh()->is_active,
            'o último administrador da empresa 1 foi desativado porque a empresa 2 tem administradores'
        );
    }

    // -----------------------------------------------------------------
    // Usuário sem company_id resolvido
    // -----------------------------------------------------------------

    /**
     * O cenário "usuário autenticado sem empresa" é impedido pelo banco:
     * `users.company_id` é NOT NULL com chave estrangeira. Este teste existe
     * para prender essa restrição, porque é ela que sustenta todo o resto: sem
     * tenant resolvido o escopo global não filtra nada, e a requisição passaria
     * a enxergar as duas empresas juntas.
     */
    public function test_usuario_sem_company_id_nao_consegue_existir_no_banco(): void
    {
        $usuario = TenantAtual::comTenant($this->empresaUm->id, fn () => User::factory()->create());

        $this->expectException(QueryException::class);

        DB::table('users')->where('id', $usuario->id)->update(['company_id' => null]);
    }

    /**
     * Sem tenant resolvido, o escopo global não filtra nada: é o buraco que a
     * coluna NOT NULL fecha. Fica documentado aqui, com usuário de mentira que
     * nunca chega ao banco, para que a consequência esteja escrita e não
     * dependa de alguém lembrar.
     */
    public function test_sem_tenant_resolvido_o_escopo_para_de_filtrar(): void
    {
        Auth::setUser(new User);

        $this->assertNull(TenantAtual::id(), 'usuário sem company_id não resolve tenant');

        $this->assertSame(
            2,
            Client::query()->count(),
            'sem tenant resolvido o escopo global deixa de filtrar, e é por isso que users.company_id é NOT NULL'
        );
    }

    /**
     * Sem tenant resolvido, quem precisa da empresa falha alto e com mensagem
     * própria, em vez de cair na empresa 1 por padrão. Documento emitido com o
     * cabeçalho da empresa errada não tem como ser desfeito.
     */
    public function test_sem_tenant_resolvido_a_empresa_corrente_estoura_em_vez_de_cair_na_empresa_1(): void
    {
        Auth::setUser(new User);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Nenhuma empresa resolvida para esta operação.');

        Company::current();
    }

    /**
     * Enquanto o `DEFAULT 1` das colunas `company_id` estiver de pé (ele só cai
     * na migration de Deploy 5), gravação que escapar da trait vai para a
     * empresa 1. O que não pode acontecer em nenhum dos dois momentos é a linha
     * nascer na empresa 2 sem ninguém ter pedido.
     */
    public function test_gravacao_que_escapa_da_trait_nunca_cai_na_empresa_2(): void
    {
        $id = null;

        try {
            $id = DB::table('procedures')->insertGetId([
                'name' => 'Procedimento sem empresa',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            // Depois do Deploy 5 o DEFAULT some e o insert falha, que é o
            // comportamento final desejado.
            $this->assertTrue(true);

            return;
        }

        $empresa = (int) DB::table('procedures')->where('id', $id)->value('company_id');

        $this->assertNotSame(
            $this->empresaDois->id,
            $empresa,
            'gravação sem empresa informada caiu no tenant 2'
        );
        $this->assertSame($this->empresaUm->id, $empresa, 'com o DEFAULT vivo, a gravação sem empresa cai na empresa fundadora');
    }

    // -----------------------------------------------------------------
    // Escopo no Eloquent: os models de domínio, um a um
    // -----------------------------------------------------------------

    /**
     * Antes de qualquer rota, o escopo precisa valer no Eloquent. Percorre o
     * conjunto completo criado nos dois tenants e exige que, dentro do tenant 1,
     * nenhum registro do tenant 2 seja encontrado por consulta nem por id.
     */
    public function test_nenhum_model_de_dominio_encontra_registro_do_outro_tenant(): void
    {
        foreach ($this->dadosDois as $chave => $registro) {
            $classe = $registro::class;
            $id = $registro->id;

            $encontrado = TenantAtual::comTenant(
                $this->empresaUm->id,
                fn () => $classe::query()->whereKey($id)->first()
            );

            $this->assertNull(
                $encontrado,
                "{$classe} ({$chave}) do tenant 2 foi encontrado dentro do tenant 1: a trait BelongsToCompany não está aplicada ou o escopo não filtrou"
            );

            $proprio = TenantAtual::comTenant(
                $this->empresaUm->id,
                fn () => $classe::query()->whereKey($this->dadosUm[$chave]->id)->first()
            );

            $this->assertNotNull(
                $proprio,
                "{$classe} ({$chave}) do próprio tenant 1 sumiu da consulta: o escopo está filtrando demais"
            );
        }
    }

    /**
     * O conjunto de dados do cenário cobre todo model listado em
     * `DominioMultiempresa::MODELS_DE_DOMINIO`, com a exceção declarada de
     * `User`, que tem testes próprios logo acima. Model de domínio novo entra
     * na lista e faz este teste falhar até ganhar dado no cenário.
     */
    public function test_o_cenario_cobre_todos_os_models_de_dominio(): void
    {
        $cobertos = collect($this->dadosDois)->map(fn (Model $registro): string => $registro::class)->unique();

        $faltando = collect(DominioMultiempresa::MODELS_DE_DOMINIO)
            ->reject(fn (string $model): bool => $model === User::class)
            ->reject(fn (string $model): bool => $cobertos->contains($model))
            ->values()
            ->all();

        $this->assertSame(
            [],
            $faltando,
            'estes models de domínio não têm dado no cenário do teste de vazamento: '.implode(', ', $faltando)
        );
    }

    // -----------------------------------------------------------------
    // Apoio: cenário
    // -----------------------------------------------------------------

    /**
     * A empresa 1 nasce da migration de fundação do tenant, sem nome. Ganha aqui
     * o nome e o CNPJ para virar dado carimbado como o resto.
     */
    private function prepararEmpresaFundadora(): Company
    {
        $empresa = Company::query()->firstOrFail();

        $empresa->update([
            'name' => 'Dedetizadora '.self::MARCA_UM,
            'cnpj' => '11.111.111/0001-11',
            'email' => 'contato@'.strtolower(self::MARCA_UM).'.test',
        ]);

        return $empresa->fresh();
    }

    private function criarAdministrador(int $empresa, string $marca): User
    {
        $usuario = TenantAtual::comTenant($empresa, fn () => User::factory()->create([
            'name' => 'Administrador '.$marca,
            'email' => 'admin-'.strtolower($marca).'@exemplo.test',
            'is_active' => true,
        ]));

        $usuario->assignRole('administrador');

        return $usuario->fresh();
    }

    /**
     * Conjunto completo de dado de um tenant. Toda string relevante leva a
     * marca da empresa, para que qualquer vazamento apareça na busca textual.
     *
     * @return array<string, Model>
     */
    private function criarConjuntoCompleto(int $empresa, string $marca, User $autor): array
    {
        return TenantAtual::comTenant($empresa, function () use ($marca, $autor): array {
            $baixo = strtolower($marca);

            $dados = [];

            $dados['client'] = Client::create([
                'name' => 'Cliente '.$marca,
                'email' => "cliente-{$baixo}@exemplo.test",
                'phone' => '11912340000',
                'cnpj' => '33.333.333/0001-'.$this->sufixo($marca),
                'notes' => 'Observacao do cliente '.$marca,
            ]);

            $dados['address'] = Address::create([
                'client_id' => $dados['client']->id,
                'nickname' => 'Unidade '.$marca,
                'street' => 'Rua '.$marca,
                'number' => '100',
                'district' => 'Bairro '.$marca,
                'city' => 'Cidade'.$marca,
                'state' => 'SP',
                'zip' => '01000-000',
                'active' => true,
            ]);

            $dados['room'] = Room::create([
                'address_id' => $dados['address']->id,
                'name' => 'Comodo '.$marca,
                'active' => true,
            ]);

            $dados['baitType'] = BaitType::create([
                'name' => 'Isca '.$marca,
                'brand' => 'Marca '.$marca,
            ]);

            $dados['device'] = Device::create([
                'address_id' => $dados['address']->id,
                'bait_type_id' => $dados['baitType']->id,
                'label' => 'Dispositivo '.$marca,
                'number' => 'DISP-'.$marca,
                'active' => true,
            ]);

            $dados['activeIngredient'] = ActiveIngredient::create(['name' => 'Principio '.$marca]);
            $dados['chemicalGroup'] = ChemicalGroup::create(['name' => 'Grupo '.$marca]);
            $dados['antidote'] = Antidote::create(['name' => 'Antidoto '.$marca]);
            $dados['organRegistration'] = OrganRegistration::create(['record' => 'REGISTRO-'.$marca]);

            $dados['product'] = Product::create([
                'name' => 'Produto '.$marca,
                'active_ingredient_id' => $dados['activeIngredient']->id,
                'chemical_group_id' => $dados['chemicalGroup']->id,
                'antidote_id' => $dados['antidote']->id,
                'organ_registration_id' => $dados['organRegistration']->id,
            ]);

            $dados['service'] = Service::create([
                'name' => 'Servico '.$marca,
                'description' => 'Descricao do servico '.$marca,
                'price' => 300,
                'is_active' => true,
            ]);

            $dados['serviceType'] = ServiceType::create(['name' => 'Tipo '.$marca]);

            $dados['technician'] = Technician::create([
                'name' => 'Tecnico '.$marca,
                'email' => "tecnico-{$baixo}@exemplo.test",
                'phone' => '11987650000',
                'registration_number' => 'CRQ-'.$marca,
                'is_active' => true,
            ]);

            $dados['procedure'] = Procedure::create(['name' => 'Procedimento '.$marca]);

            $dados['eventType'] = EventType::create([
                'name' => 'Evento '.$marca,
                'description' => 'Descricao do evento '.$marca,
            ]);

            $dados['workOrder'] = WorkOrder::create([
                'order_number' => 'OT-'.$marca,
                'client_id' => $dados['client']->id,
                'address_id' => $dados['address']->id,
                'scheduled_date' => '2026-07-05',
                'status' => 'completed',
                'description' => 'Ordem de trabalho '.$marca,
                'total_cost' => 500,
                'discount_amount' => 0,
                'final_amount' => 500,
                'payment_status' => 'paid',
                'active' => true,
            ]);

            $dados['serviceOrder'] = ServiceOrder::create([
                'client_id' => $dados['client']->id,
                'service_id' => $dados['service']->id,
                'order_number' => 'SA-'.$marca,
                'order_date' => '2026-07-05',
                'start_time' => '08:00',
                'end_time' => '10:00',
                'description' => 'Servico agendado '.$marca,
                'status' => 'pending',
            ]);

            $dados['certificate'] = Certificate::create([
                'client_id' => $dados['client']->id,
                'address_id' => $dados['address']->id,
                'work_order_id' => $dados['workOrder']->id,
                'service_id' => $dados['service']->id,
                'certificate_number' => 'CERT-'.$marca,
                'execution_date' => '2026-07-05',
                'warranty' => '2026-10-05',
                'status' => 'active',
                'notes' => 'Certificado '.$marca,
            ]);

            $dados['contract'] = Contract::create([
                'address_id' => $dados['address']->id,
                'contract_number' => 'CONT-'.$marca,
                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',
                'service_value' => 1000,
                'service_type' => 'Controle de pragas '.$marca,
                'visit_frequency_valor' => 1,
                'visit_frequency_unidade' => 'meses',
                'visit_count' => 12,
                'pest_target' => 'Praga '.$marca,
            ]);

            // Motivo de uma data prevista que não virou visita. Entra no
            // cenário porque é model de domínio: o texto do motivo de uma
            // empresa não pode aparecer para a outra.
            $dados['contractVisitJustification'] = ContractVisitJustification::create([
                'contract_id' => $dados['contract']->id,
                'expected_date' => '2026-02-01',
                'reason' => 'Contrato suspenso no periodo '.$marca,
                'justified_by' => $autor->id,
                'justified_by_name' => 'Autor '.$marca,
            ]);

            $dados['budget'] = Budget::create([
                'client_id' => $dados['client']->id,
                'address_id' => $dados['address']->id,
                'user_id' => $autor->id,
                'status' => 'draft',
                'date' => '2026-07-05',
                'validity_date' => '2026-08-05',
                'observations' => 'Orcamento '.$marca,
            ]);

            $dados['financialEntry'] = FinancialEntry::create([
                'source' => 'manual',
                'amount' => $this->valorDeEntrada($marca),
                'description' => 'Entrada '.$marca,
                'entry_date' => now()->toDateString(),
                'status' => 'confirmed',
                'created_by' => $autor->id,
            ]);

            $dados['financialWithdrawal'] = FinancialEntry::create([
                'source' => 'manual_withdrawal',
                'amount' => $this->valorDeSaida($marca),
                'description' => 'Saida '.$marca,
                'entry_date' => now()->toDateString(),
                'status' => 'confirmed',
                'created_by' => $autor->id,
            ]);

            $dados['paymentDetail'] = PaymentDetail::create([
                'work_order_id' => $dados['workOrder']->id,
                'total_cost' => 500,
                'discount_amount' => 0,
                'final_amount' => 500,
                'payment_date' => '2026-07-06',
                'amount_paid' => 500,
                'payment_status' => 'paid',
                'payment_notes' => 'Parcela '.$marca,
                'active' => true,
            ]);

            $dados['dailyCashBalance'] = DailyCashBalance::create([
                'balance_date' => '2026-07-05',
                'opening_balance' => 0,
                'closing_balance' => 0,
                'notes' => 'Caixa '.$marca,
            ]);

            $dados['deviceEvent'] = DeviceEvent::create([
                'device_id' => $dados['device']->id,
                'work_order_id' => $dados['workOrder']->id,
                'event_type' => 'cleaning',
                'event_date' => '2026-07-05 09:00:00',
                'description' => 'Evento de dispositivo '.$marca,
                'active' => true,
            ]);

            $dados['pestSighting'] = PestSighting::create([
                'address_id' => $dados['address']->id,
                'work_order_id' => $dados['workOrder']->id,
                'sighting_date' => '2026-07-05 09:30:00',
                'pest_type' => 'rats',
                'severity_level' => 'low',
                'location_description' => 'Local '.$marca,
                'description' => 'Avistamento '.$marca,
                'active' => true,
            ]);

            $dados['adequation'] = WorkOrderAdequation::create([
                'work_order_id' => $dados['workOrder']->id,
                'type' => 'estrutural',
                'description' => 'Adequacao '.$marca,
                'priority' => 'media',
                'status' => 'pendente',
            ]);

            $dados['photo'] = WorkOrderPhoto::create([
                'work_order_id' => $dados['workOrder']->id,
                'entity_type' => 'adequation',
                'entity_id' => $dados['adequation']->id,
                'path' => "fotos/{$baixo}/foto.jpg",
                'caption' => 'Foto '.$marca,
            ]);

            $dados['workOrderDeviceEvent'] = WorkOrderDeviceEvent::create([
                'work_order_id' => $dados['workOrder']->id,
                'device_id' => $dados['device']->id,
                'event_type_id' => $dados['eventType']->id,
                'event_date' => '2026-07-05',
                'event_description' => 'Evento na OS '.$marca,
            ]);

            $dados['accessLog'] = AccessLog::create([
                'user_id' => $autor->id,
                'email' => $autor->email,
                'evento' => 'login',
                'ip' => '10.0.0.1',
                'user_agent' => 'Navegador '.$marca,
            ]);

            // Central de notificações. O texto do template e o corpo já montado
            // na fila são escritos pela empresa e citam nome de cliente e número
            // de OS: o vazamento aqui entregaria a carteira de clientes da
            // concorrente em texto puro.
            $dados['notificationTemplate'] = NotificationTemplate::create([
                'evento' => 'visita_agendada',
                'canal' => 'email',
                'assunto' => 'Visita agendada '.$marca,
                'corpo' => 'Modelo de aviso '.$marca,
                'ativo' => true,
            ]);

            $dados['notificationQueue'] = NotificationQueue::create([
                'evento' => 'visita_agendada',
                'canal' => 'email',
                // A chave de idempotência é unique global de propósito, então a
                // marca precisa entrar nela: duas empresas com a mesma chave
                // colidiriam no banco, e é esse o comportamento declarado em
                // DominioMultiempresa::UNIQUES_GLOBAIS_MANTIDOS.
                'chave_idempotencia' => 'visita_agendada:cliente:'.$dados['client']->id.':work_order:'.$dados['workOrder']->id,
                'destinatario_tipo' => NotificationQueue::DESTINATARIO_CLIENTE,
                'destinatario_id' => $dados['client']->id,
                'destino' => $dados['client']->email,
                'assunto' => 'Visita agendada '.$marca,
                'corpo' => 'Aviso enfileirado '.$marca,
                'contexto' => ['work_order_id' => $dados['workOrder']->id, 'marca' => $marca],
                'situacao' => NotificationQueue::SITUACAO_PENDENTE,
                'agendada_para' => '2026-07-04 18:00:00',
                'tentativas' => 0,
            ]);

            $dados['notificationLog'] = NotificationLog::create([
                'notification_queue_id' => $dados['notificationQueue']->id,
                'tentativa' => 1,
                'resultado' => NotificationLog::RESULTADO_FALHA_TEMPORARIA,
                'mensagem' => 'Tempo limite do provedor '.$marca,
                'resposta_bruta' => ['codigo' => 504, 'marca' => $marca],
                'ocorrida_em' => '2026-07-04 18:01:00',
            ]);

            // Dispositivo novo só para dar destino à substituição abaixo. Não
            // vira uma entrada própria em `$dados`/`basesDeRecurso()` porque
            // `device_replacements` não tem rota de CRUD direta (a única rota é
            // a ação `devices.substituir`, coberta pela Task 11.9); aqui o
            // objetivo é só o model de domínio ter dado no cenário, sem alterar
            // `situacao`/`active` de `$dados['device']`, que outros testes deste
            // arquivo já usam supondo um dispositivo comum.
            $dispositivoNovoDaSubstituicao = Device::create([
                'address_id' => $dados['address']->id,
                'bait_type_id' => $dados['baitType']->id,
                'label' => 'Dispositivo substituto '.$marca,
                'number' => 'DISP-SUB-'.$marca,
                'active' => true,
            ]);

            $dados['deviceReplacement'] = DeviceReplacement::create([
                'device_anterior_id' => $dados['device']->id,
                'device_novo_id' => $dispositivoNovoDaSubstituicao->id,
                'motivo' => 'danificado',
                'observacao' => 'Substituicao '.$marca,
                'substituido_em' => '2026-07-05',
                'user_id' => $autor->id,
            ]);

            $dados['auditLog'] = AuditLog::create([
                'auditable_type' => Client::class,
                'auditable_id' => $dados['client']->id,
                'acao' => 'atualizado',
                'user_id' => $autor->id,
                'autor_nome' => 'Autor '.$marca,
                'valores_antes' => ['name' => 'Antigo '.$marca],
                'valores_depois' => ['name' => 'Cliente '.$marca],
            ]);

            // Convite e passo de onboarding (Plano 8). Nenhum dos dois tem rota
            // de CRUD direta ainda (só migrations e models na Task 8.1), então
            // não entram em basesDeRecurso(): o objetivo aqui é só o model de
            // domínio ter dado no cenário, mesmo critério já usado para
            // deviceReplacement acima.
            $dados['invitation'] = Invitation::create([
                'email' => "convite-{$baixo}@exemplo.test",
                'nome' => 'Convidado '.$marca,
                'papel' => 'tecnico',
                'convidado_por' => $autor->id,
                'expira_em' => now()->addDays(7)->toDateString(),
            ]);

            $dados['onboardingStep'] = OnboardingStep::create([
                'chave' => 'passo_'.$baixo,
            ]);

            return $dados;
        });
    }

    private function sufixo(string $marca): string
    {
        return $marca === self::MARCA_UM ? '11' : '22';
    }

    /**
     * Valores escolhidos para não colidirem por soma. O fluxo de caixa
     * apresenta subtotais, e 111,11 + 222,22 dá 333,33: usar 333,33 no tenant 2
     * faria um subtotal legítimo do tenant 1 parecer vazamento.
     */
    private function valorDeEntrada(string $marca): string
    {
        return $marca === self::MARCA_UM ? '111.11' : '777.77';
    }

    private function valorDeSaida(string $marca): string
    {
        return $marca === self::MARCA_UM ? '222.22' : '888.88';
    }

    /**
     * Strings que só existem no tenant informado. Qualquer uma delas no corpo de
     * uma resposta do outro tenant é vazamento.
     *
     * @param  array<string, Model>  $dados
     * @return array<int, string>
     */
    private function marcasTextuais(string $marca, User $administrador, array $dados): array
    {
        $marcas = [
            $marca,
            $administrador->email,
            $dados['client']->email,
            $dados['technician']->email,
            $dados['certificate']->certificate_number,
            $dados['workOrder']->order_number,
            $dados['serviceOrder']->order_number,
            $dados['contract']->contract_number,
            $dados['client']->cnpj,
        ];

        return array_values(array_unique(array_filter($marcas)));
    }

    private function urlDoReciboDaOrdemDoTenantDois(): string
    {
        return TenantAtual::comTenant(
            $this->empresaDois->id,
            fn () => app(WorkOrderService::class)->urlAssinadaDoRecibo($this->dadosDois['workOrder'])
        );
    }

    // -----------------------------------------------------------------
    // Apoio: varredura de rotas
    // -----------------------------------------------------------------

    /**
     * URIs GET sem parâmetro protegidas por `auth`, colhidas da coleção de
     * rotas registradas. Index, create, dashboard, estatística, exportação e
     * configuração entram juntos; rota nova de domínio entra sozinha.
     *
     * @return array<int, string>
     */
    private function rotasDeVarredura(): array
    {
        return collect(Route::getRoutes())
            ->filter(fn ($rota): bool => in_array('GET', $rota->methods(), true))
            ->filter(fn ($rota): bool => ! str_contains($rota->uri(), '{'))
            ->filter(fn ($rota): bool => in_array('auth', $rota->gatherMiddleware(), true))
            ->reject(fn ($rota): bool => array_key_exists($rota->uri(), self::ROTAS_FORA_DA_VARREDURA))
            ->map(fn ($rota): string => ltrim($rota->uri(), '/'))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    // -----------------------------------------------------------------
    // Apoio: mapa de recursos por rota
    // -----------------------------------------------------------------

    /**
     * Cada combinação de verbo, ação e URL que aponta para um registro do
     * tenant 2, já filtrada pelas rotas que existem de fato.
     *
     * @return array<int, array{0: string, 1: string, 2: string, 3: Model}>
     */
    private function chamadasDiretasAosRecursosDoTenantDois(): array
    {
        $chamadas = [];

        foreach ($this->basesDeRecurso() as $base => $chave) {
            $registro = $this->dadosDois[$chave];

            foreach ([
                ['GET', 'show', "/{$base}/{$registro->id}"],
                ['GET', 'edit', "/{$base}/{$registro->id}/edit"],
                ['PUT', 'update', "/{$base}/{$registro->id}"],
                ['DELETE', 'destroy', "/{$base}/{$registro->id}"],
            ] as [$metodo, $acao, $url]) {
                if (! Route::has("{$base}.{$acao}")) {
                    continue;
                }

                $chamadas[] = [$metodo, "{$base}.{$acao}", $url, $registro];
            }
        }

        return $chamadas;
    }

    /**
     * @return array<string, string>
     */
    private function basesDeRecurso(): array
    {
        return [
            'clients' => 'client',
            'addresses' => 'address',
            'rooms' => 'room',
            'devices' => 'device',
            'bait-types' => 'baitType',
            'products' => 'product',
            'active-ingredients' => 'activeIngredient',
            'chemical-groups' => 'chemicalGroup',
            'antidotes' => 'antidote',
            'organ-registrations' => 'organRegistration',
            'services' => 'service',
            'service-orders' => 'serviceOrder',
            'certificates' => 'certificate',
            'contracts' => 'contract',
            'budgets' => 'budget',
            'work-orders' => 'workOrder',
            'technicians' => 'technician',
            'device-events' => 'deviceEvent',
            'pest-sightings' => 'pestSighting',
            'event-types' => 'eventType',
            'financial-entries' => 'financialEntry',
            'financial-withdrawals' => 'financialWithdrawal',
            'payment-details' => 'paymentDetail',
        ];
    }

    /**
     * Atributos do registro lidos de dentro do tenant dono dele. `null` quando
     * o registro deixou de existir, que é o que denuncia exclusão indevida.
     *
     * @return array<string, mixed>|null
     */
    private function atributosNaEmpresaDois(Model $registro): ?array
    {
        $classe = $registro::class;
        $id = $registro->id;

        return TenantAtual::comTenant(
            $this->empresaDois->id,
            fn () => $classe::query()->whereKey($id)->first()?->getAttributes()
        );
    }

    // -----------------------------------------------------------------
    // Apoio: asserções sobre a resposta
    // -----------------------------------------------------------------

    /**
     * As duas asserções que valem para toda resposta: nenhuma marca textual do
     * tenant 2 no corpo, e nenhum objeto serializado com `company_id` do
     * tenant 2.
     */
    private function assertRespostaSemDadoDaEmpresaDois($resposta, string $origem): void
    {
        $corpo = $this->corpoDaResposta($resposta);

        foreach ($this->marcasDaEmpresaDois as $marca) {
            $this->assertStringNotContainsString(
                $marca,
                $corpo,
                "{$origem} devolveu '{$marca}', que é dado da empresa 2"
            );
        }

        $estrutura = $this->estruturaDaResposta($corpo);

        if ($estrutura === null) {
            return;
        }

        $this->assertFalse(
            $this->contemCompanyId($estrutura, $this->empresaDois->id),
            "{$origem} devolveu um registro serializado com company_id = {$this->empresaDois->id}"
        );
    }

    private function corpoDaResposta($resposta): string
    {
        $conteudo = $resposta->baseResponse->getContent();

        if ($conteudo === false || $conteudo === null) {
            return $resposta->streamedContent();
        }

        return $conteudo;
    }

    /**
     * Estrutura de dados da resposta, quando dá para extrair: JSON puro ou o
     * `data-page` da página Inertia. `null` quando é PDF, CSV ou HTML sem
     * Inertia, e nesses casos sobra a busca textual.
     *
     * @return array<mixed>|null
     */
    private function estruturaDaResposta(string $corpo): ?array
    {
        $decodificado = json_decode($corpo, true);

        if (is_array($decodificado)) {
            return $decodificado;
        }

        if (preg_match('/data-page="([^"]*)"/', $corpo, $partes) !== 1) {
            return null;
        }

        $pagina = json_decode(html_entity_decode($partes[1], ENT_QUOTES), true);

        return is_array($pagina) ? $pagina : null;
    }

    /**
     * Existe, em qualquer profundidade, um array com `company_id` igual ao
     * informado?
     *
     * @param  array<mixed>  $estrutura
     */
    private function contemCompanyId(array $estrutura, int $empresa): bool
    {
        if (array_key_exists('company_id', $estrutura) && (int) $estrutura['company_id'] === $empresa) {
            return true;
        }

        foreach ($estrutura as $valor) {
            if (is_array($valor) && $this->contemCompanyId($valor, $empresa)) {
                return true;
            }
        }

        return false;
    }
}
