<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\NotificationLog;
use App\Models\NotificationQueue;
use App\Services\Notification\ClassificadorDeFalhaDeEnvio;
use App\Services\Notification\DriverDeEnvio;
use App\Services\Notification\ResultadoDeEnvio;
use App\Services\NotificationDispatcher;
use App\Support\EventosDeNotificacao;
use App\Support\TenantAtual;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Tests\TestCase;
use Throwable;

/**
 * Task 14.9 do Plano 14: a retentativa funciona como projetado.
 *
 * O que este arquivo protege é a decisão mais cara do plano, e ela tem dois
 * lados que se cobram:
 *
 * - **Falha temporária tem que repetir**, com espera crescente, senão o aviso
 *   se perde por causa de um minuto de indisponibilidade do provedor.
 * - **Falha permanente não pode repetir.** Insistir quatro vezes em uma caixa
 *   inexistente derruba a reputação do remetente da dedetizadora e leva junto a
 *   entrega de todos os outros avisos dela.
 *
 * Nenhum teste daqui toca a rede. O despachante aceita a lista de drivers pelo
 * construtor exatamente para isto: os cenários de falha usam o
 * `DriverDeMentira` declarado no fim deste arquivo, que devolve o resultado
 * programado (ou lança a exceção programada) sem SMTP, sem HTTP e sem provedor.
 * O único teste que usa o driver real de e-mail é o do remetente, e ele sai pelo
 * transporte `array` de `phpunit.xml`, que guarda a mensagem em memória.
 *
 * O relógio é fixado em UTC em todos os cenários, e avançado à mão nos testes de
 * espera. Fixar em Brasília faria `Carbon::setTestNow()` emprestar o fuso da
 * instância mockada para o cast de coluna `datetime`, e as comparações de hora
 * mediriam o defeito do teste em vez do código (mesma nota de
 * `NotificationServiceTest` e de `GeracaoDeVisitasTest`).
 */
class NotificationDispatcherTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 12h em UTC, 09h em Brasília: longe das duas viradas de dia, porque aqui o
     * que importa é o intervalo entre tentativas, não a data.
     */
    private const AGORA = '2026-07-26 12:00:00';

    private Company $empresaA;

    private Company $empresaB;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::AGORA, 'UTC'));

        // A empresa 1 vem da migration de fundação do tenant, sem nome.
        Company::query()->whereKey(1)->update(['name' => 'Dedetizadora A', 'email' => 'contato@a.test']);

        $this->empresaA = Company::query()->findOrFail(1);
        $this->empresaB = Company::create(['name' => 'Dedetizadora B', 'email' => 'contato@b.test']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Caminho feliz
    // -----------------------------------------------------------------

    public function test_item_vencido_e_enviado_e_registra_log_de_sucesso(): void
    {
        $item = $this->itemNaFila($this->empresaA);
        $driver = new DriverDeMentira;

        $resumo = $this->despacharNa($this->empresaA, $driver);

        $this->assertSame(1, $resumo['processados']);
        $this->assertSame(1, $resumo['enviados']);
        $this->assertSame([$item->id], $driver->itensRecebidos);

        $item->refresh();

        $this->assertSame(NotificationQueue::SITUACAO_ENVIADA, $item->situacao);
        $this->assertSame(1, (int) $item->tentativas);
        $this->assertNull($item->proxima_tentativa_em);

        $logs = $this->logsDe($item);

        $this->assertCount(1, $logs, 'a tentativa bem-sucedida não deixou linha no histórico');
        $this->assertSame(NotificationLog::RESULTADO_SUCESSO, $logs[0]->resultado);
        $this->assertSame(1, (int) $logs[0]->tentativa);
        $this->assertSame((int) $this->empresaA->id, (int) $logs[0]->company_id);
    }

    public function test_item_agendado_para_o_futuro_nao_sai_nesta_passada(): void
    {
        $item = $this->itemNaFila($this->empresaA, [
            'agendada_para' => CarbonImmutable::now()->addHour(),
        ]);

        $driver = new DriverDeMentira;
        $resumo = $this->despacharNa($this->empresaA, $driver);

        $this->assertSame(0, $resumo['processados']);
        $this->assertSame([], $driver->itensRecebidos);
        $this->assertSame(NotificationQueue::SITUACAO_PENDENTE, $item->refresh()->situacao);
        $this->assertSame(0, (int) $item->tentativas);
    }

    /**
     * Item de WhatsApp fica na fila esperando o clique no link `wa.me`, que é o
     * fluxo manual desta entrega. Marcá-lo como falha por não ter driver seria
     * apagar do painel um aviso que ainda vai ser mandado à mão.
     */
    public function test_item_de_canal_sem_driver_continua_na_fila_sem_falhar(): void
    {
        $item = $this->itemNaFila($this->empresaA, ['canal' => EventosDeNotificacao::CANAL_WHATSAPP]);

        $resumo = $this->despacharNa($this->empresaA, new DriverDeMentira);

        $this->assertSame(0, $resumo['processados']);

        $item->refresh();

        $this->assertSame(NotificationQueue::SITUACAO_PENDENTE, $item->situacao);
        $this->assertSame(0, (int) $item->tentativas);
        $this->assertCount(0, $this->logsDe($item));
    }

    // -----------------------------------------------------------------
    // Espera crescente
    // -----------------------------------------------------------------

    /**
     * As quatro tentativas, com a espera de cada uma conferida no relógio.
     *
     * A quarta falha temporária não ganha uma quinta espera: o teto de
     * `MAXIMO_DE_TENTATIVAS` encerra o item em `falha`. O provedor já disse
     * quatro vezes que não vai entregar, e um lembrete de véspera entregue no
     * dia seguinte não serve para nada.
     */
    public function test_falha_temporaria_reagenda_com_a_espera_correta_em_cada_tentativa(): void
    {
        $item = $this->itemNaFila($this->empresaA);
        $driver = (new DriverDeMentira)->sempre(ResultadoDeEnvio::falhaTemporaria('O provedor não respondeu.'));

        $esperas = NotificationDispatcher::ESPERAS_EM_MINUTOS;

        // As três primeiras falhas devolvem o item à fila com a espera da vez.
        foreach ([0, 1, 2] as $indice) {
            $resumo = $this->despacharNa($this->empresaA, $driver);

            $this->assertSame(1, $resumo['reagendados']);

            $item->refresh();

            $tentativa = $indice + 1;

            $this->assertSame(NotificationQueue::SITUACAO_PENDENTE, $item->situacao);
            $this->assertSame($tentativa, (int) $item->tentativas);
            $this->assertSame(
                CarbonImmutable::now()->addMinutes($esperas[$indice])->format('Y-m-d H:i:s'),
                $item->proxima_tentativa_em->format('Y-m-d H:i:s'),
                "a espera depois da tentativa {$tentativa} não foi de {$esperas[$indice]} minutos"
            );

            Carbon::setTestNow(Carbon::now()->addMinutes($esperas[$indice]));
        }

        // A quarta falha temporária encerra o item.
        $resumo = $this->despacharNa($this->empresaA, $driver);

        $this->assertSame(1, $resumo['desistidos']);

        $item->refresh();

        $this->assertSame(NotificationQueue::SITUACAO_FALHA, $item->situacao);
        $this->assertSame(NotificationDispatcher::MAXIMO_DE_TENTATIVAS, (int) $item->tentativas);
        $this->assertNull($item->proxima_tentativa_em, 'a quarta falha marcou uma quinta espera');

        $logs = $this->logsDe($item);

        $this->assertCount(NotificationDispatcher::MAXIMO_DE_TENTATIVAS, $logs);
        $this->assertSame([1, 2, 3, 4], array_map('intval', array_column($logs->all(), 'tentativa')));

        foreach ($logs as $log) {
            $this->assertSame(NotificationLog::RESULTADO_FALHA_TEMPORARIA, $log->resultado);
        }
    }

    public function test_item_reagendado_nao_e_tentado_antes_da_hora_marcada(): void
    {
        $item = $this->itemNaFila($this->empresaA);
        $driver = (new DriverDeMentira)->sempre(ResultadoDeEnvio::falhaTemporaria('O provedor não respondeu.'));

        $this->despacharNa($this->empresaA, $driver);
        $this->assertSame(1, (int) $item->refresh()->tentativas);

        // Quatro minutos depois: a espera da primeira tentativa é de cinco.
        Carbon::setTestNow(Carbon::now()->addMinutes(NotificationDispatcher::ESPERAS_EM_MINUTOS[0] - 1));

        $resumo = $this->despacharNa($this->empresaA, $driver);

        $this->assertSame(0, $resumo['processados'], 'o item foi tentado antes da hora marcada');
        $this->assertSame(1, (int) $item->refresh()->tentativas);
        $this->assertCount(1, $this->logsDe($item));
    }

    // -----------------------------------------------------------------
    // Falha permanente
    // -----------------------------------------------------------------

    public function test_falha_permanente_nao_repete(): void
    {
        $item = $this->itemNaFila($this->empresaA);
        $driver = (new DriverDeMentira)->sempre(
            ResultadoDeEnvio::falhaPermanente('A caixa do destinatário não existe.')
        );

        $resumo = $this->despacharNa($this->empresaA, $driver);

        $this->assertSame(1, $resumo['falhas_permanentes']);

        $item->refresh();

        $this->assertSame(NotificationQueue::SITUACAO_FALHA, $item->situacao);
        $this->assertSame(1, (int) $item->tentativas, 'a falha permanente consumiu mais de uma tentativa');
        $this->assertNull($item->proxima_tentativa_em);

        $logs = $this->logsDe($item);

        $this->assertCount(1, $logs);
        $this->assertSame(NotificationLog::RESULTADO_FALHA_PERMANENTE, $logs[0]->resultado);

        // Uma passada depois, com o relógio bem à frente: nada volta a ser
        // tentado, porque o item saiu de `pendente`.
        Carbon::setTestNow(Carbon::now()->addDay());

        $this->assertSame(0, $this->despacharNa($this->empresaA, $driver)['processados']);
        $this->assertCount(1, $this->logsDe($item));
    }

    /**
     * Driver com defeito não pode derrubar a rotina e levar junto os avisos das
     * outras empresas: a exceção vira resultado classificado.
     */
    public function test_excecao_lancada_pelo_driver_vira_falha_classificada(): void
    {
        $item = $this->itemNaFila($this->empresaA);
        $driver = (new DriverDeMentira)->lancar(new RfcComplianceException(
            'Email "cliente-sem-arroba" does not comply with addr-spec of RFC 2822.'
        ));

        $resumo = $this->despacharNa($this->empresaA, $driver);

        $this->assertSame(1, $resumo['falhas_permanentes']);
        $this->assertSame(NotificationQueue::SITUACAO_FALHA, $item->refresh()->situacao);

        $logs = $this->logsDe($item);

        $this->assertCount(1, $logs);
        $this->assertSame(NotificationLog::RESULTADO_FALHA_PERMANENTE, $logs[0]->resultado);
        $this->assertStringContainsString('Endereço de destino inválido', (string) $logs[0]->mensagem);
    }

    /**
     * A classificação por tipo de erro é testada aqui, e não em arquivo próprio,
     * porque é a regra que decide se o despachante repete: separá-la em outro
     * lugar esconderia de quem lê o despachante o critério que ele aplica.
     *
     * `HttpTransportException` fica de fora porque o construtor dela exige um
     * `Symfony\Contracts\HttpClient\ResponseInterface`, e o pacote de contratos
     * do HttpClient não está instalado neste projeto (o envio sai por SMTP). O
     * caminho dela está coberto por leitura do código, não por teste.
     */
    public function test_o_classificador_separa_falha_permanente_de_temporaria_por_tipo_de_erro(): void
    {
        // Endereço que nem chega a ser endereço: nenhuma repetição conserta.
        $this->assertTrue(
            ClassificadorDeFalhaDeEnvio::classificar(
                new RfcComplianceException('Email "x" does not comply with addr-spec of RFC 2822.')
            )->ehFalhaPermanente()
        );

        // SMTP 5yz é recusa definitiva do servidor de e-mail.
        $this->assertTrue(
            ClassificadorDeFalhaDeEnvio::classificar(
                new UnexpectedResponseException('Expected response code 250 but got code "550".', 550)
            )->ehFalhaPermanente()
        );

        // SMTP 4yz é "tente de novo", ao contrário do 4xx de HTTP.
        $this->assertTrue(
            ClassificadorDeFalhaDeEnvio::classificar(
                new UnexpectedResponseException('Expected response code 250 but got code "421".', 421)
            )->ehFalhaTemporaria()
        );

        // Limite de taxa é temporário mesmo sendo 429, que é 4xx.
        $this->assertTrue(
            ClassificadorDeFalhaDeEnvio::classificar(
                new RuntimeException('429 returned by the provider: rate limit exceeded for this account.')
            )->ehFalhaTemporaria()
        );

        // Caixa inexistente dita com palavras, sem código utilizável.
        $this->assertTrue(
            ClassificadorDeFalhaDeEnvio::classificar(
                new RuntimeException('<cliente@dominio.test>: Recipient address rejected: User unknown')
            )->ehFalhaPermanente()
        );

        // Rede e tempo limite.
        $this->assertTrue(
            ClassificadorDeFalhaDeEnvio::classificar(
                new RuntimeException('Connection to the mail server timed out.')
            )->ehFalhaTemporaria()
        );

        // Desconhecido cai no lado seguro: repete, com o teto de tentativas
        // impedindo que fique repetindo para sempre.
        $this->assertTrue(
            ClassificadorDeFalhaDeEnvio::classificar(
                new RuntimeException('Coisa que nunca vimos antes.')
            )->ehFalhaTemporaria()
        );
    }

    // -----------------------------------------------------------------
    // Item preso em `enviando`
    // -----------------------------------------------------------------

    /**
     * Processo morto no meio do envio deixa o item em `enviando` para sempre.
     * Sem o destravamento, o aviso nunca mais sairia e nada no histórico diria
     * por quê.
     */
    public function test_item_preso_em_enviando_ha_mais_de_quinze_minutos_volta_a_pendente(): void
    {
        $item = $this->itemNaFila($this->empresaA, ['situacao' => NotificationQueue::SITUACAO_ENVIANDO]);

        $this->envelhecerPosse($item, NotificationDispatcher::MINUTOS_PARA_DESTRAVAR + 5);

        $destravados = TenantAtual::comTenant(
            (int) $this->empresaA->id,
            fn (): int => (new NotificationDispatcher([new DriverDeMentira]))->destravarPresos()
        );

        $this->assertSame(1, $destravados);

        $item->refresh();

        $this->assertSame(NotificationQueue::SITUACAO_PENDENTE, $item->situacao);
        $this->assertSame(1, (int) $item->tentativas, 'a tentativa abandonada não foi contada');
        $this->assertSame(
            CarbonImmutable::now()->addMinutes(NotificationDispatcher::ESPERAS_EM_MINUTOS[0])->format('Y-m-d H:i:s'),
            $item->proxima_tentativa_em->format('Y-m-d H:i:s')
        );

        $logs = $this->logsDe($item);

        $this->assertCount(1, $logs, 'a tentativa abandonada não deixou rastro no histórico');
        $this->assertSame(NotificationLog::RESULTADO_FALHA_TEMPORARIA, $logs[0]->resultado);
        $this->assertStringContainsString('interrompida', (string) $logs[0]->mensagem);
    }

    /**
     * O prazo tem que ser folgado: destravar um item que ainda está sendo
     * enviado manda o mesmo aviso duas vezes para o cliente.
     */
    public function test_item_em_enviando_ha_poucos_minutos_nao_e_destravado(): void
    {
        $item = $this->itemNaFila($this->empresaA, ['situacao' => NotificationQueue::SITUACAO_ENVIANDO]);

        $this->envelhecerPosse($item, NotificationDispatcher::MINUTOS_PARA_DESTRAVAR - 5);

        $destravados = TenantAtual::comTenant(
            (int) $this->empresaA->id,
            fn (): int => (new NotificationDispatcher([new DriverDeMentira]))->destravarPresos()
        );

        $this->assertSame(0, $destravados);
        $this->assertSame(NotificationQueue::SITUACAO_ENVIANDO, $item->refresh()->situacao);
        $this->assertCount(0, $this->logsDe($item));
    }

    public function test_item_preso_de_outra_empresa_nao_e_destravado_no_tenant_errado(): void
    {
        $item = $this->itemNaFila($this->empresaA, ['situacao' => NotificationQueue::SITUACAO_ENVIANDO]);

        $this->envelhecerPosse($item, NotificationDispatcher::MINUTOS_PARA_DESTRAVAR + 5);

        $destravados = TenantAtual::comTenant(
            (int) $this->empresaB->id,
            fn (): int => (new NotificationDispatcher([new DriverDeMentira]))->destravarPresos()
        );

        $this->assertSame(0, $destravados);
        $this->assertSame(NotificationQueue::SITUACAO_ENVIANDO, $item->refresh()->situacao);
    }

    // -----------------------------------------------------------------
    // Escopo por empresa
    // -----------------------------------------------------------------

    public function test_item_de_um_tenant_nao_e_despachado_no_contexto_de_outro(): void
    {
        $itemDeA = $this->itemNaFila($this->empresaA);
        $driver = new DriverDeMentira;

        $resumo = $this->despacharNa($this->empresaB, $driver);

        $this->assertSame(0, $resumo['processados']);
        $this->assertSame([], $driver->itensRecebidos, 'o driver recebeu um item de outra empresa');

        $itemDeA->refresh();

        $this->assertSame(NotificationQueue::SITUACAO_PENDENTE, $itemDeA->situacao);
        $this->assertSame(0, (int) $itemDeA->tentativas);
        $this->assertNull($itemDeA->proxima_tentativa_em);
        $this->assertSame(0, NotificationLog::query()->count());
    }

    public function test_cada_empresa_despacha_apenas_a_propria_fila(): void
    {
        $itemDeA = $this->itemNaFila($this->empresaA, ['destino' => 'cliente.a@teste.test']);
        $itemDeB = $this->itemNaFila($this->empresaB, ['destino' => 'cliente.b@teste.test']);

        $driverDeA = new DriverDeMentira;
        $driverDeB = new DriverDeMentira;

        $this->despacharNa($this->empresaA, $driverDeA);
        $this->despacharNa($this->empresaB, $driverDeB);

        $this->assertSame([$itemDeA->id], $driverDeA->itensRecebidos);
        $this->assertSame([$itemDeB->id], $driverDeB->itensRecebidos);

        $this->assertSame(NotificationQueue::SITUACAO_ENVIADA, $itemDeA->refresh()->situacao);
        $this->assertSame(NotificationQueue::SITUACAO_ENVIADA, $itemDeB->refresh()->situacao);

        $this->assertSame(
            [(int) $this->empresaA->id, (int) $this->empresaB->id],
            NotificationLog::query()->orderBy('id')->pluck('company_id')->map('intval')->all()
        );
    }

    /**
     * O remetente é da empresa dona do item, nunca da plataforma: o cliente
     * final conhece a dedetizadora, e e-mail com nome de sistema desconhecido é
     * lido como golpe ou vai direto para o spam.
     *
     * É o único cenário deste arquivo com o driver real de e-mail, e mesmo assim
     * sem rede: `phpunit.xml` fixa `MAIL_MAILER=array`, e o transporte guarda a
     * mensagem em memória em vez de entregá-la a um servidor.
     */
    public function test_o_email_sai_com_o_remetente_da_empresa_dona_do_item(): void
    {
        $this->itemNaFila($this->empresaA, ['destino' => 'cliente.a@teste.test']);
        $this->itemNaFila($this->empresaB, ['destino' => 'cliente.b@teste.test']);

        $despachante = new NotificationDispatcher;

        TenantAtual::comTenant((int) $this->empresaA->id, fn (): array => $despachante->despachar());
        TenantAtual::comTenant((int) $this->empresaB->id, fn (): array => $despachante->despachar());

        $remetentePorDestino = $this->remetentesEnviados();

        $this->assertSame($this->empresaA->email, $remetentePorDestino['cliente.a@teste.test']['email'] ?? null);
        $this->assertSame('Dedetizadora A', $remetentePorDestino['cliente.a@teste.test']['nome'] ?? null);

        $this->assertSame($this->empresaB->email, $remetentePorDestino['cliente.b@teste.test']['email'] ?? null);
        $this->assertSame('Dedetizadora B', $remetentePorDestino['cliente.b@teste.test']['nome'] ?? null);
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * Uma passada do despachante dentro do tenant informado.
     *
     * @return array<string, int>
     */
    private function despacharNa(Company $empresa, DriverDeEnvio $driver): array
    {
        return TenantAtual::comTenant(
            (int) $empresa->id,
            fn (): array => (new NotificationDispatcher([$driver]))->despachar()
        );
    }

    /**
     * Item pendente e já vencido, gravado dentro do tenant da empresa.
     *
     * @param  array<string, mixed>  $atributos
     */
    private function itemNaFila(Company $empresa, array $atributos = []): NotificationQueue
    {
        return TenantAtual::comTenant(
            (int) $empresa->id,
            fn (): NotificationQueue => NotificationQueue::create(array_merge([
                'evento' => EventosDeNotificacao::VISITA_AGENDADA,
                'canal' => EventosDeNotificacao::CANAL_EMAIL,
                'chave_idempotencia' => 'teste:'.$empresa->id.':'.uniqid('', true),
                'destinatario_tipo' => NotificationQueue::DESTINATARIO_CLIENTE,
                'destinatario_id' => null,
                'destino' => 'cliente@teste.test',
                'assunto' => 'Visita agendada',
                'corpo' => 'Olá. Sua visita técnica está agendada.',
                'contexto' => [],
                'situacao' => NotificationQueue::SITUACAO_PENDENTE,
                'agendada_para' => CarbonImmutable::now()->subMinutes(10),
                'tentativas' => 0,
            ], $atributos))
        );
    }

    /**
     * Envelhece o carimbo de posse do item, que é o que `destravarPresos()` lê.
     *
     * Vai por `DB` de propósito: passar pelo model atualizaria `updated_at` para
     * agora e desfaria o próprio cenário.
     */
    private function envelhecerPosse(NotificationQueue $item, int $minutos): void
    {
        DB::table('notification_queue')
            ->where('id', $item->id)
            ->update(['updated_at' => CarbonImmutable::now()->subMinutes($minutos)]);
    }

    /**
     * Tentativas registradas para o item, da mais antiga para a mais nova.
     *
     * A consulta roda fora de `comTenant`, e nesse estado o escopo global não
     * filtra nada: é o que permite conferir as duas empresas de uma vez.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, NotificationLog>
     */
    private function logsDe(NotificationQueue $item): \Illuminate\Database\Eloquent\Collection
    {
        return NotificationLog::query()
            ->where('notification_queue_id', $item->id)
            ->orderBy('id')
            ->get();
    }

    /**
     * Remetente de cada mensagem que o transporte `array` guardou, indexado pelo
     * destinatário.
     *
     * @return array<string, array{email: string, nome: string}>
     */
    private function remetentesEnviados(): array
    {
        $remetentes = [];

        foreach (Mail::getSymfonyTransport()->messages() as $enviada) {
            $mensagem = $enviada->getOriginalMessage();
            $de = $mensagem->getFrom()[0];
            $para = $mensagem->getTo()[0];

            $remetentes[$para->getAddress()] = ['email' => $de->getAddress(), 'nome' => $de->getName()];
        }

        return $remetentes;
    }
}

/**
 * Driver de mentira: entrega o resultado programado sem falar com provedor
 * nenhum.
 *
 * Existe porque a retentativa é a regra mais cara deste plano e não dá para
 * exercitá-la com rede de verdade: seria lento, instável e dependeria de um
 * provedor cooperando com o cenário. O ponto de injeção de
 * `NotificationDispatcher::__construct()` foi escrito na Task 14.3 exatamente
 * para isto.
 *
 * Fica neste arquivo, e não em `tests/Support/`, porque só este teste o usa e a
 * Task 14.9 declara três arquivos. Se um segundo teste precisar dele, o lugar é
 * um arquivo próprio.
 */
class DriverDeMentira implements DriverDeEnvio
{
    /**
     * Ids dos itens que chegaram, na ordem. É o que prova que o despachante não
     * entregou item de outra empresa.
     *
     * @var array<int, int>
     */
    public array $itensRecebidos = [];

    /**
     * Resultados programados para as próximas chamadas, consumidos em ordem.
     *
     * @var array<int, ResultadoDeEnvio>
     */
    private array $programados = [];

    /**
     * Resultado devolvido quando a fila de programados acaba.
     */
    private ?ResultadoDeEnvio $padrao = null;

    /**
     * Exceção lançada no lugar de devolver resultado, para exercitar o
     * try/catch do despachante e o classificador.
     */
    private ?Throwable $erro = null;

    public function __construct(private readonly string $canal = EventosDeNotificacao::CANAL_EMAIL) {}

    public function canal(): string
    {
        return $this->canal;
    }

    public function enviar(NotificationQueue $item): ResultadoDeEnvio
    {
        $this->itensRecebidos[] = (int) $item->id;

        if ($this->erro !== null) {
            throw $this->erro;
        }

        return array_shift($this->programados)
            ?? $this->padrao
            ?? ResultadoDeEnvio::sucesso('Entregue pelo driver de teste.');
    }

    /**
     * Resultados desta e das próximas chamadas, em ordem.
     */
    public function programar(ResultadoDeEnvio ...$resultados): static
    {
        $this->programados = $resultados;

        return $this;
    }

    /**
     * Mesmo resultado em toda chamada.
     */
    public function sempre(ResultadoDeEnvio $resultado): static
    {
        $this->padrao = $resultado;

        return $this;
    }

    /**
     * Lança em vez de devolver resultado, como faria um driver com defeito.
     */
    public function lancar(Throwable $erro): static
    {
        $this->erro = $erro;

        return $this;
    }
}
