<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\OperaPorTenant;
use App\Models\Contract;
use App\Services\ReceivableService;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Rotina mensal (Task 18.3) que gera o título a receber da competência do mês
 * corrente para cada contrato periódico vigente.
 *
 * Segue a mesma disciplina de tenant explícito e falha isolada da Task 14.4
 * (`EnfileirarAvisosDiarios`): percorre as empresas uma a uma, dentro do
 * tenant de cada uma (`OperaPorTenant`), e uma empresa com erro não impede a
 * geração das demais. Dentro da mesma empresa, um contrato com erro (dado
 * incompleto, endereço sem cliente) também não pode travar os contratos
 * seguintes dela: o erro é reportado e a rotina continua.
 *
 * Idempotente por `[contract_id, competência]` (ver `ReceivableService::gerarDoContrato()`):
 * rodar esta rotina duas vezes na mesma competência não duplica título, o que
 * é o que permite reprocessar um mês que falhou no meio sem gerar cobrança
 * dobrada para o cliente final.
 */
class GerarTitulosDeContrato extends Command
{
    use OperaPorTenant;

    protected $signature = 'financeiro:gerar-titulos-de-contrato
                            {--competencia= : Competência YYYY-MM a gerar. Sem esta opção, usa o mês corrente no fuso do negócio.}';

    protected $description = 'Gera o título a receber da competência do mês para cada contrato periódico vigente, sem duplicar título já gerado.';

    /**
     * Sinaliza que ao menos um contrato, em alguma empresa desta execução,
     * terminou com erro. `paraCadaTenant()` só marca a execução como
     * `Command::FAILURE` quando o callback lança exceção; contrato com erro
     * não lança (é capturado dentro de `gerarDaEmpresa()`), então o código de
     * saída final precisa combinar os dois sinais, o mesmo critério já usado
     * em `GerarVisitasDeContratos::$algumContratoFalhou`.
     */
    private bool $algumContratoFalhou = false;

    public function __construct(private readonly ReceivableService $receivableService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $competencia = $this->resolverCompetencia();

        if ($competencia === false) {
            return Command::INVALID;
        }

        $this->info("Gerando títulos de contrato da competência {$competencia}...");

        $codigo = $this->paraCadaTenant(fn (): int => $this->gerarDaEmpresa($competencia));

        return $codigo === Command::SUCCESS && $this->algumContratoFalhou ? Command::FAILURE : $codigo;
    }

    /**
     * Uma empresa, já dentro do tenant dela: gera o título de cada contrato
     * periódico vigente na competência informada, isolando o erro de um
     * contrato dos demais.
     *
     * @return int quantidade de contratos processados com sucesso
     */
    private function gerarDaEmpresa(string $competencia): int
    {
        [$ano, $mes] = array_map('intval', explode('-', $competencia));

        $contratos = $this->contratosNaCompetencia($ano, $mes)->get();

        $processados = 0;
        $comErro = 0;

        foreach ($contratos as $contrato) {
            try {
                $this->receivableService->gerarDoContrato($contrato, $competencia);
                $processados++;
            } catch (Throwable $erro) {
                report($erro);

                $comErro++;
                $this->algumContratoFalhou = true;
                $this->warn("  Contrato #{$contrato->id} ({$contrato->contract_number}): erro ao gerar título - {$erro->getMessage()}");
            }
        }

        $this->line("  Contratos processados: {$processados}");

        if ($comErro > 0) {
            $this->line("  Contratos com erro: {$comErro}");
        }

        return $processados;
    }

    /**
     * Contratos periódicos vigentes durante a competência informada:
     * `service_type` diferente de `pontual` (contrato pontual não tem
     * cobrança recorrente), `start_date` não posterior ao fim do mês e
     * `end_date` nulo ou não anterior ao início do mês.
     */
    private function contratosNaCompetencia(int $ano, int $mes): Builder
    {
        $primeiroDia = CarbonImmutable::create($ano, $mes, 1);
        $ultimoDia = $primeiroDia->endOfMonth();

        return Contract::query()
            ->where('service_type', '!=', 'pontual')
            ->where(function (Builder $consulta) use ($ultimoDia) {
                $consulta->whereNull('start_date')
                    ->orWhere('start_date', '<=', $ultimoDia->toDateString());
            })
            ->where(function (Builder $consulta) use ($primeiroDia) {
                $consulta->whereNull('end_date')
                    ->orWhere('end_date', '>=', $primeiroDia->toDateString());
            });
    }

    /**
     * Lê `--competencia`. Sem a opção, usa o mês corrente no fuso do negócio.
     * Devolve `false` quando o valor informado não está no formato `YYYY-MM`.
     */
    private function resolverCompetencia(): string|false
    {
        $opcao = $this->option('competencia');

        if ($opcao === null) {
            return BusinessDate::hoje()->format('Y-m');
        }

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $opcao) !== 1) {
            $this->error("--competencia precisa estar no formato YYYY-MM. Valor recebido: \"{$opcao}\".");

            return false;
        }

        return $opcao;
    }
}
