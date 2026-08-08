<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\OperaPorTenant;
use App\Models\Company;
use App\Services\Commission\CommissionCalculationService;
use App\Support\BusinessDate;
use Illuminate\Console\Command;

/**
 * Rotina mensal (Plano 23, Task 23.2) que apura a comissão de vendedores e
 * técnicos da competência que acabou de terminar, uma empresa por vez.
 *
 * Sem `--competencia`, apura o mês anterior ao atual (no fuso do negócio): a
 * rotina roda no início do mês, para fechar a apuração do mês que acabou de
 * passar. `--competencia=YYYY-MM` permite reapurar qualquer mês (uma
 * competência fechada é simplesmente ignorada pelo Service, sem erro).
 *
 * Mesma disciplina de tenant explícito e falha isolada de
 * `GerarPagamentosRecorrentes`/`GerarTitulosDeContrato` (`OperaPorTenant`):
 * uma empresa com erro não impede a apuração das demais, e dentro da mesma
 * empresa a apuração inteira de uma pessoa nunca derruba a das outras
 * (`CommissionCalculationService::apurar()` já isola por transação própria).
 *
 * O registro em `scheduled_task_runs` é automático para toda execução
 * disparada pelo `$schedule` do Laravel (gancho `RegistraExecucaoAgendada` em
 * `bootstrap/app.php`, aplicado a partir de `RotinasAgendadas::MENSAIS`).
 */
class ApurarComissoes extends Command
{
    use OperaPorTenant;

    protected $signature = 'comissoes:apurar
                            {--competencia= : Competência YYYY-MM a apurar. Sem esta opção, apura o mês anterior ao atual}';

    protected $description = 'Apura a comissão de vendedores e técnicos da competência informada (ou do mês anterior, sem opção), por empresa.';

    public function __construct(private readonly CommissionCalculationService $apuracao)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $competencia = $this->competenciaAlvo();

        if ($competencia === null) {
            return Command::INVALID;
        }

        $this->info("Apurando comissões da competência {$competencia}...");

        return $this->paraCadaTenant(fn (Company $empresa): int => $this->apurarDaEmpresa($competencia));
    }

    private function apurarDaEmpresa(string $competencia): int
    {
        $resultado = $this->apuracao->apurar($competencia, null);

        $this->line("  Pessoas processadas: {$resultado['pessoas_processadas']}");
        $this->line("  Itens gravados: {$resultado['itens_gravados']}");

        if ($resultado['competencias_fechadas_ignoradas'] > 0) {
            $this->line("  Comissões já fechadas ou pagas, ignoradas: {$resultado['competencias_fechadas_ignoradas']}");
        }

        return $resultado['pessoas_processadas'];
    }

    /**
     * Competência a apurar, validada, ou `null` quando a opção informada é
     * inválida (o erro já foi impresso).
     */
    private function competenciaAlvo(): ?string
    {
        $informada = $this->option('competencia');

        if ($informada === null) {
            return BusinessDate::hoje()->subMonthNoOverflow()->format('Y-m');
        }

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $informada) !== 1) {
            $this->error("Competência inválida: \"{$informada}\". Use o formato YYYY-MM.");

            return null;
        }

        return $informada;
    }
}
