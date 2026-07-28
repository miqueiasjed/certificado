<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Services\InadimplenciaService;
use Illuminate\Console\Command;

/**
 * Rotina diária (Task 7.7) que aplica a régua de inadimplência: avisa antes do
 * vencimento, marca a fatura vencida, coloca a assinatura em atraso, cobra
 * dentro da tolerância e suspende quando ela estoura.
 *
 * É a rotina que pode bloquear o acesso de um cliente real, e por isso a
 * comparação de datas dela é sempre por dia no fuso do negócio, nunca por
 * instante em UTC: suspender às 21h de Brasília do dia do vencimento seria
 * bloqueio indevido.
 *
 * Também é, na prática, o único lugar do sistema que decide que uma fatura
 * venceu. O PagBank não avisa boleto vencido por webhook (ver
 * `PagBankGateway::eventoDePedido()`), então nada além desta passada faria a
 * transição `aberta -> vencida`.
 *
 * Tenant interno nunca é tocado por caminho nenhum: ele é excluído na consulta
 * do Service e barrado de novo em cada método público dele.
 *
 * Rodar duas vezes no mesmo dia não duplica efeito: fatura já vencida não é
 * remarcada, assinatura já em atraso não é reescrita, empresa já suspensa não é
 * suspensa de novo (o que preservaria `suspensa_em`) e a assinatura suspensa sai
 * da consulta na passada seguinte.
 *
 * O registro em `scheduled_task_runs` é automático para toda execução disparada
 * pelo `$schedule` do Laravel (gancho `RegistraExecucaoAgendada` em
 * `bootstrap/app.php`, aplicado a partir de `RotinasAgendadas::DIARIAS`).
 */
class AplicarReguaDeInadimplencia extends Command
{
    protected $signature = 'plataforma:inadimplencia';

    protected $description = 'Aplica a régua de inadimplência: avisa, marca em atraso e suspende quem passou da tolerância.';

    public function __construct(private readonly InadimplenciaService $servico)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info(sprintf(
            'Aplicando a régua de inadimplência (tolerância de %d dias)...',
            (int) config('assinatura.dias_de_tolerancia', 5)
        ));

        $itens = $this->servico->avaliar();

        $this->imprimirResultado($itens);

        $houveErro = collect($itens)->contains(fn (array $item): bool => $item['erro'] !== null);

        return $houveErro ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Uma linha por assinatura avaliada, com o total ao final.
     *
     * A coluna "Dias" é positiva quando é atraso e negativa quando o vencimento
     * ainda está por vir, que é o mesmo sinal usado no Service. A coluna "Ação"
     * traz o desfecho mais severo daquela assinatura; a suspensão aparece
     * destacada no rodapé, porque é o único efeito da rotina que o cliente
     * percebe na hora.
     *
     * @param  array<int, array{assinatura: Subscription, empresa: ?Company, fatura: ?Invoice, acao: string, dias: int, marcou_vencida: bool, erro: ?string}>  $itens
     */
    private function imprimirResultado(array $itens): void
    {
        if ($itens === []) {
            $this->newLine();
            $this->line('Nenhuma assinatura com fatura em aberto ou vencida. Nada a fazer.');

            return;
        }

        $linhas = [];
        $suspensos = 0;

        foreach ($itens as $item) {
            $empresa = $item['empresa'];
            $fatura = $item['fatura'];

            $linhas[] = [
                $empresa === null
                    ? "assinatura #{$item['assinatura']->id}"
                    : "#{$empresa->id} {$empresa->name}",
                $fatura?->referencia ?? '-',
                $fatura === null ? '-' : $fatura->vencimento->format('d/m/Y'),
                (string) $item['dias'],
                $item['erro'] === null ? $item['acao'] : 'erro',
                $item['marcou_vencida'] ? 'sim' : 'não',
                $item['erro'] ?? '',
            ];

            if ($item['acao'] === InadimplenciaService::ACAO_SUSPENSA && $item['erro'] === null) {
                $suspensos++;
            }
        }

        $this->newLine();
        $this->table(
            ['Empresa', 'Referência', 'Vencimento', 'Dias', 'Ação', 'Venceu agora', 'Erro'],
            $linhas
        );

        $this->line(sprintf(
            '%d assinatura(s) avaliada(s), %d suspensa(s) por inadimplência.',
            count($itens),
            $suspensos
        ));

        if ($suspensos > 0) {
            $this->warn(
                'Tenant suspenso mantém todos os dados intactos: é acesso negado, não exclusão. '
                .'A tela de faturas continua acessível, e a baixa do pagamento devolve o acesso na requisição seguinte.'
            );
        }

        $comErro = collect($itens)->filter(fn (array $item): bool => $item['erro'] !== null);

        if ($comErro->isNotEmpty()) {
            $this->newLine();
            $this->warn(
                "{$comErro->count()} assinatura(s) falharam na avaliação. O log da aplicação tem o erro completo de cada uma. "
                .'As demais foram avaliadas normalmente.'
            );
        }
    }
}
