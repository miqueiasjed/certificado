<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\OperaPorTenant;
use App\Models\Budget;
use App\Models\Certificate;
use App\Models\Company;
use App\Models\Contract;
use App\Models\NotificationQueue;
use App\Models\PaymentDetail;
use App\Models\WorkOrder;
use App\Services\NotificationService;
use App\Services\PendenciasDeContratoService;
use App\Support\BusinessDate;
use App\Support\EventosDeNotificacao;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rotina diária que enfileira os cinco avisos abaixo, que nascem da passagem
 * do tempo, e não de uma ação do usuário (Task 14.4):
 *
 * - lembrete na véspera da visita;
 * - certificado a vencer, em três marcos;
 * - pagamento vencido;
 * - orçamento perto de expirar sem resposta;
 * - visita periódica prevista e não gerada.
 *
 * "Contrato a vencer" morou aqui até a Task 23.5, com um único marco de
 * configuração global e sem filtrar `situacao_renovacao`. Um contrato já
 * renovado (Task 23.4 preserva o anterior na tabela, com `end_date` no
 * passado) voltava a gerar aviso para sempre quando `end_date` dele
 * coincidisse com o marco configurado (bug real de produção), corrigido ao
 * mudar o aviso para `App\Console\Commands\VerificarContratosAVencer`, que
 * também resolve o prazo configurável por tenant que esta rotina nunca teve
 * (ver o comentário histórico em `config/notificacoes.php`, chave removida
 * na mesma task). O evento do catálogo continua o mesmo
 * (`EventosDeNotificacao::CONTRATO_A_VENCER`); só o disparo mudou de dono.
 *
 * Roda uma passada por empresa, cada uma dentro do tenant dela
 * (`OperaPorTenant`): sem isso as consultas sairiam sem escopo e uma empresa
 * mandaria aviso sobre o cliente da outra, que é a falha mais grave possível
 * neste sistema. Erro em uma empresa não interrompe as demais, e erro em um
 * registro não interrompe os demais registros da mesma empresa: cada disparo
 * tem o próprio try/catch, porque um certificado com dado inconsistente não
 * pode calar o aviso de cobrança do cliente seguinte.
 *
 * Idempotência
 * ------------
 * Nada aqui varia por instante. Os cortes de data são dias no fuso do negócio,
 * e os marcos são números fixos (30, 15, 7) ou a data prevista da visita. É o
 * que permite rodar a rotina duas vezes no mesmo dia, ou reprocessar um dia que
 * falhou, sem o cliente receber o mesmo aviso de novo: a chave de idempotência
 * do `NotificationService` reconhece o aviso que já está na fila.
 *
 * Comparação por dia, sempre
 * --------------------------
 * Todo corte usa `BusinessDate::hoje()`, nunca `now()` nem `today()`. A rotina
 * roda de manhã em Brasília, mas a aplicação está em UTC: comparar em UTC faria
 * o aviso de vencimento sair um dia antes.
 */
class EnfileirarAvisosDiarios extends Command
{
    use OperaPorTenant;

    protected $signature = 'notificacoes:avisos-diarios';

    protected $description = 'Enfileira os avisos diários da central de notificações: véspera de visita, certificado a vencer, pagamento vencido, orçamento a expirar e visita periódica não gerada.';

    /**
     * Status de OS que ainda espera acontecer. Visita concluída, cancelada ou em
     * espera não recebe lembrete de véspera: nas duas primeiras o assunto já
     * está encerrado, e na terceira a data de amanhã não é compromisso firmado.
     *
     * @var array<int, string>
     */
    private const STATUS_QUE_ESPERAM_VISITA = ['pending', 'scheduled'];

    /**
     * Situação de orçamento que está com o cliente, esperando resposta.
     * Rascunho ainda não saiu, e aprovado, recusado ou convertido já teve
     * desfecho.
     *
     * @var array<int, string>
     */
    private const STATUS_DE_ORCAMENTO_SEM_RESPOSTA = ['sent', 'negotiating'];

    /**
     * Situações de parcela que não são cobrança em aberto.
     *
     * @var array<int, string>
     */
    private const STATUS_DE_PARCELA_ENCERRADA = ['paid', 'cancelled'];

    /**
     * O Service chega pelo `handle()`, e não pelo construtor, para não ser
     * resolvido em toda chamada de artisan do sistema. Fica em propriedade
     * porque os seis blocos de disparo o usam.
     */
    private ?NotificationService $notificacoes = null;

    private ?PendenciasDeContratoService $pendenciasDeContrato = null;

    /**
     * Contagem da empresa corrente, zerada a cada volta do laço por tenant.
     *
     * @var array<string, int>
     */
    private array $contagem = [];

    /**
     * Algum registro, em alguma empresa, terminou com erro.
     *
     * `paraCadaTenant()` só marca falha quando o callback lança, e aqui o erro
     * de um registro é capturado de propósito para não derrubar os demais. Sem
     * este sinal a rotina terminaria com código de sucesso enquanto avisos
     * deixaram de sair, e a verificação de rotinas (Task 14.5) não teria como
     * saber. Mesmo critério de `contratos:gerar-visitas`.
     */
    private bool $algumRegistroFalhou = false;

    public function handle(
        NotificationService $notificacoes,
        PendenciasDeContratoService $pendenciasDeContrato
    ): int {
        $this->notificacoes = $notificacoes;
        $this->pendenciasDeContrato = $pendenciasDeContrato;

        $hoje = BusinessDate::hoje();

        $this->info('Enfileirando os avisos diários da central de notificações...');
        $this->line('Dia de referência ('.BusinessDate::fuso().'): '.$hoje->toDateString());

        $codigo = $this->paraCadaTenant(fn (Company $empresa): int => $this->enfileirarDaEmpresa());

        return $codigo === Command::SUCCESS && $this->algumRegistroFalhou ? Command::FAILURE : $codigo;
    }

    /**
     * Uma empresa, já dentro do tenant dela.
     *
     * Toda consulta é montada e executada aqui dentro, e não antes do laço: o
     * escopo global por empresa é aplicado quando a consulta roda, então um
     * builder montado fora sairia com o filtro da volta errada.
     *
     * @return int quantidade de avisos novos enfileirados nesta empresa
     */
    private function enfileirarDaEmpresa(): int
    {
        $this->contagem = ['enfileirados' => 0, 'duplicados' => 0, 'sem_envio' => 0, 'erros' => 0];

        $this->lembretesDeVespera();
        $this->certificadosAVencer();
        $this->pagamentosVencidos();
        $this->orcamentosAExpirar();
        $this->visitasPeriodicasNaoGeradas();

        $this->line("  Avisos novos: {$this->contagem['enfileirados']}");
        $this->line("  Já estavam na fila: {$this->contagem['duplicados']}");
        $this->line('  Sem envio (recusa do cliente, falta de e-mail ou de template): '.$this->contagem['sem_envio']);
        $this->line("  Registros com erro: {$this->contagem['erros']}");

        return $this->contagem['enfileirados'];
    }

    // -----------------------------------------------------------------
    // Lembrete na véspera da visita
    // -----------------------------------------------------------------

    /**
     * Visita de amanhã, aviso hoje.
     *
     * O envio é agendado para hoje, no horário padrão da central, e não para
     * amanhã: o texto do lembrete diz "sua visita é amanhã", e entregá-lo no dia
     * da visita transformaria a mensagem em informação errada, além de chegar
     * tarde demais para o cliente deixar o local acessível. A rotina roda antes
     * do horário de disparo justamente para o item já estar na fila quando o
     * despachante passar.
     */
    private function lembretesDeVespera(): void
    {
        $amanha = BusinessDate::hoje()->addDay();

        $visitas = WorkOrder::query()
            ->whereDate('scheduled_date', $amanha->toDateString())
            ->whereIn('status', self::STATUS_QUE_ESPERAM_VISITA)
            ->with(['client', 'address', 'technician'])
            ->orderBy('id')
            ->get();

        $this->line('  Visitas agendadas para amanhã ('.$amanha->toDateString()."): {$visitas->count()}");

        foreach ($visitas as $visita) {
            $this->enfileirar(
                EventosDeNotificacao::LEMBRETE_VESPERA,
                $visita,
                ['agendada_para' => BusinessDate::hoje()->toDateString()],
                "ordem de serviço #{$visita->id}"
            );
        }
    }

    // -----------------------------------------------------------------
    // Certificado a vencer: cliente e empresa
    // -----------------------------------------------------------------

    /**
     * Um aviso por marco (30, 15 e 7 dias), para o cliente e para a empresa.
     *
     * O corte é a igualdade com o dia exato do marco, não um intervalo: com
     * intervalo, o certificado apareceria em todas as execuções entre 30 e 7
     * dias, e só a chave de idempotência impediria o segundo e-mail, o que
     * esconderia a intenção do código atrás de um efeito colateral.
     *
     * Certificado cancelado fica de fora: não há garantia a renovar.
     */
    private function certificadosAVencer(): void
    {
        $marcos = (array) config('notificacoes.marcos_certificado_a_vencer', [30, 15, 7]);

        foreach ($marcos as $dias) {
            $dias = (int) $dias;
            $vencimento = BusinessDate::hoje()->addDays($dias)->toDateString();

            $certificados = Certificate::query()
                ->whereDate('warranty', $vencimento)
                ->where('status', '!=', 'cancelled')
                ->with(['client', 'address'])
                ->orderBy('id')
                ->get();

            $this->line("  Certificados a {$dias} dia(s) do vencimento ({$vencimento}): {$certificados->count()}");

            foreach ($certificados as $certificado) {
                $rotulo = "certificado #{$certificado->id}";

                $this->enfileirar(
                    EventosDeNotificacao::CERTIFICADO_A_VENCER,
                    $certificado,
                    ['marco' => (string) $dias],
                    $rotulo
                );

                $this->enfileirar(
                    EventosDeNotificacao::CERTIFICADO_A_VENCER,
                    $certificado,
                    [
                        'marco' => (string) $dias,
                        'destinatario_tipo' => NotificationQueue::DESTINATARIO_EMPRESA,
                        'assunto' => 'Aviso interno: certificado {{certificado_numero}} vence em {{data_vencimento}}',
                        'corpo' => 'O certificado {{certificado_numero}}, do cliente {{cliente_nome}}, '
                            ."vence em {{data_vencimento}}, daqui a {{dias_para_vencer}} dias.\n"
                            ."Endereço: {{endereco}}.\n\n"
                            .'O cliente recebeu o aviso dele em separado. Agende a renovação antes do '
                            .'vencimento: certificado vencido deixa o local irregular perante a fiscalização.',
                    ],
                    $rotulo.' (cópia interna)'
                );
            }
        }
    }

    // -----------------------------------------------------------------
    // Pagamento vencido: cliente e empresa
    // -----------------------------------------------------------------

    /**
     * Parcela que venceu ontem e continua em aberto.
     *
     * Ontem, e não "qualquer parcela vencida": o aviso é o toque logo depois do
     * vencimento, uma vez por parcela. Varrer toda a inadimplência todo dia é
     * régua de cobrança, que é outro plano.
     *
     * Sem marco de propósito: a parcela só entra nesta consulta no dia seguinte
     * ao vencimento dela, então a chave sem marco já garante o aviso único.
     */
    private function pagamentosVencidos(): void
    {
        $ontem = BusinessDate::hoje()->subDay()->toDateString();

        $parcelas = PaymentDetail::query()
            ->whereDate('payment_due_date', $ontem)
            ->whereNull('payment_date')
            ->where('active', true)
            ->where(function ($consulta): void {
                $consulta->whereNull('payment_status')
                    ->orWhereNotIn('payment_status', self::STATUS_DE_PARCELA_ENCERRADA);
            })
            ->with('workOrder.client')
            ->orderBy('id')
            ->get();

        $this->line("  Parcelas vencidas ontem ({$ontem}) e em aberto: {$parcelas->count()}");

        foreach ($parcelas as $parcela) {
            $rotulo = "parcela #{$parcela->id}";

            $this->enfileirar(EventosDeNotificacao::PAGAMENTO_VENCIDO, $parcela, [], $rotulo);

            $this->enfileirar(
                EventosDeNotificacao::PAGAMENTO_VENCIDO,
                $parcela,
                [
                    'destinatario_tipo' => NotificationQueue::DESTINATARIO_EMPRESA,
                    'assunto' => 'Aviso interno: pagamento de {{cliente_nome}} vencido em {{data_vencimento}}',
                    'corpo' => 'O pagamento de {{valor}}, do cliente {{cliente_nome}}, referente à ordem de '
                        ."serviço {{os_numero}}, venceu em {{data_vencimento}} e continua em aberto.\n\n"
                        .'O cliente recebeu o aviso dele em separado. Confira se o recebimento não foi apenas '
                        .'lançado com atraso no sistema antes de cobrar.',
                ],
                $rotulo.' (cópia interna)'
            );
        }
    }

    // -----------------------------------------------------------------
    // Orçamento a expirar sem resposta
    // -----------------------------------------------------------------

    /**
     * Orçamento que está com o cliente e expira em poucos dias.
     *
     * `cliente_nome` é informado aqui porque o orçamento pode ser de um
     * interessado que ainda não virou cliente (`client_id` nulo, nome em
     * `prospect_name`), e nesse caso o aviso sairia sem dizer de quem é.
     */
    private function orcamentosAExpirar(): void
    {
        $dias = (int) config('notificacoes.dias_aviso_orcamento_a_expirar', 3);
        $validade = BusinessDate::hoje()->addDays($dias)->toDateString();

        $orcamentos = Budget::query()
            ->whereIn('status', self::STATUS_DE_ORCAMENTO_SEM_RESPOSTA)
            ->whereDate('validity_date', $validade)
            ->with('client')
            ->orderBy('id')
            ->get();

        $this->line("  Orçamentos sem resposta a {$dias} dia(s) da validade ({$validade}): {$orcamentos->count()}");

        foreach ($orcamentos as $orcamento) {
            $this->enfileirar(
                EventosDeNotificacao::ORCAMENTO_A_EXPIRAR,
                $orcamento,
                [
                    'marco' => (string) $dias,
                    'variaveis' => [
                        'cliente_nome' => $orcamento->client?->name ?? $orcamento->prospect_name,
                    ],
                ],
                "orçamento #{$orcamento->id}"
            );
        }
    }

    // -----------------------------------------------------------------
    // Visita periódica prevista e não gerada
    // -----------------------------------------------------------------

    /**
     * Pendência de conformidade do Plano 9, avisada por e-mail.
     *
     * A detecção vem inteira de `PendenciasDeContratoService::listar()`, a mesma
     * fonte do painel de pendências: recalcular o que é uma visita devida aqui
     * criaria uma segunda regra, e no dia em que as duas divergissem a empresa
     * teria um painel dizendo uma coisa e um e-mail dizendo outra.
     *
     * Um aviso por contrato, sobre a data prevista mais recente sem OS. O painel
     * é quem mostra a lista inteira; o e-mail existe para avisar que ela cresceu.
     * Um contrato antigo pode acumular dezenas de datas descobertas, e mandar um
     * e-mail por data entupiria a caixa de entrada da empresa na primeira
     * execução, que é a forma mais rápida de o alerta parar de ser lido. Como o
     * marco é a data, cada novo período que passa descoberto gera um aviso novo,
     * e nenhum é repetido.
     */
    private function visitasPeriodicasNaoGeradas(): void
    {
        try {
            $pendencias = $this->pendenciasDeContrato->listar();
        } catch (Throwable $erro) {
            // O cálculo das pendências percorre o calendário de todos os
            // contratos periódicos de uma vez. Um contrato com periodicidade
            // impossível derrubaria os outros cinco blocos desta empresa se o
            // erro subisse até `OperaPorTenant`.
            $this->registrarErro($erro, EventosDeNotificacao::VISITA_PERIODICA_NAO_GERADA, 'painel de pendências de contrato');

            return;
        }

        $comDataDescoberta = array_values(array_filter(
            $pendencias,
            static fn (array $linha): bool => $linha['datas_sem_visita'] !== []
        ));

        $this->line('  Contratos periódicos com visita prevista e não gerada: '.count($comDataDescoberta));

        foreach ($comDataDescoberta as $linha) {
            $rotulo = "contrato #{$linha['contrato_id']}";

            try {
                $contrato = Contract::query()->find($linha['contrato_id']);

                if ($contrato === null) {
                    continue;
                }

                $data = $this->dataMaisRecente($linha['datas_sem_visita']);

                $this->enfileirar(
                    EventosDeNotificacao::VISITA_PERIODICA_NAO_GERADA,
                    $contrato,
                    [
                        'marco' => $data,
                        'variaveis' => ['data_prevista' => $data],
                    ],
                    $rotulo
                );
            } catch (Throwable $erro) {
                $this->registrarErro($erro, EventosDeNotificacao::VISITA_PERIODICA_NAO_GERADA, $rotulo);
            }
        }
    }

    /**
     * Data prevista mais recente da lista de pendências do contrato.
     *
     * As datas saem do Service em formato 'Y-m-d', que ordena corretamente como
     * texto, sem precisar instanciar Carbon para comparar dia com dia.
     *
     * @param  array<int, array{data: string, data_br: string, numero: int}>  $datas
     */
    private function dataMaisRecente(array $datas): string
    {
        return max(array_column($datas, 'data'));
    }

    // -----------------------------------------------------------------
    // Disparo individual
    // -----------------------------------------------------------------

    /**
     * Um disparo, com o erro isolado no registro que o causou.
     *
     * Sem este try/catch, um certificado com endereço apagado derrubaria a
     * empresa inteira em `OperaPorTenant` e os demais avisos do dia não sairiam.
     * O erro é contado, impresso e registrado em log, e o laço segue.
     *
     * @param  array<string, mixed>  $opcoes
     */
    private function enfileirar(string $evento, Model $referencia, array $opcoes, string $rotulo): void
    {
        try {
            $resultado = $this->notificacoes->enfileirar($evento, $referencia, $opcoes);

            match ($resultado['resultado']) {
                NotificationService::RESULTADO_ENFILEIRADA => $this->contagem['enfileirados']++,
                NotificationService::RESULTADO_DUPLICADA => $this->contagem['duplicados']++,
                default => $this->contagem['sem_envio']++,
            };
        } catch (Throwable $erro) {
            $this->registrarErro($erro, $evento, $rotulo);
        }
    }

    private function registrarErro(Throwable $erro, string $evento, string $rotulo): void
    {
        $this->contagem['erros']++;
        $this->algumRegistroFalhou = true;

        report($erro);

        Log::error('Falha ao enfileirar aviso diário.', [
            'evento' => $evento,
            'registro' => $rotulo,
            'mensagem' => $erro->getMessage(),
        ]);

        $this->error("  Falha no {$rotulo} ({$evento}): {$erro->getMessage()}");
        $this->line('  Os demais registros continuam sendo processados.');
    }
}
