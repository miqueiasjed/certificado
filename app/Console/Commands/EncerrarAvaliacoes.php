<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\AvaliacaoService;
use Illuminate\Console\Command;

/**
 * Rotina diária (Task 8.6) que aplica a régua de avaliação: avisa quem está
 * perto do fim do prazo e suspende quem passou dele sem assinar.
 *
 * Mesmo espírito de `AplicarReguaDeInadimplencia` (Task 7.7): pode bloquear o
 * acesso de um tenant real, e por isso a comparação de datas dela é sempre por
 * dia no fuso do negócio, nunca por instante em UTC. Suspender às 21h de
 * Brasília do último dia do prazo tiraria um dia de quem ainda está decidindo
 * comprar.
 *
 * Tenant interno nunca é tocado: ele é excluído na consulta do Service e
 * barrado de novo em cada método público dele.
 *
 * Rodar duas vezes no mesmo dia não duplica efeito: empresa já suspensa sai
 * da consulta na passada seguinte, porque `situacao` deixou de ser
 * `em_avaliacao`.
 *
 * O registro em `scheduled_task_runs` é automático para toda execução
 * disparada pelo `$schedule` do Laravel (gancho `RegistraExecucaoAgendada` em
 * `bootstrap/app.php`, aplicado a partir de `RotinasAgendadas::DIARIAS`).
 */
class EncerrarAvaliacoes extends Command
{
    protected $signature = 'plataforma:encerrar-avaliacoes';

    protected $description = 'Avisa quem está perto do fim da avaliação e suspende quem passou do prazo sem assinar.';

    public function __construct(private readonly AvaliacaoService $servico)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Aplicando a régua de avaliação...');

        $itens = $this->servico->avaliarVencimentos();

        $this->imprimirResultado($itens);

        $houveErro = collect($itens)->contains(fn (array $item): bool => $item['erro'] !== null);

        return $houveErro ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Uma linha por empresa avaliada, com o total ao final.
     *
     * A coluna "Dias" é positiva quando ainda falta para o fim da avaliação e
     * negativa quando já passou, mesmo sinal de
     * `AvaliacaoService::diasRestantes()`.
     *
     * @param  array<int, array{empresa: Company, acao: string, dias: int, erro: ?string}>  $itens
     */
    private function imprimirResultado(array $itens): void
    {
        if ($itens === []) {
            $this->newLine();
            $this->line('Nenhuma empresa em avaliação. Nada a fazer.');

            return;
        }

        $linhas = [];
        $suspensas = 0;

        foreach ($itens as $item) {
            $empresa = $item['empresa'];

            $linhas[] = [
                "#{$empresa->id} {$empresa->name}",
                $empresa->trial_ends_at?->format('d/m/Y') ?? '-',
                (string) $item['dias'],
                $item['erro'] === null ? $item['acao'] : 'erro',
                $item['erro'] ?? '',
            ];

            if ($item['acao'] === AvaliacaoService::ACAO_SUSPENSA && $item['erro'] === null) {
                $suspensas++;
            }
        }

        $this->newLine();
        $this->table(
            ['Empresa', 'Fim da avaliação', 'Dias', 'Ação', 'Erro'],
            $linhas
        );

        $this->line(sprintf(
            '%d empresa(s) avaliada(s), %d suspensa(s) por fim de avaliação sem assinatura.',
            count($itens),
            $suspensas
        ));

        if ($suspensas > 0) {
            $this->warn(
                'Tenant suspenso mantém todos os dados intactos: é acesso negado, não exclusão. '
                .'A conversão para plano pago devolve o acesso na hora.'
            );
        }

        $comErro = collect($itens)->filter(fn (array $item): bool => $item['erro'] !== null);

        if ($comErro->isNotEmpty()) {
            $this->newLine();
            $this->warn(
                "{$comErro->count()} empresa(s) falharam na avaliação. O log da aplicação tem o erro completo de cada uma. "
                .'As demais foram avaliadas normalmente.'
            );
        }
    }
}
