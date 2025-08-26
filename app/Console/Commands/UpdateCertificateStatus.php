<?php

namespace App\Console\Commands;

use App\Models\Certificate;
use Illuminate\Console\Command;
use Carbon\Carbon;

class UpdateCertificateStatus extends Command
{
    protected $signature = 'certificates:update-status';
    protected $description = 'Atualiza o status dos certificados baseado na data de garantia';

    public function handle()
    {
        $this->info('Atualizando status dos certificados...');

        // Certificados com garantia vencida
        $expiredCount = Certificate::where('status', '!=', 'cancelled')
            ->whereNotNull('warranty')
            ->where('warranty', '<', Carbon::today())
            ->update(['status' => 'expired']);

        // Certificados com garantia ativa
        $activeCount = Certificate::where('status', '!=', 'cancelled')
            ->where(function($query) {
                $query->whereNull('warranty')
                      ->orWhere('warranty', '>=', Carbon::today());
            })
            ->update(['status' => 'active']);

        $this->info("✅ {$expiredCount} certificados marcados como vencidos");
        $this->info("✅ {$activeCount} certificados marcados como ativos");
        $this->info('Status dos certificados atualizado com sucesso!');

        return Command::SUCCESS;
    }
}
