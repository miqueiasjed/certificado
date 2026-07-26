<?php

namespace App\Services\Notification;

use App\Listeners\RegistraExecucaoAgendada;
use App\Models\Company;
use App\Models\NotificationQueue;
use App\Models\ScheduledTaskRun;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\BusinessDate;
use App\Support\EventosDeNotificacao;
use App\Support\RotinasAgendadas;
use DateTimeInterface;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Exceptions\RoleDoesNotExist;

/**
 * Descobre qual rotina agendada parou de rodar e avisa quem pode agir.
 *
 * O Plano 1 registra em `scheduled_task_runs` o que rodou, e o comando
 * `routines:status` mostra esse histórico para quem abrir o terminal. O que
 * ficava de fora é justamente o caso grave: rotina que deixa de executar não
 * grava linha nenhuma, então o silêncio no histórico é idêntico ao silêncio de
 * um sistema saudável. Ninguém descobre que os certificados pararam de vencer
 * até a fiscalização apontar um documento errado.
 *
 * Dois problemas, e são diferentes
 * --------------------------------
 * - `falhou`: a última execução terminou com erro. Existe registro, existe
 *   mensagem, e o aviso carrega essa mensagem.
 * - `nao_rodou`: nenhuma execução com sucesso dentro da janela de tolerância da
 *   rotina. É o caso que não deixa rastro, e o motivo desta classe existir.
 *
 * Quando os dois valem para a mesma rotina, o aviso sai como `falhou`: a
 * mensagem de erro registrada é mais acionável que "não rodou", e o destinatário
 * recebe um aviso por rotina por dia de qualquer forma.
 *
 * Uma detecção, vários destinatários
 * ----------------------------------
 * `scheduled_task_runs` é tabela de plataforma, sem `company_id`: existe uma
 * linha por execução do comando agendado, não uma por empresa processada dentro
 * dele. Por isso `problemas()` roda uma vez só, fora de qualquer tenant, e
 * `avisarAdministradores()` é chamado depois, uma vez por empresa, já dentro do
 * tenant dela. Rotina parada afeta todas as empresas igualmente, e todas
 * precisam saber.
 *
 * Instante é comparado em UTC
 * ---------------------------
 * O tempo decorrido desde a última execução é diferença entre dois instantes, e
 * diferença entre instantes não muda com o fuso. O fuso do negócio entra na
 * exibição, e só nela: o que vai no e-mail é `dd/mm/aaaa HH:mm` em Brasília,
 * porque quem lê o aviso vive nesse fuso.
 */
class AvisoDeRotinaFalha
{
    /** A última execução terminou com erro. */
    public const PROBLEMA_FALHOU = 'falhou';

    /** Nenhuma execução com sucesso dentro da janela de tolerância. */
    public const PROBLEMA_NAO_RODOU = 'nao_rodou';

    /**
     * Papel de quem recebe o aviso.
     *
     * O aviso vai ao administrador, e não ao técnico: rotina parada se resolve
     * mexendo em cron e em servidor, e o técnico em campo não tem o que fazer
     * com a informação além de se preocupar.
     */
    public const PAPEL_DE_QUEM_RECEBE = 'administrador';

    /**
     * Teto de caracteres da mensagem de erro copiada para o e-mail.
     *
     * A coluna `output` guarda até 2000 caracteres da saída da rotina. Despejar
     * tudo isso no corpo de um e-mail entrega um stack trace a quem administra
     * uma dedetizadora; as primeiras linhas são o que identifica o problema para
     * quem der suporte.
     */
    private const LIMITE_DA_MENSAGEM = 500;

    public function __construct(private readonly NotificationService $notificacoes) {}

    /**
     * Rotinas do catálogo que estão com problema agora.
     *
     * Consulta `scheduled_task_runs` sem filtro de empresa, de propósito: a
     * tabela é de plataforma e a pergunta ("esta rotina rodou?") não tem tenant.
     *
     * @return array<int, array{
     *     assinatura: string,
     *     tipo: string,
     *     horario_esperado: string,
     *     ultima_execucao: string,
     *     mensagem_erro: string
     * }>
     */
    public function problemas(): array
    {
        $problemas = [];

        foreach (RotinasAgendadas::assinaturas() as $assinatura) {
            $problema = $this->problemaDa($assinatura);

            if ($problema !== null) {
                $problemas[] = $problema;
            }
        }

        return $problemas;
    }

    /**
     * Enfileira o aviso de cada problema para cada administrador da empresa.
     *
     * Precisa ser chamado com o tenant já resolvido: `NotificationService`
     * exige empresa corrente para montar o cabeçalho da mensagem, e
     * `User::daEmpresaAtual()` depende do mesmo tenant para não devolver o
     * administrador da empresa vizinha.
     *
     * @param  array<int, array<string, string>>  $problemas
     * @return array{enfileirados: int, duplicados: int, sem_envio: int, administradores: int}
     */
    public function avisarAdministradores(array $problemas, Company $empresa): array
    {
        $contagem = ['enfileirados' => 0, 'duplicados' => 0, 'sem_envio' => 0, 'administradores' => 0];

        if ($problemas === []) {
            return $contagem;
        }

        $administradores = $this->administradoresDaEmpresa();
        $contagem['administradores'] = count($administradores);

        if ($administradores === []) {
            // Não é erro da rotina, e por isso não lança: é cadastro
            // incompleto. Mas precisa deixar rastro, ou a empresa fica sem
            // saber que a rotina parou e sem saber que o aviso não saiu.
            Log::warning('Rotina agendada com problema e nenhum administrador ativo para avisar.', [
                'empresa_id' => $empresa->id,
                'rotinas' => array_column($problemas, 'assinatura'),
            ]);

            return $contagem;
        }

        // O dia entra no marco, e é o que faz a verificação de hora em hora
        // gerar um aviso por rotina por dia em vez de vinte e quatro. Dia no
        // fuso do negócio: em UTC a virada aconteceria às 21h de Brasília e o
        // administrador receberia dois avisos da mesma rotina na mesma noite.
        $dia = BusinessDate::hoje()->toDateString();

        foreach ($problemas as $problema) {
            foreach ($administradores as $administrador) {
                $resultado = $this->notificacoes->enfileirar(
                    EventosDeNotificacao::ROTINA_AGENDADA_FALHOU,
                    // A referência é a própria empresa: o aviso não fala de
                    // cliente, de OS nem de certificado, fala do sistema que
                    // aquela empresa usa. Também é o Model que sempre existe
                    // nesta volta do laço, o que mantém a chave de idempotência
                    // estável entre execuções.
                    $empresa,
                    [
                        'destinatario' => $administrador,
                        'destinatario_tipo' => NotificationQueue::DESTINATARIO_USUARIO,
                        'marco' => $problema['assinatura'].':'.$dia,
                        'variaveis' => [
                            'rotina_nome' => $problema['assinatura'],
                            'horario_esperado' => $problema['horario_esperado'],
                            'ultima_execucao' => $problema['ultima_execucao'],
                            'mensagem_erro' => $problema['mensagem_erro'],
                        ],
                    ]
                );

                match ($resultado['resultado']) {
                    NotificationService::RESULTADO_ENFILEIRADA => $contagem['enfileirados']++,
                    NotificationService::RESULTADO_DUPLICADA => $contagem['duplicados']++,
                    default => $contagem['sem_envio']++,
                };
            }
        }

        return $contagem;
    }

    // -----------------------------------------------------------------
    // Detecção
    // -----------------------------------------------------------------

    /**
     * O problema desta rotina, ou `null` quando ela está saudável.
     *
     * @return array<string, string>|null
     */
    private function problemaDa(string $assinatura): ?array
    {
        $ultima = ScheduledTaskRun::query()->daTarefa($assinatura)->ultimas(1)->first();
        $ultimoSucesso = $this->ultimoSucessoDe($assinatura);

        if ($ultima !== null && $ultima->status === RegistraExecucaoAgendada::STATUS_FALHOU) {
            return $this->montar($assinatura, self::PROBLEMA_FALHOU, $ultimoSucesso, $this->mensagemDaFalha($ultima));
        }

        if ($this->ausenciaContaComoFalha($assinatura) && $this->foraDaJanela($assinatura, $ultimoSucesso)) {
            return $this->montar(
                $assinatura,
                self::PROBLEMA_NAO_RODOU,
                $ultimoSucesso,
                $this->mensagemDaAusencia($assinatura, $ultimoSucesso)
            );
        }

        return null;
    }

    /**
     * Última execução que terminou com sucesso.
     *
     * Rodada `skipped`, barrada pela trava de sobreposição, não conta: ela não
     * processou nada, e tratá-la como sinal de vida adiaria o aviso justamente
     * na situação em que uma execução travada está bloqueando todas as
     * seguintes. Mesmo critério de `routines:status`.
     */
    private function ultimoSucessoDe(string $assinatura): ?ScheduledTaskRun
    {
        return ScheduledTaskRun::query()
            ->daTarefa($assinatura)
            ->where('status', RegistraExecucaoAgendada::STATUS_SUCESSO)
            ->ultimas(1)
            ->first();
    }

    /**
     * A ausência de execução desta rotina deve virar aviso?
     *
     * Só a própria verificação fica de fora, e por contradição: quem está
     * rodando este código é `rotinas:verificar`, então um aviso dizendo que ela
     * não rodou seria escrito pela execução que prova o contrário. É o que
     * aconteceria na primeira passada de toda instalação (nenhum sucesso
     * registrado ainda) e em toda execução manual pelo terminal, que não grava
     * linha em `scheduled_task_runs`.
     *
     * A verificação continua sendo avaliada para o problema `falhou`: aí a
     * informação é real, veio da passada anterior e esta execução está viva para
     * reportar. E se ela parar de rodar de vez, nenhum aviso sairia de qualquer
     * jeito, porque é ela quem envia todos eles; quem cobre esse caso é o
     * `routines:status` e o monitoramento do cron, não ela mesma.
     */
    private function ausenciaContaComoFalha(string $assinatura): bool
    {
        return $assinatura !== RotinasAgendadas::VERIFICACAO;
    }

    /**
     * Passou da janela de tolerância sem uma execução bem-sucedida?
     *
     * Nunca ter rodado conta como fora da janela: instalação em que a rotina
     * nunca executou é exatamente o cenário do cron que ninguém cadastrou.
     */
    private function foraDaJanela(string $assinatura, ?ScheduledTaskRun $ultimoSucesso): bool
    {
        $janela = RotinasAgendadas::janelaDeToleranciaEmMinutos($assinatura);

        if ($janela === null) {
            // Assinatura fora do catálogo não tem janela declarada, e inventar
            // uma aqui seria a periodicidade duplicada que o catálogo existe
            // para evitar.
            return false;
        }

        if ($ultimoSucesso === null || $ultimoSucesso->started_at === null) {
            return true;
        }

        return $this->minutosDesde($ultimoSucesso->started_at) > $janela;
    }

    /**
     * Minutos decorridos até agora.
     *
     * Diferença entre instantes, sem passar por `BusinessDate::paraFusoNegocio`:
     * aquele conversor trata meia-noite exata como dia puro e a reconstrói no
     * fuso do negócio, o que deslocaria em três horas o cálculo de uma rotina
     * que tenha rodado às 00:00:00 em UTC.
     */
    private function minutosDesde(DateTimeInterface $instante): int
    {
        return (int) BusinessDate::agora()->diffInMinutes($instante, absolute: true);
    }

    /**
     * @return array<string, string>
     */
    private function montar(
        string $assinatura,
        string $tipo,
        ?ScheduledTaskRun $ultimoSucesso,
        string $mensagem
    ): array {
        return [
            'assinatura' => $assinatura,
            'tipo' => $tipo,
            'horario_esperado' => $this->horarioEsperado($assinatura),
            'ultima_execucao' => $ultimoSucesso?->started_at !== null
                ? $this->instanteEmTexto($ultimoSucesso->started_at)
                : 'nunca executou',
            'mensagem_erro' => $mensagem,
        ];
    }

    // -----------------------------------------------------------------
    // Texto do aviso
    // -----------------------------------------------------------------

    /**
     * Quando a rotina deveria ter rodado, em português.
     *
     * Sai do mesmo catálogo que agenda o comando, e não de um texto paralelo:
     * mudar o horário em `RotinasAgendadas` muda o agendamento e o aviso ao
     * mesmo tempo, sem chance de o e-mail cobrar um horário que ninguém agendou.
     */
    private function horarioEsperado(string $assinatura): string
    {
        $horario = RotinasAgendadas::DIARIAS[$assinatura] ?? null;

        if ($horario !== null) {
            return "todo dia às {$horario} (".BusinessDate::fuso().')';
        }

        $minutos = RotinasAgendadas::POR_INTERVALO[$assinatura] ?? null;

        return $minutos !== null ? "a cada {$minutos} minutos" : 'sem horário declarado';
    }

    /**
     * Mensagem de uma execução que terminou com erro.
     */
    private function mensagemDaFalha(ScheduledTaskRun $ultima): string
    {
        $saida = trim((string) $ultima->output);

        if ($saida === '') {
            $quando = $ultima->started_at !== null
                ? ' de '.$this->instanteEmTexto($ultima->started_at)
                : '';

            return 'A última execução terminou com erro e não deixou mensagem na saída. '
                ."Confira o log da aplicação no horário{$quando}.";
        }

        return $this->cortar($saida, self::LIMITE_DA_MENSAGEM);
    }

    /**
     * Mensagem de uma rotina que simplesmente não rodou.
     *
     * Não existe erro para copiar aqui, e é esse o ponto: a variável do template
     * precisa dizer o que aconteceu, ou o e-mail chega com a linha "Mensagem
     * registrada:" vazia e parece que o aviso é que está quebrado.
     */
    private function mensagemDaAusencia(string $assinatura, ?ScheduledTaskRun $ultimoSucesso): string
    {
        if ($ultimoSucesso === null) {
            return 'Nenhuma execução com sucesso foi registrada até agora. '
                .'Confira se a linha de cron do schedule:run está ativa no servidor.';
        }

        return 'Nenhuma execução com sucesso '.$this->janelaEmTexto($assinatura).'. '
            .'Confira se a linha de cron do schedule:run está ativa no servidor.';
    }

    /**
     * Janela de tolerância em texto: "nas últimas 26 horas", "nos últimos 125
     * minutos". Hora cheia sai em horas porque é assim que a pessoa lê o prazo.
     */
    private function janelaEmTexto(string $assinatura): string
    {
        $janela = RotinasAgendadas::janelaDeToleranciaEmMinutos($assinatura);

        if ($janela === null) {
            return 'dentro da janela esperada';
        }

        return $janela % 60 === 0
            ? 'nas últimas '.intdiv($janela, 60).' horas'
            : "nos últimos {$janela} minutos";
    }

    /**
     * Instante gravado em UTC, exibido no fuso do negócio. Data em aviso segue a
     * mesma regra do resto do sistema: formatada no backend, em Brasília.
     */
    private function instanteEmTexto(DateTimeInterface $instante): string
    {
        return BusinessDate::paraFusoNegocio($instante)?->format('d/m/Y H:i') ?? '-';
    }

    private function cortar(string $texto, int $limite): string
    {
        return mb_strlen($texto) <= $limite ? $texto : mb_substr($texto, 0, $limite - 3).'...';
    }

    // -----------------------------------------------------------------
    // Destinatários
    // -----------------------------------------------------------------

    /**
     * Administradores ativos da empresa corrente.
     *
     * `daEmpresaAtual()` é obrigatório: `User` é a única exceção do sistema ao
     * escopo global de leitura, então sem ele a consulta traria o administrador
     * de todas as empresas e cada uma receberia o aviso da outra.
     *
     * Administrador desativado fica de fora: a conta dele não entra mais no
     * sistema, e mandar alerta operacional para quem foi desligado é vazamento
     * de informação da empresa.
     *
     * @return array<int, User>
     */
    private function administradoresDaEmpresa(): array
    {
        try {
            return User::query()
                ->daEmpresaAtual()
                ->role(self::PAPEL_DE_QUEM_RECEBE)
                ->where('is_active', true)
                ->orderBy('id')
                ->get()
                ->all();
        } catch (RoleDoesNotExist) {
            // Instalação em que o catálogo de papéis ainda não foi semeado. Sem
            // o papel não existe a quem avisar, e derrubar a verificação inteira
            // por causa disso deixaria de detectar as rotinas paradas também.
            Log::warning('Papel "'.self::PAPEL_DE_QUEM_RECEBE.'" não existe: nenhum aviso de rotina foi enfileirado.');

            return [];
        }
    }
}
