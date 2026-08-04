<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\OperaPorTenant;
use App\Models\Company;
use App\Services\NotificationService;
use App\Services\Payments\ReguaDeCobrancaService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Rotina diária (Plano 19, Task 19.5) que executa a régua de cobrança:
 * enfileira, pela central de notificações do Plano 14, o lembrete de cada
 * parcela de contrato que está exatamente em um dos marcos ativos (3 dias
 * antes do vencimento, no vencimento, e 3/7/15 dias depois), sempre que
 * existir uma cobrança ativa para lembrar.
 *
 * Toda a decisão de negócio (quais marcos, qual evento, quando parar) está
 * em `App\Services\Payments\ReguaDeCobrancaService`; este comando só
 * percorre os tenants e imprime o resumo.
 *
 * ## Idempotente
 *
 * Rodar esta rotina duas vezes no mesmo dia não duplica aviso: a chave de
 * idempotência do `NotificationService` é `{evento}:{destinatario_tipo}:
 * {destinatario_id}:{referencia_tipo}:{referencia_id}:{marco}`, e o `marco`
 * (`antes_3`, `no_dia`, `depois_3`...) nunca muda para a mesma cobrança no
 * mesmo dia. A segunda passada do dia encontra o item já na fila e devolve
 * `duplicada`, sem gravar nada de novo.
 *
 * ## Falha isolada por tenant
 *
 * Percorre todas as empresas com `OperaPorTenant`: uma empresa com erro não
 * impede o aviso das demais. Falha de uma parcela específica já é isolada
 * dentro de `ReguaDeCobrancaService::executar()`.
 *
 * O registro em `scheduled_task_runs` é automático para toda execução
 * disparada pelo `$schedule` do Laravel (gancho `RegistraExecucaoAgendada`
 * em `bootstrap/app.php`, aplicado a partir de `RotinasAgendadas::DIARIAS`).
 */
class ExecutarReguaDeCobranca extends Command
{
    use OperaPorTenant;

    protected $signature = 'cobrancas:regua';

    protected $description = 'Executa a régua de cobrança: lembra o cliente antes, no dia e depois do vencimento da cobrança ativa da parcela de contrato.';

    public function __construct(private readonly ReguaDeCobrancaService $regua)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Executando a régua de cobrança...');

        return $this->paraCadaTenant(fn (Company $empresa): int => $this->executarDaEmpresa());
    }

    /**
     * Uma empresa, já dentro do tenant dela.
     *
     * @return int quantidade de avisos novos enfileirados nesta empresa
     */
    private function executarDaEmpresa(): int
    {
        $itens = collect($this->regua->executar());

        $enfileirados = $this->contar($itens, NotificationService::RESULTADO_ENFILEIRADA);
        $duplicados = $this->contar($itens, NotificationService::RESULTADO_DUPLICADA);
        $paradas = $this->contar($itens, 'parada');
        $semCobranca = $this->contar($itens, 'sem_cobranca');
        $recusados = $this->contar($itens, NotificationService::RESULTADO_RECUSADA);
        $semDestino = $this->contar($itens, NotificationService::RESULTADO_SEM_DESTINO);
        $comErro = $this->contar($itens, 'erro');

        $this->line("  Marcos avaliados: {$itens->count()}");
        $this->line("  Avisos novos: {$enfileirados}");
        $this->line("  Já estavam na fila (rotina rodou de novo no dia): {$duplicados}");
        $this->line("  Régua parada (parcela paga ou cancelada): {$paradas}");
        $this->line("  Sem cobrança ativa para lembrar: {$semCobranca}");
        $this->line('  Sem envio (canal recusado pelo cliente ou sem destino): '.($recusados + $semDestino));

        if ($comErro > 0) {
            $this->warn("  Parcelas com erro ao processar: {$comErro}");
        }

        return $enfileirados;
    }

    /**
     * @param  Collection<int, array{receivable_installment_id: int, marco: string, resultado: string, mensagem: string}>  $itens
     */
    private function contar(Collection $itens, string $resultado): int
    {
        return $itens->where('resultado', $resultado)->count();
    }
}
