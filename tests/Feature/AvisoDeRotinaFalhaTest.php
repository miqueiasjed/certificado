<?php

namespace Tests\Feature;

use App\Listeners\RegistraExecucaoAgendada;
use App\Models\Company;
use App\Models\NotificationQueue;
use App\Models\ScheduledTaskRun;
use App\Models\User;
use App\Services\Notification\AvisoDeRotinaFalha;
use App\Support\BusinessDate;
use App\Support\EventosDeNotificacao;
use App\Support\RotinasAgendadas;
use App\Support\TenantAtual;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Task 14.5 do Plano 14: rotina que para de rodar deixa de ser silêncio.
 *
 * O caso que estes testes existem para proteger é o segundo, não o primeiro.
 * Rotina que falha grava `failed` em `scheduled_task_runs` e aparece em
 * `routines:status` para quem for olhar. Rotina que deixa de executar não grava
 * nada, e o histórico dela fica idêntico ao de um sistema saudável: é a falha
 * que ninguém descobre até um certificado vencido aparecer como ativo diante da
 * fiscalização.
 *
 * O relógio é fixado com `Carbon::setTestNow` em todos os cenários. A idade da
 * última execução é o dado que decide tudo aqui, e deixá-la depender do instante
 * real da suíte produziria teste que passa hoje e falha na virada de uma janela.
 *
 * Cada cenário parte de um sistema saudável e estraga uma rotina só
 * (`todasSaudaveis($exceto)`), para que o aviso conferido seja o daquela rotina
 * e não o ruído das outras oito.
 */
class AvisoDeRotinaFalhaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 12:00 em UTC, que é 09:00 em Brasília: longe das duas viradas de dia, para
     * que "hoje" seja o mesmo dia nos dois fusos e a data do marco não vire
     * assunto nos cenários que não tratam dela.
     */
    private const AGORA = '2026-07-26 12:00:00';

    /**
     * Rotina diária usada como cobaia. Janela de tolerância de 26 horas.
     */
    private const ROTINA_DIARIA = 'certificates:update-status';

    private const ROTINA_DE_INTERVALO = 'notificacoes:despachar';

    private Company $empresaA;

    private Company $empresaB;

    private User $administradorA;

    private User $administradorB;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::AGORA, 'UTC'));

        $this->seed(RolesAndPermissionsSeeder::class);

        // A empresa 1 vem da migration de fundação do tenant, sem nome.
        Company::query()->whereKey(1)->update(['name' => 'Dedetizadora A', 'email' => 'contato@a.test']);

        $this->empresaA = Company::query()->findOrFail(1);
        $this->empresaB = Company::create(['name' => 'Dedetizadora B', 'email' => 'contato@b.test']);

        $this->administradorA = $this->criarAdministrador($this->empresaA, 'admin.a@teste.test');
        $this->administradorB = $this->criarAdministrador($this->empresaB, 'admin.b@teste.test');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Rotina que falhou
    // -----------------------------------------------------------------

    public function test_rotina_com_ultima_execucao_em_erro_gera_aviso(): void
    {
        $this->rotinaQueFalhou('SQLSTATE[42S22]: Column not found: 1054 Unknown column warranty');

        $this->artisan('rotinas:verificar')->assertSuccessful();

        $aviso = $this->avisoDe($this->administradorA);

        $this->assertNotNull($aviso, 'a rotina que terminou com erro não gerou aviso');
        $this->assertStringContainsString(self::ROTINA_DIARIA, (string) $aviso->assunto);
        $this->assertStringContainsString('Unknown column warranty', $aviso->corpo);
    }

    /**
     * Falha sem saída registrada continua virando aviso. O `output` é o arquivo
     * que o agendamento redireciona, e ele pode chegar vazio: processo morto
     * antes de escrever, disco cheio, comando que só devolveu código de erro.
     */
    public function test_rotina_que_falhou_sem_deixar_mensagem_ainda_gera_aviso(): void
    {
        $this->rotinaQueFalhou();

        $this->artisan('rotinas:verificar')->assertSuccessful();

        $aviso = $this->avisoDe($this->administradorA);

        $this->assertNotNull($aviso);
        $this->assertStringContainsString('não deixou mensagem na saída', $aviso->corpo);
    }

    // -----------------------------------------------------------------
    // Rotina que não rodou: o caso que o Plano 1 não cobria
    // -----------------------------------------------------------------

    public function test_rotina_sem_execucao_dentro_da_janela_gera_aviso_mesmo_sem_registro_de_erro(): void
    {
        $this->todasSaudaveis(self::ROTINA_DIARIA);

        // 30 horas: passou das 26 da janela de uma diária, e nenhuma linha de
        // erro existe. Este é o cenário que não deixava rastro nenhum.
        $this->registrarExecucao(self::ROTINA_DIARIA, RegistraExecucaoAgendada::STATUS_SUCESSO, now()->subHours(30));

        $this->assertSame(
            0,
            ScheduledTaskRun::query()->where('status', RegistraExecucaoAgendada::STATUS_FALHOU)->count(),
            'o cenário precisa rodar sem nenhuma execução em erro, ou não testa o que deveria'
        );

        $this->artisan('rotinas:verificar')->assertSuccessful();

        $aviso = $this->avisoDe($this->administradorA);

        $this->assertNotNull($aviso, 'a rotina parada há 30 horas não gerou aviso');
        $this->assertStringContainsString('Nenhuma execução com sucesso nas últimas 26 horas', $aviso->corpo);
    }

    public function test_rotina_que_nunca_executou_gera_aviso(): void
    {
        $this->todasSaudaveis(self::ROTINA_DIARIA);

        $this->artisan('rotinas:verificar')->assertSuccessful();

        $aviso = $this->avisoDe($this->administradorA);

        $this->assertNotNull($aviso);
        $this->assertStringContainsString('Última execução bem-sucedida: nunca executou', $aviso->corpo);
    }

    /**
     * A folga de duas horas é o que separa "atrasou" de "parou". Uma diária que
     * rodou há 25 horas está atrasada e não é avisada; às 27 horas, sim.
     */
    public function test_a_diaria_so_e_cobrada_depois_das_26_horas(): void
    {
        $this->todasSaudaveis(self::ROTINA_DIARIA);
        $this->registrarExecucao(self::ROTINA_DIARIA, RegistraExecucaoAgendada::STATUS_SUCESSO, now()->subHours(25));

        $this->artisan('rotinas:verificar')->assertSuccessful();

        $this->assertNull($this->avisoDe($this->administradorA), 'rotina com 25 horas ainda está dentro da janela');

        $this->todasSaudaveis(self::ROTINA_DIARIA);
        $this->registrarExecucao(self::ROTINA_DIARIA, RegistraExecucaoAgendada::STATUS_SUCESSO, now()->subHours(27));

        $this->artisan('rotinas:verificar')->assertSuccessful();

        $this->assertNotNull($this->avisoDe($this->administradorA), 'rotina com 27 horas passou da janela e deveria avisar');
    }

    /**
     * Rodada barrada pela trava de sobreposição não é sinal de vida: ela não
     * processou nada. Tratá-la como execução adiaria o aviso justamente quando
     * um processo travado está bloqueando todas as passadas seguintes.
     */
    public function test_rodada_ignorada_por_sobreposicao_nao_conta_como_execucao(): void
    {
        $this->todasSaudaveis(self::ROTINA_DIARIA);

        $this->registrarExecucao(self::ROTINA_DIARIA, RegistraExecucaoAgendada::STATUS_SUCESSO, now()->subHours(30));
        $this->registrarExecucao(self::ROTINA_DIARIA, RegistraExecucaoAgendada::STATUS_IGNORADO, now()->subMinutes(5));

        $this->artisan('rotinas:verificar')->assertSuccessful();

        $this->assertNotNull($this->avisoDe($this->administradorA));
    }

    // -----------------------------------------------------------------
    // Rotina saudável
    // -----------------------------------------------------------------

    public function test_rotina_saudavel_nao_gera_aviso_nenhum(): void
    {
        $this->todasSaudaveis();

        $this->artisan('rotinas:verificar')
            ->expectsOutputToContain('Nenhuma rotina com problema')
            ->assertSuccessful();

        $this->assertSame(0, $this->totalDeAvisos());
    }

    // -----------------------------------------------------------------
    // Um aviso por rotina por dia
    // -----------------------------------------------------------------

    public function test_rodar_a_verificacao_24_vezes_no_dia_gera_um_aviso_por_rotina(): void
    {
        $this->rotinaQueFalhou();

        for ($passada = 1; $passada <= 24; $passada++) {
            $this->artisan('rotinas:verificar')->assertSuccessful();
        }

        // Duas empresas, um administrador em cada, uma rotina com problema.
        $this->assertSame(2, $this->totalDeAvisos(), 'a verificação de hora em hora repetiu o aviso do dia');
    }

    /**
     * O que faz o aviso ser diário é a data no marco da chave de idempotência.
     * Dia no fuso do negócio, não em UTC: em UTC a virada aconteceria às 21h de
     * Brasília e o administrador receberia dois avisos da mesma rotina na mesma
     * noite.
     */
    public function test_a_chave_do_aviso_carrega_a_rotina_e_o_dia_no_fuso_do_negocio(): void
    {
        $this->rotinaQueFalhou();

        $this->artisan('rotinas:verificar')->assertSuccessful();

        $chave = (string) $this->avisoDe($this->administradorA)?->chave_idempotencia;

        $this->assertStringContainsString('certificates-update-status', $chave);
        $this->assertStringContainsString(BusinessDate::hoje()->toDateString(), $chave);
    }

    // -----------------------------------------------------------------
    // Conteúdo do aviso
    // -----------------------------------------------------------------

    public function test_o_aviso_traz_nome_horario_esperado_e_ultima_execucao_bem_sucedida(): void
    {
        $this->todasSaudaveis(self::ROTINA_DIARIA);

        $ultimoSucesso = now()->subHours(30);
        $this->registrarExecucao(self::ROTINA_DIARIA, RegistraExecucaoAgendada::STATUS_SUCESSO, $ultimoSucesso);

        $this->artisan('rotinas:verificar')->assertSuccessful();

        $aviso = $this->avisoDe($this->administradorA);
        $this->assertNotNull($aviso);

        $this->assertStringContainsString(self::ROTINA_DIARIA, $aviso->corpo, 'o aviso não diz qual rotina falhou');
        $this->assertStringContainsString(
            'todo dia às '.RotinasAgendadas::DIARIAS[self::ROTINA_DIARIA],
            $aviso->corpo,
            'o aviso não diz o horário esperado da rotina'
        );
        $this->assertStringContainsString(
            BusinessDate::paraFusoNegocio($ultimoSucesso)->format('d/m/Y H:i'),
            $aviso->corpo,
            'o aviso não diz quando a rotina rodou bem pela última vez'
        );
    }

    /**
     * O horário esperado vem do mesmo catálogo que agenda o comando. Sem isso, o
     * e-mail poderia cobrar um horário que ninguém agendou.
     */
    public function test_rotina_de_intervalo_curto_tem_o_proprio_horario_esperado_no_aviso(): void
    {
        $this->todasSaudaveis(self::ROTINA_DE_INTERVALO);

        $this->registrarExecucao(self::ROTINA_DE_INTERVALO, RegistraExecucaoAgendada::STATUS_SUCESSO, now()->subHours(5));

        $this->artisan('rotinas:verificar')->assertSuccessful();

        $aviso = $this->avisoDe($this->administradorA);

        $this->assertNotNull($aviso);
        $this->assertStringContainsString(
            'a cada '.RotinasAgendadas::POR_INTERVALO[self::ROTINA_DE_INTERVALO].' minutos',
            $aviso->corpo
        );
    }

    // -----------------------------------------------------------------
    // Destinatário: o administrador de cada empresa
    // -----------------------------------------------------------------

    public function test_cada_empresa_recebe_o_aviso_no_proprio_administrador(): void
    {
        $this->rotinaQueFalhou();

        $this->artisan('rotinas:verificar')->assertSuccessful();

        $daEmpresaA = $this->avisoDe($this->administradorA);
        $daEmpresaB = $this->avisoDe($this->administradorB);

        $this->assertNotNull($daEmpresaA, 'a empresa A não foi avisada');
        $this->assertNotNull($daEmpresaB, 'a empresa B não foi avisada');

        $this->assertSame((int) $this->empresaA->id, (int) $daEmpresaA->company_id);
        $this->assertSame((int) $this->empresaB->id, (int) $daEmpresaB->company_id);
        $this->assertSame($this->administradorA->email, $daEmpresaA->destino);
        $this->assertSame($this->administradorB->email, $daEmpresaB->destino);

        $this->assertStringNotContainsString('Dedetizadora B', $daEmpresaA->corpo, 'o aviso da empresa A citou a empresa B');
    }

    /**
     * O aviso é do administrador, não da caixa genérica da empresa nem do
     * técnico: quem mexe em cron e servidor é quem administra.
     */
    public function test_o_aviso_vai_para_o_usuario_administrador_e_nao_para_o_tecnico(): void
    {
        $tecnico = $this->criarUsuarioComPapel($this->empresaA, 'tecnico', 'tecnico.a@teste.test');

        $this->rotinaQueFalhou();

        $this->artisan('rotinas:verificar')->assertSuccessful();

        $aviso = $this->avisoDe($this->administradorA);

        $this->assertNotNull($aviso);
        $this->assertSame(NotificationQueue::DESTINATARIO_USUARIO, $aviso->destinatario_tipo);
        $this->assertNull($this->avisoDe($tecnico), 'o técnico recebeu aviso de rotina parada');
    }

    public function test_administrador_desativado_nao_recebe_aviso(): void
    {
        $this->administradorB->update(['is_active' => false]);

        $this->rotinaQueFalhou();

        $this->artisan('rotinas:verificar')->assertSuccessful();

        $this->assertNotNull($this->avisoDe($this->administradorA));
        $this->assertNull($this->avisoDe($this->administradorB), 'administrador desativado recebeu aviso');
    }

    /**
     * Empresa sem administrador ativo não derruba a verificação nem as demais
     * empresas: é cadastro incompleto, e o rastro fica no log.
     */
    public function test_empresa_sem_administrador_nao_interrompe_as_outras(): void
    {
        Log::spy();

        $this->administradorB->update(['is_active' => false]);

        $this->rotinaQueFalhou();

        $this->artisan('rotinas:verificar')
            ->expectsOutputToContain('Nenhum administrador ativo nesta empresa')
            ->assertSuccessful();

        $this->assertNotNull($this->avisoDe($this->administradorA));

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $mensagem): bool => str_contains($mensagem, 'nenhum administrador ativo para avisar'))
            ->atLeast()->once();
    }

    // -----------------------------------------------------------------
    // A verificação e ela mesma
    // -----------------------------------------------------------------

    /**
     * Quem está rodando este código é `rotinas:verificar`. Um aviso dizendo que
     * ela não rodou seria escrito pela execução que prova o contrário, e sairia
     * em toda instalação nova e em toda execução manual pelo terminal, que não
     * grava linha em `scheduled_task_runs`.
     */
    public function test_a_propria_verificacao_nao_se_acusa_de_nao_ter_rodado(): void
    {
        $this->todasSaudaveis(RotinasAgendadas::VERIFICACAO);

        $this->assertSame(
            0,
            ScheduledTaskRun::query()->daTarefa(RotinasAgendadas::VERIFICACAO)->count(),
            'o cenário precisa deixar a verificação sem nenhuma execução registrada'
        );

        $this->artisan('rotinas:verificar')
            ->expectsOutputToContain('Nenhuma rotina com problema')
            ->assertSuccessful();

        $this->assertSame(0, $this->totalDeAvisos());
    }

    /**
     * Falha da passada anterior, ao contrário, é informação real: aconteceu, foi
     * registrada, e esta execução está viva para reportar.
     */
    public function test_a_propria_verificacao_e_reportada_quando_a_passada_anterior_falhou(): void
    {
        $this->todasSaudaveis(RotinasAgendadas::VERIFICACAO);

        $this->registrarExecucao(
            RotinasAgendadas::VERIFICACAO,
            RegistraExecucaoAgendada::STATUS_FALHOU,
            now()->subMinutes(5),
            'RuntimeException: Nenhuma empresa cadastrada'
        );

        $this->artisan('rotinas:verificar')->assertSuccessful();

        $aviso = $this->avisoDe($this->administradorA);

        $this->assertNotNull($aviso);
        $this->assertStringContainsString(RotinasAgendadas::VERIFICACAO, (string) $aviso->assunto);
    }

    // -----------------------------------------------------------------
    // Catálogo e agendamento
    // -----------------------------------------------------------------

    /**
     * O critério "rotina de plataforma avisa o super admin" não tem caso real
     * hoje: as nove rotinas do catálogo processam dado de empresa, então todas
     * avisam o administrador de cada tenant. Este teste fixa essa leitura, para
     * que a primeira rotina genuinamente de plataforma tenha que ser declarada
     * de propósito, e não escorregue para o caminho de tenant sem ninguém notar.
     */
    public function test_nenhuma_rotina_atual_e_de_plataforma(): void
    {
        $this->assertSame([], RotinasAgendadas::DE_PLATAFORMA);

        foreach (RotinasAgendadas::assinaturas() as $assinatura) {
            $this->assertFalse(
                RotinasAgendadas::ehDePlataforma($assinatura),
                "a rotina {$assinatura} foi marcada como de plataforma sem o caminho do super admin existir"
            );
        }
    }

    public function test_a_janela_de_tolerancia_soma_a_folga_ao_intervalo_declarado(): void
    {
        $this->assertSame(
            RotinasAgendadas::MINUTOS_DE_UM_DIA + RotinasAgendadas::MINUTOS_DE_TOLERANCIA_EXTRA,
            RotinasAgendadas::janelaDeToleranciaEmMinutos(self::ROTINA_DIARIA)
        );

        // As 26 horas do plano.
        $this->assertSame(1560, RotinasAgendadas::janelaDeToleranciaEmMinutos(self::ROTINA_DIARIA));

        $this->assertSame(
            RotinasAgendadas::POR_INTERVALO[self::ROTINA_DE_INTERVALO] + RotinasAgendadas::MINUTOS_DE_TOLERANCIA_EXTRA,
            RotinasAgendadas::janelaDeToleranciaEmMinutos(self::ROTINA_DE_INTERVALO)
        );

        $this->assertNull(RotinasAgendadas::janelaDeToleranciaEmMinutos('comando:que-nao-existe'));
    }

    public function test_a_verificacao_esta_agendada_de_hora_em_hora(): void
    {
        $this->assertSame(60, RotinasAgendadas::POR_INTERVALO[RotinasAgendadas::VERIFICACAO] ?? null);

        $this->artisan('schedule:list')->assertSuccessful();

        $agendados = collect($this->app->make(Schedule::class)->events())->filter(
            fn ($evento): bool => is_string($evento->command)
                && str_contains($evento->command, RotinasAgendadas::VERIFICACAO)
        );

        $this->assertCount(1, $agendados, 'a verificação não está agendada exatamente uma vez');
    }

    /**
     * O código de saída responde "a verificação funcionou?", e não "o sistema
     * está saudável?". Devolver falha ao encontrar rotina parada marcaria esta
     * execução como `failed` em `scheduled_task_runs`, e a passada seguinte
     * acusaria a própria verificação de ter falhado.
     */
    public function test_rotina_com_problema_nao_faz_a_verificacao_terminar_em_erro(): void
    {
        $this->rotinaQueFalhou();

        $this->artisan('rotinas:verificar')->assertExitCode(0);
    }

    /**
     * `routines:status` e `rotinas:verificar` precisam responder a mesma coisa
     * sobre a mesma rotina.
     *
     * O diagnóstico usava um limiar fixo de 48 horas, enquanto o aviso usa a
     * janela declarada de cada rotina. Com os dois números diferentes, uma
     * diária parada há 30 horas gerava e-mail ao administrador e aparecia como
     * "não atrasada" na tabela do terminal: o comando que existe para tirar a
     * dúvida virava a fonte da dúvida.
     */
    public function test_o_diagnostico_no_terminal_concorda_com_o_aviso_enviado(): void
    {
        $this->todasSaudaveis();

        $this->artisan('routines:status')->assertExitCode(0);
        $this->artisan('rotinas:verificar')->assertSuccessful();
        $this->assertSame(0, $this->totalDeAvisos());

        $this->todasSaudaveis(self::ROTINA_DIARIA);
        $this->registrarExecucao(self::ROTINA_DIARIA, RegistraExecucaoAgendada::STATUS_SUCESSO, now()->subHours(30));

        $this->artisan('rotinas:verificar')->assertSuccessful();

        $this->assertNotNull($this->avisoDe($this->administradorA), 'o aviso saiu');
        $this->artisan('routines:status')->assertExitCode(1);
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * Cenário mais usado: tudo saudável e a rotina diária com a última execução
     * em erro, precedida de um sucesso ainda dentro da janela. A falha é o
     * registro mais recente da rotina, que é o que a detecção lê.
     */
    private function rotinaQueFalhou(?string $saida = null): void
    {
        $this->todasSaudaveis(self::ROTINA_DIARIA);

        $this->registrarExecucao(self::ROTINA_DIARIA, RegistraExecucaoAgendada::STATUS_SUCESSO, now()->subHours(25));
        $this->registrarExecucao(self::ROTINA_DIARIA, RegistraExecucaoAgendada::STATUS_FALHOU, now()->subMinutes(5), $saida);
    }

    /**
     * Registra uma execução recente com sucesso para toda rotina do catálogo,
     * menos a que o cenário vai estragar.
     */
    private function todasSaudaveis(?string $exceto = null): void
    {
        ScheduledTaskRun::query()->delete();

        foreach (RotinasAgendadas::assinaturas() as $assinatura) {
            if ($assinatura === $exceto) {
                continue;
            }

            $this->registrarExecucao($assinatura, RegistraExecucaoAgendada::STATUS_SUCESSO, now()->subMinutes(10));
        }
    }

    private function registrarExecucao(
        string $rotina,
        string $status,
        CarbonInterface $quando,
        ?string $saida = null
    ): ScheduledTaskRun {
        return ScheduledTaskRun::create([
            'task' => $rotina,
            'started_at' => $quando,
            'finished_at' => $quando->copy()->addSeconds(2),
            'status' => $status,
            'duration_ms' => 2000,
            'output' => $saida,
        ]);
    }

    /**
     * O aviso de rotina enfileirado para este usuário, ou null.
     *
     * A consulta não precisa desligar o escopo por empresa: o teste roda fora de
     * requisição, sem tenant resolvido, e nesse estado o escopo global não
     * filtra nada. É o que permite conferir as duas empresas de uma vez.
     */
    private function avisoDe(User $usuario): ?NotificationQueue
    {
        return NotificationQueue::query()
            ->where('evento', EventosDeNotificacao::ROTINA_AGENDADA_FALHOU)
            ->where('destinatario_tipo', NotificationQueue::DESTINATARIO_USUARIO)
            ->where('destinatario_id', $usuario->id)
            ->orderByDesc('id')
            ->first();
    }

    private function totalDeAvisos(): int
    {
        return NotificationQueue::query()
            ->where('evento', EventosDeNotificacao::ROTINA_AGENDADA_FALHOU)
            ->count();
    }

    private function criarAdministrador(Company $empresa, string $email): User
    {
        return $this->criarUsuarioComPapel($empresa, AvisoDeRotinaFalha::PAPEL_DE_QUEM_RECEBE, $email);
    }

    /**
     * `is_active` vai explícito porque a factory não o declara, e o cast booleano
     * resolveria o atributo ausente como `false` no objeto em memória.
     */
    private function criarUsuarioComPapel(Company $empresa, string $papel, string $email): User
    {
        return TenantAtual::comTenant((int) $empresa->id, function () use ($papel, $email): User {
            $usuario = User::factory()->create(['email' => $email, 'is_active' => true]);
            $usuario->assignRole($papel);

            return $usuario;
        });
    }
}
