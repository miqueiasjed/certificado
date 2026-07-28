<?php

namespace App\Console\Commands;

use App\Contracts\GatewayAssinatura;
use App\Models\GatewayEvent;
use App\Services\Gateway\PagBankGateway;
use App\Services\GatewayEventService;
use App\Support\Gateway\EventoDeGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

/**
 * Reprocessa evento de webhook que falhou, a partir do payload já gravado
 * (Plano 7, Task 7.6).
 *
 * O provedor manda cada evento uma vez. Quando o processamento falha, o webhook
 * responde 200 mesmo assim, de propósito (ver `GatewayWebhookController`), e o
 * que sobra é a linha em `gateway_events` com o payload inteiro e a mensagem do
 * erro. Este comando é o que transforma essa linha em segunda chance depois de a
 * causa ter sido corrigida: fatura conciliada, bug de interpretação consertado,
 * deploy que faltava.
 *
 * Nenhuma requisição ao provedor acontece aqui. O payload cru é reinterpretado
 * pela implementação de `GatewayAssinatura`, e o resultado vai para o mesmo
 * `GatewayEventService::processar()` que o webhook usa. Reprocessar é seguro
 * pelo mesmo motivo que um reenvio do provedor é: o efeito de domínio de cada
 * tipo tem saída antecipada quando já aconteceu.
 *
 * Manual, não agendado. Reprocessar sozinho um evento que falha sempre viraria
 * um laço diário sem ninguém olhando, e o que a operação precisa é justamente
 * que alguém olhe.
 */
class ReprocessarEventosDeGateway extends Command
{
    /**
     * Nomes de provedor cujo payload esta implementação sabe reinterpretar.
     *
     * Repete a lista de `GatewayWebhookController::GATEWAYS_CONHECIDOS` de
     * propósito, em vez de importá-la: comando artisan que depende de um
     * controller HTTP amarra duas bordas que não têm nada a ver uma com a outra.
     * A lista fica nas bordas, e não em `GatewayEventService`, porque é ali que
     * citar a implementação concreta não amarra o domínio a ela.
     *
     * @var array<int, string>
     */
    private const GATEWAYS_CONHECIDOS = [
        PagBankGateway::NOME,
    ];

    protected $signature = 'plataforma:reprocessar-eventos
                            {--id= : Id da linha de gateway_events a reprocessar, mesmo que ela não tenha erro. Sem esta opção, reprocessa todos os eventos com erro pendente.}';

    protected $description = 'Reprocessa evento de webhook do gateway a partir do payload gravado, sem falar com o provedor.';

    public function __construct(
        private readonly GatewayAssinatura $gateway,
        private readonly GatewayEventService $gatewayEventService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $eventos = $this->eventosAReprocessar();

        if ($eventos === false) {
            return Command::INVALID;
        }

        if ($eventos->isEmpty()) {
            $this->line('Nenhum evento de gateway pendente de reprocessamento.');

            return Command::SUCCESS;
        }

        $linhas = [];
        $falharam = 0;

        foreach ($eventos as $evento) {
            $resultado = $this->reprocessar($evento);

            if (! $resultado['processado']) {
                $falharam++;
            }

            $linhas[] = $resultado['linha'];
        }

        $this->newLine();
        $this->table(['#', 'Evento no provedor', 'Tipo', 'Resultado', 'Observação'], $linhas);

        $this->line(sprintf(
            '%d evento(s) reprocessado(s), %d ainda com erro.',
            $eventos->count(),
            $falharam
        ));

        return $falharam > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Eventos que esta passada vai reprocessar, ou `false` quando a opção
     * recebida não aponta para nada.
     *
     * Sem `--id`, o filtro é `erro` preenchido **e** `processado_em` nulo. As
     * duas condições são equivalentes no fluxo normal, porque o processamento
     * que falha nunca chega a gravar `processado_em` (a transação inteira volta
     * atrás). O par existe pelo caminho de exceção: um `--id` usado para depurar
     * um evento que já tinha dado certo pode deixá-lo com erro **e** processado,
     * e sem a segunda condição ele entraria em toda passada seguinte para
     * sempre.
     *
     * Com `--id`, o evento é reprocessado mesmo sem erro e mesmo já processado.
     * É o uso de depuração: rodar o efeito de novo com log ligado, ou depois de
     * corrigir à mão um dado que o evento dependia.
     *
     * @return Collection<int, GatewayEvent>|false
     */
    private function eventosAReprocessar(): Collection|false
    {
        $id = $this->option('id');

        if ($id === null) {
            return GatewayEvent::query()
                ->whereNotNull('erro')
                ->whereNull('processado_em')
                ->orderBy('id')
                ->get();
        }

        if (! ctype_digit((string) $id) || (int) $id < 1) {
            $this->error("--id precisa ser o id inteiro positivo de uma linha de gateway_events. Valor recebido: \"{$id}\".");

            return false;
        }

        $evento = GatewayEvent::query()->find((int) $id);

        if ($evento === null) {
            $this->error("Evento de gateway #{$id} não existe. Nada foi reprocessado.");

            return false;
        }

        return new Collection([$evento]);
    }

    /**
     * Uma linha da tabela: reinterpreta o payload gravado e manda processar.
     *
     * O erro de cada evento fica contido nesta volta. Um payload que a
     * implementação atual não consegue reinterpretar não pode interromper o
     * reprocessamento dos demais, que é justamente o que a passada em lote
     * existe para fazer.
     *
     * @return array{processado: bool, linha: array{0: int, 1: string, 2: string, 3: string, 4: string}}
     */
    private function reprocessar(GatewayEvent $evento): array
    {
        try {
            $eventoDeGateway = $this->interpretar($evento);
        } catch (Throwable $erro) {
            report($erro);

            return $this->resultado($evento, processado: false, observacao: $erro->getMessage());
        }

        $this->gatewayEventService->processar($evento, $eventoDeGateway);

        $evento->refresh();

        // O Service não lança: quem diz se deu certo é a linha depois dele.
        return $evento->processado_em === null
            ? $this->resultado($evento, processado: false, observacao: (string) $evento->erro)
            : $this->resultado($evento, processado: true, observacao: '');
    }

    /**
     * Traduz de novo o payload gravado para o vocabulário do domínio.
     *
     * O nome do provedor gravado na linha é conferido antes: entregar o payload
     * de um gateway à implementação de outro produziria "desconhecido" em
     * silêncio, e o evento seria marcado como processado sem nunca ter tido
     * efeito.
     *
     * @throws Throwable
     */
    private function interpretar(GatewayEvent $evento): EventoDeGateway
    {
        if (! in_array($evento->gateway, self::GATEWAYS_CONHECIDOS, true)) {
            throw new RuntimeException(sprintf(
                'O evento foi recebido do gateway "%s", que este sistema não interpreta hoje. Gateways conhecidos: %s.',
                (string) $evento->gateway,
                implode(', ', self::GATEWAYS_CONHECIDOS)
            ));
        }

        return $this->gateway->interpretarWebhook((array) $evento->payload);
    }

    /**
     * Desfecho de uma volta: o que a tabela mostra e o que o total conta.
     *
     * @return array{processado: bool, linha: array{0: int, 1: string, 2: string, 3: string, 4: string}}
     */
    private function resultado(GatewayEvent $evento, bool $processado, string $observacao): array
    {
        return [
            'processado' => $processado,
            'linha' => [
                (int) $evento->getKey(),
                (string) $evento->evento_id,
                (string) $evento->tipo,
                $processado ? 'processado' : 'erro',
                $observacao,
            ],
        ];
    }
}
