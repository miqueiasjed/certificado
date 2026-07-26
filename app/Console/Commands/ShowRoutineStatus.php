<?php

namespace App\Console\Commands;

use App\Listeners\RegistraExecucaoAgendada;
use App\Models\ScheduledTaskRun;
use App\Support\BusinessDate;
use App\Support\RotinasAgendadas;
use DateTimeInterface;
use Illuminate\Console\Command;

/**
 * Mostra a última execução de cada rotina agendada, para diagnosticar se o
 * cron do `schedule:run` está rodando.
 *
 * Comando somente leitura: consulta scheduled_task_runs e não grava nada
 * nela. A lista de rotinas vem de RotinasAgendadas::todas(), que junta as
 * diárias e as de intervalo curto lidas pelo agendamento em
 * bootstrap/app.php, para que o diagnóstico nunca divirja do que de fato
 * está agendado.
 */
class ShowRoutineStatus extends Command
{
    protected $signature = 'routines:status
                            {--task= : Mostra apenas a rotina com esta assinatura}';

    protected $description = 'Mostra quando cada rotina agendada rodou pela última vez e se alguma está atrasada. Comando somente leitura, não grava nada em scheduled_task_runs.';

    /**
     * Larguras de coluna da tabela impressa, na mesma ordem do cabeçalho.
     *
     * @var array<int, int>
     */
    private const LARGURA_COLUNAS = [30, 17, 25, 10, 10];

    public function handle(): int
    {
        $tarefas = $this->tarefasParaVerificar();

        if ($tarefas === null) {
            return Command::FAILURE;
        }

        $linhas = array_map(
            fn (string $assinatura): array => $this->montarLinha($assinatura),
            array_keys($tarefas)
        );

        $this->imprimirTabela($linhas);

        $existeProblema = collect($linhas)->contains(
            fn (array $linha): bool => $linha['atrasada'] || $linha['statusBruto'] === RegistraExecucaoAgendada::STATUS_FALHOU
        );

        return $existeProblema ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Resolve a lista de rotinas a verificar, considerando o filtro --task.
     * Devolve null quando o filtro não corresponde a nenhuma rotina
     * conhecida, e já imprime o erro nesse caso.
     *
     * @return array<string, string>|null
     */
    private function tarefasParaVerificar(): ?array
    {
        $rotinas = RotinasAgendadas::todas();
        $filtro = $this->option('task');

        if ($filtro === null) {
            return $rotinas;
        }

        if (! array_key_exists($filtro, $rotinas)) {
            $this->error("Rotina '{$filtro}' não é uma rotina agendada conhecida.");
            $this->line('Rotinas disponíveis: '.implode(', ', RotinasAgendadas::assinaturas()));

            return null;
        }

        return [$filtro => $rotinas[$filtro]];
    }

    /**
     * Monta a linha de diagnóstico de uma rotina: última execução (qualquer
     * status), e se está atrasada com base apenas nas execuções com sucesso.
     *
     * @return array{rotina: string, ultimaExecucao: string, status: string, duracao: string, atrasada: bool, statusBruto: ?string}
     */
    private function montarLinha(string $assinatura): array
    {
        $ultima = ScheduledTaskRun::daTarefa($assinatura)->ultimas(1)->first();

        $ultimoSucesso = ScheduledTaskRun::daTarefa($assinatura)
            ->where('status', RegistraExecucaoAgendada::STATUS_SUCESSO)
            ->ultimas(1)
            ->first();

        return [
            'rotina' => $assinatura,
            'ultimaExecucao' => $ultima !== null ? $this->formatarInstante($ultima->started_at) : 'nunca executou',
            'status' => $ultima !== null ? $this->rotuloDoStatus($ultima->status) : '-',
            'duracao' => $this->formatarDuracao($ultima?->duration_ms),
            'atrasada' => $this->estaAtrasada($assinatura, $ultimoSucesso),
            'statusBruto' => $ultima?->status,
        ];
    }

    /**
     * Atrasada quando nunca houve execução com sucesso, ou quando a última
     * passou da janela de tolerância declarada para aquela rotina.
     *
     * A janela vem de `RotinasAgendadas`, e não de um limiar fixo aqui, para que
     * este diagnóstico e o aviso automático de `rotinas:verificar` respondam a
     * mesma coisa. Com o limiar fixo de 48 horas que existia antes, uma diária
     * parada há 30 horas gerava e-mail ao administrador e aparecia como "não
     * atrasada" nesta tabela, e o comando que existe para tirar a dúvida virava
     * a fonte da dúvida.
     *
     * Uma rodada `skipped`, barrada pela trava de sobreposição, não conta como
     * sucesso e por isso não adia o atraso: ela é tratada como se a rotina não
     * tivesse rodado.
     */
    private function estaAtrasada(string $assinatura, ?ScheduledTaskRun $ultimoSucesso): bool
    {
        if ($ultimoSucesso === null || $ultimoSucesso->started_at === null) {
            return true;
        }

        $janela = RotinasAgendadas::janelaDeToleranciaEmMinutos($assinatura);

        if ($janela === null) {
            return false;
        }

        // Diferença entre instantes, sem passar pelo conversor de fuso: ele
        // trata meia-noite exata como dia puro e a reconstrói em Brasília, o que
        // deslocaria em três horas a idade de uma rodada iniciada às 00:00 UTC.
        return BusinessDate::agora()->diffInMinutes($ultimoSucesso->started_at, absolute: true) > $janela;
    }

    /**
     * Formata o instante gravado em UTC no fuso do negócio (America/Sao_Paulo),
     * nunca em UTC.
     */
    private function formatarInstante(DateTimeInterface $instante): string
    {
        return BusinessDate::paraFusoNegocio($instante)?->format('d/m/Y H:i') ?? '-';
    }

    private function formatarDuracao(?int $duracaoMs): string
    {
        if ($duracaoMs === null) {
            return '-';
        }

        if ($duracaoMs < 1000) {
            return "{$duracaoMs}ms";
        }

        return number_format($duracaoMs / 1000, 2, ',', '.').'s';
    }

    private function rotuloDoStatus(string $status): string
    {
        return match ($status) {
            RegistraExecucaoAgendada::STATUS_SUCESSO => 'sucesso',
            RegistraExecucaoAgendada::STATUS_FALHOU => 'falhou',
            RegistraExecucaoAgendada::STATUS_IGNORADO => 'ignorada (sobreposição)',
            RegistraExecucaoAgendada::STATUS_EM_EXECUCAO => 'em execução',
            default => $status,
        };
    }

    /**
     * @param  array<int, array{rotina: string, ultimaExecucao: string, status: string, duracao: string, atrasada: bool, statusBruto: ?string}>  $linhas
     */
    private function imprimirTabela(array $linhas): void
    {
        $this->line($this->formatarLinhaDaTabela(['Rotina', 'Última execução', 'Status', 'Duração', 'Atrasada?']));
        $this->line(str_repeat('-', array_sum(self::LARGURA_COLUNAS) + count(self::LARGURA_COLUNAS) - 1));

        foreach ($linhas as $linha) {
            $texto = $this->formatarLinhaDaTabela([
                $linha['rotina'],
                $linha['ultimaExecucao'],
                $linha['status'],
                $linha['duracao'],
                $linha['atrasada'] ? 'SIM' : 'não',
            ]);

            $destacar = $linha['atrasada'] || $linha['statusBruto'] === RegistraExecucaoAgendada::STATUS_FALHOU;

            $destacar ? $this->error($texto) : $this->line($texto);
        }
    }

    /**
     * @param  array<int, string>  $valores
     */
    private function formatarLinhaDaTabela(array $valores): string
    {
        $colunas = [];

        foreach (array_values($valores) as $indice => $valor) {
            $colunas[] = $this->preencher($valor, self::LARGURA_COLUNAS[$indice]);
        }

        return implode(' ', $colunas);
    }

    /**
     * Preenche a string até a largura informada. Usa mb_strlen, e não
     * str_pad puro, porque um dos rótulos de status leva acento
     * ("ignorada (sobreposição)"), e str_pad conta bytes, não caracteres.
     */
    private function preencher(string $texto, int $largura): string
    {
        $faltam = $largura - mb_strlen($texto);

        return $faltam > 0 ? $texto.str_repeat(' ', $faltam) : $texto;
    }
}
