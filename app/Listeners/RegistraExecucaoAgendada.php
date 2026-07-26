<?php

namespace App\Listeners;

use App\Models\ScheduledTaskRun;
use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event as TarefaAgendada;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Grava em scheduled_task_runs o início e o fim de cada rotina agendada.
 *
 * Não é regra de negócio, é observabilidade: nenhuma decisão de domínio passa
 * por aqui, e por isso a classe fica em Listeners, e não em Services.
 *
 * Regra número um: registrar é secundário. Se a gravação falhar, a rotina
 * segue e o erro vai para o log. Certificado vencido continuar vencido importa
 * mais do que a linha de auditoria.
 *
 * A classe é chamada por dois caminhos, porque nenhum dos dois cobre tudo:
 *
 * - Eventos ScheduledTaskStarting/Finished/Failed, disparados por
 *   `schedule:run`. Cobrem também a rodada barrada pela trava de sobreposição
 *   e a exceção lançada antes do processo filho subir.
 * - Ganchos before/then de cada agendamento, pendurados em bootstrap/app.php.
 *   Cobrem `schedule:test`, que no Laravel 11 chama Event::run() direto e não
 *   dispara evento nenhum.
 *
 * Os dois caminhos convivem porque o registro é idempotente por rodada: quem
 * chegar primeiro grava, o segundo é ignorado.
 */
class RegistraExecucaoAgendada
{
    public const STATUS_EM_EXECUCAO = 'running';

    public const STATUS_SUCESSO = 'success';

    public const STATUS_FALHOU = 'failed';

    public const STATUS_IGNORADO = 'skipped';

    /**
     * Teto de caracteres gravados na coluna output.
     */
    private const LIMITE_OUTPUT = 2000;

    /**
     * Teto de bytes lidos do arquivo de saída antes do corte.
     */
    private const LIMITE_LEITURA = 8000;

    /**
     * Rodadas abertas nesta execução do processo, por assinatura da rotina.
     *
     * Estático de propósito: o container reconstrói o listener a cada disparo,
     * e o início precisa sobreviver até o fim da rodada.
     *
     * @var array<string, array{id: int|null, iniciadoEm: float}>
     */
    private static array $emAndamento = [];

    public function aoIniciar(ScheduledTaskStarting $evento): void
    {
        $this->registrarInicio($evento->task);
    }

    public function aoFinalizar(ScheduledTaskFinished $evento): void
    {
        $this->registrarFim($evento->task);
    }

    public function aoFalhar(ScheduledTaskFailed $evento): void
    {
        $this->registrarFim($evento->task, $evento->exception);
    }

    /**
     * Abre a linha da rodada com status running.
     */
    public function registrarInicio(TarefaAgendada $tarefa): void
    {
        $nome = self::nomeDaTarefa($tarefa);

        if (isset(self::$emAndamento[$nome])) {
            return;
        }

        // Reserva a vaga antes de tocar o banco. Se o insert falhar, o fim
        // ainda encontra a rodada aberta e tenta gravar tudo de uma vez.
        self::$emAndamento[$nome] = ['id' => null, 'iniciadoEm' => microtime(true)];

        try {
            $registro = ScheduledTaskRun::create([
                'task' => $nome,
                // Instante, não dia: gravado em UTC como created_at, e
                // convertido para o fuso do negócio só na exibição.
                'started_at' => now(),
                'status' => self::STATUS_EM_EXECUCAO,
            ]);

            self::$emAndamento[$nome]['id'] = $registro->id;
        } catch (Throwable $falha) {
            $this->registrarFalhaDeAuditoria('início', $nome, $falha);
        }
    }

    /**
     * Fecha a linha da rodada com o resultado.
     */
    public function registrarFim(TarefaAgendada $tarefa, ?Throwable $erro = null): void
    {
        $nome = self::nomeDaTarefa($tarefa);

        if (! isset(self::$emAndamento[$nome])) {
            return;
        }

        $rodada = self::$emAndamento[$nome];
        unset(self::$emAndamento[$nome]);

        $duracao = (int) round((microtime(true) - $rodada['iniciadoEm']) * 1000);

        $atributos = [
            'finished_at' => now(),
            'status' => $this->statusDa($tarefa, $erro),
            'duration_ms' => $duracao,
            'output' => $this->saidaDa($tarefa, $erro),
        ];

        try {
            $registro = $rodada['id'] !== null
                ? ScheduledTaskRun::find($rodada['id'])
                : null;

            if ($registro !== null) {
                $registro->update($atributos);

                return;
            }

            // O início não chegou a ser gravado: registra a rodada inteira
            // agora, para que a falha não some do histórico.
            ScheduledTaskRun::create($atributos + [
                'task' => $nome,
                'started_at' => now()->subMilliseconds($duracao),
            ]);
        } catch (Throwable $falha) {
            $this->registrarFalhaDeAuditoria('fim', $nome, $falha);
        }
    }

    /**
     * Assinatura da rotina, no mesmo formato aceito por `schedule:test --name`.
     */
    public static function nomeDaTarefa(TarefaAgendada $tarefa): string
    {
        if ($tarefa instanceof CallbackEvent || ! is_string($tarefa->command)) {
            return self::cortar($tarefa->getSummaryForDisplay(), 100);
        }

        // O command chega como "'/usr/bin/php' 'artisan' certificates:update-status".
        $prefixo = ConsoleApplication::phpBinary().' '.ConsoleApplication::artisanBinary();

        $nome = trim(str_replace($prefixo, '', $tarefa->command));

        return self::cortar($nome !== '' ? $nome : $tarefa->getSummaryForDisplay(), 100);
    }

    /**
     * Resultado da rodada.
     */
    private function statusDa(TarefaAgendada $tarefa, ?Throwable $erro): string
    {
        if ($erro !== null) {
            return self::STATUS_FALHOU;
        }

        // exitCode nulo significa que a rodada nem começou: a trava de
        // sobreposição barrou porque a anterior ainda estava viva.
        if ($tarefa->exitCode === null) {
            return self::STATUS_IGNORADO;
        }

        return $tarefa->exitCode === 0 ? self::STATUS_SUCESSO : self::STATUS_FALHOU;
    }

    /**
     * Texto gravado em output: a exceção, quando houver, ou a saída do comando.
     */
    private function saidaDa(TarefaAgendada $tarefa, ?Throwable $erro): ?string
    {
        if ($erro !== null) {
            return self::cortar(
                $erro::class.': '.$erro->getMessage(),
                self::LIMITE_OUTPUT
            );
        }

        $saida = trim((string) $this->lerArquivoDeSaida($tarefa));

        return $saida !== '' ? self::cortar($saida, self::LIMITE_OUTPUT) : null;
    }

    /**
     * Lê o arquivo de log que o agendamento redireciona com storeOutput().
     */
    private function lerArquivoDeSaida(TarefaAgendada $tarefa): ?string
    {
        $caminho = $tarefa->output;

        if (! is_string($caminho) || $caminho === '' || $caminho === $tarefa->getDefaultOutput()) {
            return null;
        }

        try {
            if (! is_file($caminho)) {
                return null;
            }

            return (string) file_get_contents($caminho, false, null, 0, self::LIMITE_LEITURA);
        } catch (Throwable $falha) {
            return null;
        }
    }

    /**
     * Corta o texto sem estourar o limite, marcando o que ficou de fora.
     */
    private static function cortar(string $texto, int $limite): string
    {
        if (mb_strlen($texto) <= $limite) {
            return $texto;
        }

        return mb_substr($texto, 0, $limite - 3).'...';
    }

    private function registrarFalhaDeAuditoria(string $momento, string $nome, Throwable $falha): void
    {
        try {
            Log::error("Falha ao registrar o {$momento} da rotina agendada. A rotina não foi interrompida.", [
                'task' => $nome,
                'excecao' => $falha::class,
                'mensagem' => $falha->getMessage(),
            ]);
        } catch (Throwable) {
            // Nem o log respondeu. Engolir aqui é a última linha de defesa:
            // sob nenhuma hipótese a auditoria derruba a rotina.
        }
    }
}
