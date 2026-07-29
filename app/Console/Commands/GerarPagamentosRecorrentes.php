<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\OperaPorTenant;
use App\Models\Payable;
use App\Services\PayableService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Rotina (Plano 18, Task 18.5) que mantém sempre as próximas 3 competências
 * geradas para cada título a pagar recorrente vigente (aluguel, assinatura,
 * qualquer despesa que se repete).
 *
 * Nunca gera tudo até `recorrencia_ate` de uma vez: um aluguel de contrato de
 * 5 anos não pode virar 60 títulos que ninguém revisa. A cada execução, esta
 * rotina só empurra a janela de 3 competências para frente, na medida em que o
 * tempo passa (`PayableService::manterJanelaDeCompetencias()`).
 *
 * Mesma disciplina de tenant explícito da Task 18.3
 * (`GerarTitulosDeContrato`): percorre as empresas uma a uma, dentro do
 * tenant de cada uma (`OperaPorTenant`), e uma empresa com erro não impede a
 * geração das demais. Dentro da mesma empresa, um título recorrente com erro
 * também não pode travar os demais: o erro é reportado e a rotina continua.
 *
 * Idempotente pela chave `[payable_origem_id, competência]`: rodar esta
 * rotina duas vezes seguidas não duplica competência nenhuma.
 *
 * Só percorre títulos raiz (`payable_origem_id` nulo): uma ocorrência já
 * gerada nunca é, ela mesma, o ponto de partida de uma nova geração, mesmo
 * critério de `payable_origem_id` documentado na migration.
 */
class GerarPagamentosRecorrentes extends Command
{
    use OperaPorTenant;

    protected $signature = 'financeiro:gerar-pagamentos-recorrentes';

    protected $description = 'Mantém sempre as próximas 3 competências geradas de cada título a pagar recorrente, sem duplicar competência já gerada.';

    /**
     * Sinaliza que ao menos um título, em alguma empresa desta execução,
     * terminou com erro. Mesmo critério de `GerarTitulosDeContrato::$algumContratoFalhou`.
     */
    private bool $algumTituloFalhou = false;

    public function __construct(private readonly PayableService $payableService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Gerando as próximas competências dos títulos a pagar recorrentes...');

        $codigo = $this->paraCadaTenant(fn (): int => $this->gerarDaEmpresa());

        return $codigo === Command::SUCCESS && $this->algumTituloFalhou ? Command::FAILURE : $codigo;
    }

    /**
     * Uma empresa, já dentro do tenant dela: garante a janela de 3
     * competências de cada título recorrente raiz vigente, isolando o erro de
     * um título dos demais.
     *
     * @return int quantidade de títulos recorrentes processados com sucesso
     */
    private function gerarDaEmpresa(): int
    {
        $raizes = Payable::query()
            ->whereNull('payable_origem_id')
            ->where('recorrencia', '!=', 'nenhuma')
            ->get();

        $processados = 0;
        $comErro = 0;
        $totalGerado = 0;

        foreach ($raizes as $raiz) {
            try {
                $totalGerado += $this->payableService->manterJanelaDeCompetencias($raiz);
                $processados++;
            } catch (Throwable $erro) {
                report($erro);

                $comErro++;
                $this->algumTituloFalhou = true;
                $this->warn("  Título #{$raiz->id} ({$raiz->descricao}): erro ao gerar competência - {$erro->getMessage()}");
            }
        }

        $this->line("  Títulos recorrentes processados: {$processados}");
        $this->line("  Competências novas geradas: {$totalGerado}");

        if ($comErro > 0) {
            $this->line("  Títulos com erro: {$comErro}");
        }

        return $processados;
    }
}
