<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\OperaPorTenant;
use App\Models\Company;
use App\Models\Contract;
use App\Services\ContractAlertService;
use App\Services\ContractRenewalService;
use App\Services\NotificationService;
use App\Support\BusinessDate;
use App\Support\EventosDeNotificacao;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rotina diária que avisa a empresa antes de o contrato vencer e não deixa
 * contrato vencido sem tratativa desaparecer sem decisão registrada (Plano
 * 23, Task 23.5).
 *
 * Dois avisos, duas cadências de repetição
 * -----------------------------------------
 * - `contrato_a_vencer` (contrato ainda vigente): um aviso por contrato e por
 *   marco de antecedência (`ContractAlertService::marcos()`, 60/30/15 dias por
 *   padrão, configurável por tenant em `CompanyContractAlertSetting`). O
 *   marco é a chave de idempotência, e o corte é a igualdade com o dia exato
 *   do marco, nunca um intervalo, mesmo critério de
 *   `EnfileirarAvisosDiarios::certificadosAVencer()`.
 * - `contrato_a_vencer` (contrato já vencido, sem tratativa): reenvia
 *   semanalmente até alguém decidir (`renovar()` ou
 *   `registrarNaoRenovacao()`). É a exceção de "um aviso por marco" do plano,
 *   mesmo critério do lote vencido de `VerificarEstoque`: contrato que vence
 *   e some do radar é perda de receita recorrente silenciosa, e por isso o
 *   silêncio depois do primeiro aviso não é aceitável aqui. `em_negociacao`
 *   pausa este reenvio por 30 dias corridos a partir de quando foi marcado
 *   (`marcarPendenteSeguraNegociacao()`/`pausadoPorNegociacao()`); passados os
 *   30 dias sem decisão final, o reenvio volta sozinho, porque negociação que
 *   não termina em decisão vira esquecimento.
 *
 * Os dois avisos reaproveitam o mesmo evento do catálogo
 * (`EventosDeNotificacao::CONTRATO_A_VENCER`), mesmo critério de
 * `LOTE_PROXIMO_DO_VENCIMENTO`: o catálogo não conhece a diferença entre
 * "próximo do vencimento" e "vencido", só o texto (que usa
 * `dias_para_vencer`, negativo quando já venceu) e o marco, decidido por esta
 * rotina.
 *
 * `pendente`: quem atribui é esta rotina
 * ---------------------------------------
 * Contrato nasce com `situacao_renovacao` nulo. No primeiro aviso desta
 * rotina (marco mais distante ainda vigente, ou o primeiro aviso do vencido
 * sem tratativa), `ContractRenewalService::marcarPendente()` grava `pendente`
 * quando o contrato ainda está nulo, sem efeito quando já existe alguma
 * situação, para nunca sobrescrever uma decisão ou negociação em andamento.
 * Ver o docblock da migration `add_renovacao_to_contracts_table`.
 *
 * Substitui `EnfileirarAvisosDiarios::contratosAVencer()`
 * ---------------------------------------------------------
 * Aquele método (Task 14.4) enfileirava um único marco fixo, de configuração
 * global (`config('notificacoes.dias_aviso_contrato_vencer')`), sem filtrar
 * `situacao_renovacao`: um contrato já renovado (Task 23.4 preserva o
 * anterior na tabela, com `end_date` no passado) voltaria a gerar aviso para
 * sempre, se o `end_date` dele coincidisse com o marco configurado. Esta
 * rotina corrige os dois problemas (prazo por tenant, filtro de situação
 * encerrada) e por isso substitui aquele método inteiramente, em vez de
 * conviver com ele.
 *
 * Isolamento por tenant e por registro
 * -------------------------------------
 * Mesmo padrão de `VerificarEstoque`/`EnfileirarAvisosDiarios`: laço por
 * empresa (`OperaPorTenant`) e cada contrato com o próprio try/catch, para
 * que um contrato com dado inconsistente não cale o aviso dos demais nem
 * derrube a empresa seguinte.
 *
 * Comparação de data sempre pelo fuso do negócio (`BusinessDate`), nunca
 * `now()`/`Carbon::now()` puro.
 */
class VerificarContratosAVencer extends Command
{
    use OperaPorTenant;

    /**
     * Dias corridos que a marcação `em_negociacao` pausa o aviso semanal do
     * contrato vencido sem tratativa. Contados a partir de
     * `contracts.em_negociacao_em` (Task 23.5), carimbado automaticamente por
     * `App\Models\Contract::booted()` quando a situação entra em
     * `em_negociacao`.
     */
    private const DIAS_DE_PAUSA_EM_NEGOCIACAO = 30;

    protected $signature = 'contratos:verificar-vencimento';

    protected $description = 'Enfileira o aviso de contrato a vencer (marcos configuráveis por tenant) e o aviso semanal de contrato vencido sem tratativa (Plano 23).';

    /**
     * As dependências chegam pelo `handle()`, e não pelo construtor, para não
     * serem resolvidas em toda chamada de artisan do sistema. Ficam em
     * propriedade porque os blocos de disparo as usam.
     */
    private ?NotificationService $notificacoes = null;

    private ?ContractAlertService $alertas = null;

    private ?ContractRenewalService $renovacao = null;

    /**
     * Contagem da empresa corrente, zerada a cada volta do laço por tenant.
     *
     * @var array<string, int>
     */
    private array $contagem = [];

    /**
     * Algum registro, em alguma empresa, terminou com erro. Mesmo critério de
     * `VerificarEstoque::$algumRegistroFalhou`: `OperaPorTenant` só marca
     * falha quando o callback inteiro lança, e aqui cada contrato tem o
     * próprio try/catch para não calar os demais.
     */
    private bool $algumRegistroFalhou = false;

    public function handle(
        NotificationService $notificacoes,
        ContractAlertService $alertas,
        ContractRenewalService $renovacao
    ): int {
        $this->notificacoes = $notificacoes;
        $this->alertas = $alertas;
        $this->renovacao = $renovacao;

        $this->info('Verificando contratos a vencer e pendências de renovação...');

        $codigo = $this->paraCadaTenant(fn (Company $empresa): int => $this->verificarDaEmpresa());

        return $codigo === Command::SUCCESS && $this->algumRegistroFalhou ? Command::FAILURE : $codigo;
    }

    /**
     * Uma empresa, já dentro do tenant dela.
     *
     * @return int quantidade de avisos novos enfileirados nesta empresa
     */
    private function verificarDaEmpresa(): int
    {
        $this->contagem = ['enfileirados' => 0, 'duplicados' => 0, 'sem_envio' => 0, 'pausados' => 0, 'erros' => 0];

        $this->contratosAVencer();
        $this->contratosVencidosSemTratativa();

        $this->line("  Avisos novos: {$this->contagem['enfileirados']}");
        $this->line("  Já estavam na fila: {$this->contagem['duplicados']}");
        $this->line('  Sem envio (recusa do cliente, falta de e-mail ou de template): '.$this->contagem['sem_envio']);
        $this->line("  Pausados por negociação em andamento: {$this->contagem['pausados']}");
        $this->line("  Registros com erro: {$this->contagem['erros']}");

        return $this->contagem['enfileirados'];
    }

    // -----------------------------------------------------------------
    // Contrato ainda vigente, a um dos marcos configurados do fim
    // -----------------------------------------------------------------

    private function contratosAVencer(): void
    {
        $marcos = $this->alertas->marcos();

        foreach ($marcos as $dias) {
            $contratos = $this->alertas->aVencer($dias);

            $this->line("  Contratos a {$dias} dia(s) do fim da vigência: {$contratos->count()}");

            foreach ($contratos as $contrato) {
                $this->processarAlerta($contrato, (string) $dias, "contrato #{$contrato->id}");
            }
        }
    }

    // -----------------------------------------------------------------
    // Contrato vencido sem tratativa: reenvio semanal, pausado por negociação
    // -----------------------------------------------------------------

    private function contratosVencidosSemTratativa(): void
    {
        $vencidos = $this->alertas->vencidosSemTratativa();

        $this->line("  Contratos vencidos sem tratativa (aviso semanal até decisão): {$vencidos->count()}");

        foreach ($vencidos as $contrato) {
            $rotulo = "contrato #{$contrato->id} (vencido sem tratativa)";

            try {
                $this->renovacao->marcarPendente($contrato);
            } catch (Throwable $erro) {
                $this->registrarErro($erro, $rotulo);

                continue;
            }

            if ($this->pausadoPorNegociacao($contrato)) {
                $this->contagem['pausados']++;

                continue;
            }

            $this->enfileirar($contrato, $this->marcoSemanal(), $rotulo);
        }
    }

    /**
     * O contrato está em negociação e ainda dentro da janela de 30 dias
     * corridos desde que foi marcado? Enquanto durar, o reenvio semanal fica
     * pausado; passados os 30 dias sem decisão final, esta função volta a
     * devolver `false` e o reenvio recomeça sozinho.
     *
     * Contrato sem `em_negociacao_em` (não deveria acontecer, dado o
     * `booted()` de `Contract`, mas dado importado ou corrigido na mão pode
     * não ter passado por lá) nunca é tratado como pausado: sem saber quando
     * a negociação começou, não há como calcular quando ela termina, e o
     * comportamento seguro aqui é continuar avisando.
     */
    private function pausadoPorNegociacao(Contract $contrato): bool
    {
        if ($contrato->situacao_renovacao !== 'em_negociacao' || $contrato->em_negociacao_em === null) {
            return false;
        }

        $marcadoEm = BusinessDate::paraFusoNegocio($contrato->em_negociacao_em)?->startOfDay();

        if ($marcadoEm === null) {
            return false;
        }

        $diasEmNegociacao = (int) $marcadoEm->diffInDays(BusinessDate::hoje());

        return $diasEmNegociacao < self::DIAS_DE_PAUSA_EM_NEGOCIACAO;
    }

    /**
     * Marco semanal do reenvio do contrato vencido sem tratativa: muda a
     * cada sete dias corridos, contados no fuso do negócio a partir de uma
     * época fixa, e não a cada virada de semana do calendário. Um contrato
     * descoberto vencido numa sexta-feira reenvia sete dias depois, e não já
     * na segunda seguinte. Cópia literal de
     * `VerificarEstoque::marcoSemanal()`, mesmo raciocínio.
     */
    private function marcoSemanal(): string
    {
        $epoca = CarbonImmutable::createFromDate(1970, 1, 1, BusinessDate::fuso())->startOfDay();
        $diasDesdeAEpoca = (int) $epoca->diffInDays(BusinessDate::hoje());

        return 'semana-'.intdiv($diasDesdeAEpoca, 7);
    }

    // -----------------------------------------------------------------
    // Disparo individual
    // -----------------------------------------------------------------

    /**
     * Um alerta de contrato ainda vigente: marca `pendente` na primeira vez
     * (sem efeito se já houver situação) e enfileira o aviso do marco.
     */
    private function processarAlerta(Contract $contrato, string $marco, string $rotulo): void
    {
        try {
            $this->renovacao->marcarPendente($contrato);
        } catch (Throwable $erro) {
            $this->registrarErro($erro, $rotulo);

            return;
        }

        $this->enfileirar($contrato, $marco, $rotulo);
    }

    /**
     * Um disparo, com o erro isolado no registro que o causou.
     *
     * Sem este try/catch, um contrato com endereço apagado derrubaria a
     * empresa inteira em `OperaPorTenant` e os demais avisos do dia não
     * sairiam. O erro é contado, impresso e registrado em log, e o laço
     * segue. Mesmo critério de `VerificarEstoque::enfileirar()`.
     */
    private function enfileirar(Contract $contrato, string $marco, string $rotulo): void
    {
        try {
            $resultado = $this->notificacoes->enfileirar(
                EventosDeNotificacao::CONTRATO_A_VENCER,
                $contrato,
                [
                    'marco' => $marco,
                    'variaveis' => [
                        'valor' => $this->valorEmReais($contrato->service_value),
                        'historico_renovacoes' => $this->historicoDeRenovacoes($contrato),
                    ],
                ]
            );

            match ($resultado['resultado']) {
                NotificationService::RESULTADO_ENFILEIRADA => $this->contagem['enfileirados']++,
                NotificationService::RESULTADO_DUPLICADA => $this->contagem['duplicados']++,
                default => $this->contagem['sem_envio']++,
            };
        } catch (Throwable $erro) {
            $this->registrarErro($erro, $rotulo);
        }
    }

    private function registrarErro(Throwable $erro, string $rotulo): void
    {
        $this->contagem['erros']++;
        $this->algumRegistroFalhou = true;

        report($erro);

        Log::error('Falha ao enfileirar aviso de contrato a vencer.', [
            'evento' => EventosDeNotificacao::CONTRATO_A_VENCER,
            'registro' => $rotulo,
            'mensagem' => $erro->getMessage(),
        ]);

        $this->error("  Falha no {$rotulo}: {$erro->getMessage()}");
        $this->line('  Os demais registros continuam sendo processados.');
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * Quantas vezes este contrato já foi renovado antes, navegando a cadeia
     * de `contrato_anterior_id` para trás. Contexto que ajuda o responsável a
     * decidir a abordagem: cliente que já renovou várias vezes é relação
     * consolidada, contrato que nunca renovou pede atenção maior na
     * conversa.
     */
    private function historicoDeRenovacoes(Contract $contrato): string
    {
        $quantidade = 0;
        $atual = $contrato;

        while ($atual !== null && $atual->contrato_anterior_id !== null) {
            $quantidade++;
            $atual = $atual->contratoAnterior;
        }

        return $quantidade === 0
            ? 'Nunca renovado antes: primeiro contrato deste endereço.'
            : sprintf('Já renovado %d vez(es) antes deste.', $quantidade);
    }

    /**
     * Valor do contrato em reais, no formato brasileiro. Sem valor definido,
     * o texto diz isso em vez de sair "R$ 0,00", que seria informação errada.
     */
    private function valorEmReais(mixed $valor): string
    {
        if ($valor === null || $valor === '') {
            return 'não informado';
        }

        return 'R$ '.number_format((float) $valor, 2, ',', '.');
    }
}
