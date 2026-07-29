<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\OperaPorTenant;
use App\Models\Company;
use App\Models\WorkOrder;
use App\Services\SatisfactionSurveyService;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rotina diária que envia a pesquisa de satisfação das visitas concluídas **no
 * dia anterior** (Plano 16, Task 16.5).
 *
 * Nunca no mesmo dia da conclusão: pesquisa que chega com o técnico ainda saindo
 * do local pega o cliente antes de ele ter visto o resultado do serviço, e o que
 * ele responde nesse momento é sobre o atendimento, não sobre o controle de
 * pragas.
 *
 * Dia de ontem no fuso do negócio
 * -------------------------------
 * `end_time` é instante gravado em UTC. Uma visita encerrada às 22h30 de Brasília
 * já é do dia seguinte em UTC, então comparar a coluna com `whereDate` em UTC
 * deixaria essa visita de fora hoje e a incluiria amanhã, quando ela já não é de
 * ontem. Por isso o corte converte o começo e o fim do dia de ontem, no fuso do
 * negócio, para o fuso da aplicação, e compara instante contra instante.
 * Visita concluída sem `end_time` registrado cai no `scheduled_date`, que é campo
 * de dia e não sofre conversão nenhuma.
 *
 * Uma passada por empresa
 * -----------------------
 * `OperaPorTenant`: sem isso a consulta sairia sem escopo e uma empresa mandaria
 * pesquisa sobre o cliente da outra, que é a falha mais grave possível aqui. Erro
 * em uma empresa não interrompe as demais, e erro em uma visita não interrompe as
 * demais visitas da mesma empresa.
 *
 * Idempotência
 * ------------
 * Nada aqui varia por instante: o corte é o dia de ontem no fuso do negócio, e
 * `satisfaction_surveys.work_order_id` é unique. Rodar duas vezes no mesmo dia,
 * ou reprocessar um dia que falhou, não gera pesquisa repetida nem segundo
 * convite ao cliente (a chave de idempotência da central de notificações
 * reconhece o convite que já está na fila).
 *
 * Quem decide se a pesquisa nasce é o Service
 * -------------------------------------------
 * Cliente que recusou o canal, cliente que já teve pesquisa nos últimos 30 dias e
 * visita sem cliente com contato são regras de negócio, e vivem em
 * `SatisfactionSurveyService::criarParaVisita()`. Este comando só escolhe as
 * visitas do dia e conta o resultado de cada uma.
 *
 * Chave de liga e desliga
 * -----------------------
 * `config('notificacoes.pesquisa_satisfacao_ativa')` nasce desligada, por
 * exigência da ordem de aplicação em produção do plano: o deploy desta task sobe
 * com o envio desligado, e a empresa confere antes de ligar. Desligada, a rotina
 * termina com sucesso sem criar nada, e continua aparecendo em
 * `scheduled_task_runs` como qualquer outra passada, para a verificação de rotina
 * parada (Task 14.5) não acusar falha. Ver o motivo completo em
 * `config/notificacoes.php`.
 */
class EnviarPesquisasDeSatisfacao extends Command
{
    use OperaPorTenant;

    protected $signature = 'pesquisas:enviar';

    protected $description = 'Envia a pesquisa de satisfação das ordens de serviço concluídas no dia anterior.';

    private ?SatisfactionSurveyService $pesquisas = null;

    /**
     * Contagem da empresa corrente, zerada a cada volta do laço por tenant.
     *
     * @var array<string, int>
     */
    private array $contagem = [];

    /**
     * Alguma visita, em alguma empresa, terminou com erro.
     *
     * `paraCadaTenant()` só marca falha quando o callback lança, e aqui o erro de
     * uma visita é capturado de propósito para não derrubar as demais. Sem este
     * sinal a rotina terminaria com código de sucesso enquanto pesquisas deixaram
     * de sair, e a verificação de rotinas (Task 14.5) não teria como saber. Mesmo
     * critério de `notificacoes:avisos-diarios`.
     */
    private bool $algumaVisitaFalhou = false;

    public function handle(SatisfactionSurveyService $pesquisas): int
    {
        $this->pesquisas = $pesquisas;

        $ontem = BusinessDate::hoje()->subDay();

        $this->info('Enviando as pesquisas de satisfação das visitas concluídas ontem...');
        $this->line('Dia de referência ('.BusinessDate::fuso().'): '.$ontem->toDateString());

        if (! (bool) config('notificacoes.pesquisa_satisfacao_ativa', false)) {
            $this->warn('O envio automático da pesquisa está desligado. Nada foi criado nem enviado.');
            $this->line('Para ligar, defina NOTIFICACOES_PESQUISA_SATISFACAO_ATIVA=true no ambiente.');

            return Command::SUCCESS;
        }

        $codigo = $this->paraCadaTenant(fn (Company $empresa): int => $this->enviarDaEmpresa($ontem));

        return $codigo === Command::SUCCESS && $this->algumaVisitaFalhou ? Command::FAILURE : $codigo;
    }

    /**
     * Uma empresa, já dentro do tenant dela.
     *
     * A consulta é montada e executada aqui dentro, e não antes do laço: o escopo
     * global por empresa é aplicado quando a consulta roda, então um builder
     * montado fora sairia com o filtro da volta errada.
     *
     * @return int quantidade de pesquisas criadas nesta empresa
     */
    private function enviarDaEmpresa(CarbonImmutable $ontem): int
    {
        $this->contagem = [
            'criadas' => 0,
            'ja_existiam' => 0,
            'sem_contato' => 0,
            'canal_recusado' => 0,
            'pesquisa_recente' => 0,
            'sem_envio' => 0,
            'erros' => 0,
        ];

        $visitas = $this->visitasConcluidasEm($ontem);

        $this->line('  Visitas concluídas em '.$ontem->toDateString().": {$visitas->count()}");

        foreach ($visitas as $visita) {
            $this->criarPesquisa($visita);
        }

        $this->line("  Pesquisas novas: {$this->contagem['criadas']}");
        $this->line("  Visitas que já tinham pesquisa: {$this->contagem['ja_existiam']}");
        $this->line("  Sem cliente com contato: {$this->contagem['sem_contato']}");
        $this->line("  Cliente com o canal desligado: {$this->contagem['canal_recusado']}");
        $this->line('  Cliente com pesquisa nos últimos '
            .SatisfactionSurveyService::DIAS_ENTRE_PESQUISAS." dias: {$this->contagem['pesquisa_recente']}");
        $this->line("  Convite não enfileirado: {$this->contagem['sem_envio']}");
        $this->line("  Visitas com erro: {$this->contagem['erros']}");

        return $this->contagem['criadas'];
    }

    /**
     * Visitas concluídas no dia informado, no fuso do negócio.
     *
     * `active` entra no filtro porque ordem desativada é registro que a empresa
     * tirou de circulação, e pedir avaliação de um atendimento que ela desfez
     * seria constrangedor com o cliente.
     *
     * @return Collection<int, WorkOrder>
     */
    private function visitasConcluidasEm(CarbonImmutable $dia): Collection
    {
        $fusoDaAplicacao = config('app.timezone') ?: 'UTC';
        $inicio = $dia->startOfDay()->setTimezone($fusoDaAplicacao);
        $fim = $dia->endOfDay()->setTimezone($fusoDaAplicacao);

        return WorkOrder::query()
            ->where('status', SatisfactionSurveyService::STATUS_DE_OS_CONCLUIDA)
            ->where('active', true)
            ->where(function ($consulta) use ($inicio, $fim, $dia): void {
                $consulta->whereBetween('end_time', [$inicio, $fim])
                    ->orWhere(function ($semRegistro) use ($dia): void {
                        $semRegistro->whereNull('end_time')
                            ->whereDate('scheduled_date', $dia->toDateString());
                    });
            })
            ->with(['client', 'technician'])
            ->orderBy('id')
            ->get();
    }

    /**
     * Uma visita, com o erro isolado nela.
     *
     * Sem este try/catch, uma visita com dado inconsistente derrubaria a empresa
     * inteira em `OperaPorTenant` e as pesquisas das outras visitas do dia não
     * sairiam.
     */
    private function criarPesquisa(WorkOrder $visita): void
    {
        $rotulo = "ordem de serviço #{$visita->id}";

        try {
            $resultado = $this->pesquisas->criarParaVisita($visita);

            match ($resultado['resultado']) {
                SatisfactionSurveyService::RESULTADO_CRIADA => $this->contagem['criadas']++,
                SatisfactionSurveyService::RESULTADO_JA_EXISTIA => $this->contagem['ja_existiam']++,
                SatisfactionSurveyService::RESULTADO_SEM_CONTATO => $this->contagem['sem_contato']++,
                SatisfactionSurveyService::RESULTADO_CANAL_RECUSADO => $this->contagem['canal_recusado']++,
                SatisfactionSurveyService::RESULTADO_PESQUISA_RECENTE => $this->contagem['pesquisa_recente']++,
                default => $this->contagem['sem_envio']++,
            };
        } catch (Throwable $erro) {
            $this->contagem['erros']++;
            $this->algumaVisitaFalhou = true;

            report($erro);

            Log::error('Falha ao criar a pesquisa de satisfação da visita.', [
                'work_order_id' => $visita->id,
                'mensagem' => $erro->getMessage(),
            ]);

            $this->error("  Falha na {$rotulo}: {$erro->getMessage()}");
            $this->line('  As demais visitas continuam sendo processadas.');
        }
    }
}
