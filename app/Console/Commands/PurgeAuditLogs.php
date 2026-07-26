<?php

namespace App\Console\Commands;

use App\Support\BusinessDate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Expurga de audit_logs e access_logs os registros mais antigos que o
 * período de retenção, para as tabelas não crescerem sem limite.
 *
 * Único caminho de remoção permitido para estas tabelas. AuditLog e
 * AccessLog bloqueiam exclusão pela aplicação (booted(): deleting() lança
 * RuntimeException) porque o registro é imutável por definição. Este
 * comando contorna o model de propósito, com DB::table() direto, e é a
 * única exceção admitida a essa regra.
 *
 * created_at é gravado em UTC, como todo instante do projeto (ver
 * Auditavel::class e RegistraAcesso::class). O corte de retenção, porém,
 * precisa ser o dia no fuso do negócio (America/Sao_Paulo): senão a
 * fronteira de expurgo se desloca até 3 horas por causa do fuso, e um
 * registro de auditoria com valor legal seria removido ou preservado no
 * dia errado. Por isso o corte é calculado em America/Sao_Paulo e só
 * depois convertido para UTC, para comparar com o valor exatamente como
 * ele está gravado no banco.
 */
class PurgeAuditLogs extends Command
{
    protected $signature = 'auditoria:purge
                            {--dry-run : Simula a execução e mostra as contagens, sem apagar nada}
                            {--dias= : Sobrepõe o período de retenção configurado em auditoria.dias_de_retencao}';

    protected $description = 'Apaga de audit_logs e access_logs os registros anteriores ao período de retenção, comparando o corte com o dia de hoje no fuso do negócio (America/Sao_Paulo).';

    /**
     * Tabelas de auditoria alcançadas pelo expurgo, na ordem de exibição.
     *
     * @var array<int, string>
     */
    private const TABELAS = ['audit_logs', 'access_logs'];

    /**
     * Linhas apagadas por iteração do laço de exclusão, para não travar a
     * tabela com um único DELETE gigante em produção.
     */
    private const TAMANHO_DO_LOTE = 1000;

    public function handle(): int
    {
        $simulacao = (bool) $this->option('dry-run');
        $dias = $this->resolverDias();

        if ($dias === null) {
            $this->error('O valor de --dias precisa ser um número inteiro e não negativo.');

            return Command::FAILURE;
        }

        $corte = BusinessDate::hoje()->subDays($dias);
        $corteParaConsulta = $corte->utc()->format('Y-m-d H:i:s');

        $this->info('Expurgo de auditoria...');
        $this->line("Dias de retenção: {$dias}");
        $this->line('Corte (' . BusinessDate::fuso() . '): remove tudo com created_at anterior a ' . $corte->toDateString() . ' 00:00');
        $this->line($simulacao
            ? 'Modo simulação (--dry-run): nada será apagado.'
            : 'Modo aplicação: os registros serão apagados em lotes de ' . self::TAMANHO_DO_LOTE . '.');

        $totalApagado = 0;

        foreach (self::TABELAS as $tabela) {
            $totalApagado += $this->expurgarTabela($tabela, $corteParaConsulta, $simulacao);
        }

        $this->newLine();
        $this->info($simulacao
            ? "Simulação concluída. {$totalApagado} registro(s) seriam removidos."
            : "Expurgo concluído. {$totalApagado} registro(s) removido(s).");

        return Command::SUCCESS;
    }

    /**
     * $dias vem de --dias quando informado, senão do período de retenção
     * configurado em config('auditoria.dias_de_retencao').
     *
     * Devolve null quando o valor informado não é um inteiro não negativo,
     * para o handle recusar a execução em vez de calcular um corte no
     * futuro, que apagaria mais do que a retenção pretende.
     */
    private function resolverDias(): ?int
    {
        $informado = $this->option('dias');

        if ($informado === null) {
            return (int) config('auditoria.dias_de_retencao');
        }

        if (! is_numeric($informado) || (int) $informado != $informado || (int) $informado < 0) {
            return null;
        }

        return (int) $informado;
    }

    /**
     * Conta, apaga em lotes (fora do modo simulação) e imprime o resultado
     * de uma tabela. Devolve a quantidade apagada (ou que seria apagada, em
     * modo simulação), para compor o total impresso ao final.
     */
    private function expurgarTabela(string $tabela, string $corteParaConsulta, bool $simulacao): int
    {
        $antes = DB::table($tabela)->count();
        $elegiveis = DB::table($tabela)->where('created_at', '<', $corteParaConsulta)->count();

        $apagados = $simulacao ? 0 : $this->apagarEmLotes($tabela, $corteParaConsulta);
        $depois = $simulacao ? $antes : DB::table($tabela)->count();

        $this->newLine();
        $this->line("Tabela {$tabela}:");
        $this->line("  Total antes: {$antes}");
        $this->line('  ' . ($simulacao ? 'Seriam apagados' : 'Apagados') . ": {$elegiveis}");
        $this->line("  Total depois: {$depois}");

        return $simulacao ? $elegiveis : $apagados;
    }

    /**
     * Apaga em lotes de até TAMANHO_DO_LOTE linhas por iteração. A mesma
     * condição do WHERE é reaplicada a cada volta porque o lote anterior já
     * removeu as linhas mais antigas: o laço termina quando um lote apaga
     * menos que o tamanho máximo, sinal de que não sobrou nenhuma linha
     * elegível.
     */
    private function apagarEmLotes(string $tabela, string $corteParaConsulta): int
    {
        $totalApagado = 0;

        do {
            $apagadosNoLote = DB::table($tabela)
                ->where('created_at', '<', $corteParaConsulta)
                ->limit(self::TAMANHO_DO_LOTE)
                ->delete();

            $totalApagado += $apagadosNoLote;
        } while ($apagadosNoLote >= self::TAMANHO_DO_LOTE);

        return $totalApagado;
    }
}
