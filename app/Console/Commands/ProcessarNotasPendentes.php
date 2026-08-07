<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\OperaPorTenant;
use App\Models\Company;
use App\Services\Fiscal\CancelamentoDeNotaService;
use App\Services\ServiceInvoiceService;
use Illuminate\Console\Command;

class ProcessarNotasPendentes extends Command
{
    use OperaPorTenant;

    protected $signature = 'fiscal:processar-notas';

    protected $description = 'Consulta notas em processamento e repete falhas fiscais temporárias';

    public function __construct(
        private readonly ServiceInvoiceService $notas,
        private readonly CancelamentoDeNotaService $cancelamentos,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        return $this->paraCadaTenant(function (Company $empresa): int {
            $quantidade = $this->notas->processarNotas();
            $cancelamentos = $this->cancelamentos->processarCancelamentosPendentes();
            $this->line("Notas examinadas na empresa #{$empresa->id}: {$quantidade}; cancelamentos: {$cancelamentos}.");

            return $quantidade + $cancelamentos;
        });
    }
}
