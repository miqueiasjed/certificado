<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\OperaPorTenant;
use App\Models\Company;
use App\Services\NotificationDispatcher;
use App\Support\RotinasAgendadas;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Rotina de 5 em 5 minutos que envia o que está na fila de notificações.
 *
 * Roda uma passada por empresa, cada uma dentro do tenant dela
 * (`OperaPorTenant`). Sem isso, a consulta da fila sairia sem escopo e uma
 * empresa veria o aviso da outra sair com o remetente errado, que é a falha
 * mais grave possível neste sistema.
 *
 * A cada 5 minutos, e não a cada minuto, por decisão do plano: aviso de visita
 * não é tempo real, e a folga reduz o custo de infraestrutura de um processo
 * que sobe, consulta e desce o dia inteiro.
 *
 * Duas travas, contra coisas diferentes:
 *
 * - `Cache::lock` aqui, contra duas execuções do mesmo comando ao mesmo tempo.
 *   O `withoutOverlapping` do agendamento só enxerga o scheduler, e não vê o
 *   `php artisan notificacoes:despachar` que alguém roda no terminal para
 *   destravar a fila enquanto a rotina está no meio de uma passada.
 * - O update condicional de `NotificationDispatcher::assumir()`, no banco,
 *   contra o que o lock não cobre (cache limpo, dois servidores sem cache
 *   compartilhado). É ele que garante, no fim, que o mesmo aviso não sai duas
 *   vezes.
 */
class DespacharNotificacoes extends Command
{
    use OperaPorTenant;

    protected $signature = 'notificacoes:despachar
                            {--limite=100 : Máximo de itens processados por empresa nesta passada}';

    protected $description = 'Envia os avisos da fila de notificações que já venceram, registra cada tentativa e reagenda as falhas temporárias.';

    /**
     * Chave do lock que impede duas execuções concorrentes deste comando.
     */
    private const CHAVE_DO_LOCK = 'notificacoes:despachar:lock';

    /**
     * O despachante chega por injeção no `handle()`, e não pelo construtor, de
     * propósito: comando construído no boot do console resolveria o mailer em
     * toda chamada de artisan do sistema, inclusive nas que não mandam e-mail
     * nenhum, e chegaria antes de um `Mail::fake()` de teste conseguir entrar
     * no lugar dele.
     */
    public function handle(NotificationDispatcher $despachante): int
    {
        $limite = $this->resolverLimite();

        if ($limite === null) {
            return Command::INVALID;
        }

        $lock = Cache::lock(self::CHAVE_DO_LOCK, RotinasAgendadas::MINUTOS_DE_TRAVA_CURTA * 60);

        if (! $lock->get()) {
            $this->warn(
                'Já existe uma execução de notificacoes:despachar em andamento (lock ativo). '
                .'Encerrando sem processar nada. A próxima passada acontece em até 5 minutos.'
            );

            // Sucesso, não falha: encontrar outra execução em curso e não tocar
            // em nada é exatamente o comportamento esperado.
            return Command::SUCCESS;
        }

        try {
            $this->info('Despachando a fila de notificações...');
            $this->line("Limite por empresa nesta passada: {$limite} item(ns).");

            return $this->paraCadaTenant(
                fn (Company $empresa): int => $this->despacharDaEmpresa($despachante, $limite)
            );
        } finally {
            $lock->release();
        }
    }

    /**
     * Uma empresa, já dentro do tenant dela.
     *
     * @return int quantidade de itens processados, que é o número que entra no
     *             resumo por empresa de `OperaPorTenant`
     */
    private function despacharDaEmpresa(NotificationDispatcher $despachante, int $limite): int
    {
        $resumo = $despachante->despachar($limite);

        $this->line("  Itens retomados de execução interrompida: {$resumo['destravados']}");
        $this->line("  Itens processados: {$resumo['processados']}");
        $this->line("  Enviados: {$resumo['enviados']}");
        $this->line("  Reagendados por falha temporária: {$resumo['reagendados']}");
        $this->line("  Falhas permanentes (sem repetição): {$resumo['falhas_permanentes']}");
        $this->line('  Encerrados por esgotar as '.NotificationDispatcher::MAXIMO_DE_TENTATIVAS." tentativas: {$resumo['desistidos']}");

        if ($resumo['disputados'] > 0) {
            $this->line("  Itens já assumidos por outra execução: {$resumo['disputados']}");
        }

        return $resumo['processados'];
    }

    /**
     * Lê `--limite`, recusando o que não for inteiro positivo.
     */
    private function resolverLimite(): ?int
    {
        $opcao = $this->option('limite');

        if ($opcao === null) {
            return 100;
        }

        if (! ctype_digit((string) $opcao) || (int) $opcao < 1) {
            $this->error("--limite precisa ser um número inteiro maior que zero. Valor recebido: \"{$opcao}\".");

            return null;
        }

        return (int) $opcao;
    }
}
