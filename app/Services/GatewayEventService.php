<?php

namespace App\Services;

use App\Models\GatewayEvent;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Support\BusinessDate;
use App\Support\Gateway\EventoDeGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Registro e processamento dos eventos que o gateway de pagamento manda por
 * webhook (Plano 7, Task 7.6).
 *
 * É o único caminho que confirma pagamento automaticamente neste sistema, e por
 * isso o que mais precisa não errar em duas direções opostas: não pode dar baixa
 * duas vezes na mesma fatura, e não pode perder uma confirmação que o provedor
 * mandou uma vez só.
 *
 * ## Idempotência: três barreiras, nessa ordem
 *
 * 1. **`gateway_events.evento_id` é unique** (migration da Task 7.1), e
 *    `registrar()` grava com `firstOrCreate`. Dois envios simultâneos do mesmo
 *    evento resultam em uma linha só, porque o `firstOrCreate` do Laravel trata
 *    a violação da unique como "já existe" em vez de estourar.
 * 2. **`processado_em`**, conferido por quem chama antes de pedir o
 *    processamento (`GatewayWebhookController`). Evento já processado devolve
 *    200 sem passar por aqui de novo.
 * 3. **A saída antecipada de cada método de domínio**: `marcarComoPaga()`
 *    devolve a fatura como está quando ela já está paga, `marcarComoVencida()` e
 *    `marcarComoCancelada()` só agem sobre fatura em aberto, e
 *    `refletirCancelamentoDoGateway()` não mexe em assinatura já cancelada. É
 *    esta terceira que segura o caso que as outras duas não pegam: dois
 *    processos que passem juntos pela barreira 2 antes de qualquer um gravar
 *    `processado_em`.
 *
 * ## Erro de processamento não é erro de requisição
 *
 * Nenhuma exceção sai deste Service. Falha em qualquer parte grava a mensagem em
 * `GatewayEvent.erro`, incrementa `tentativas` e para por aí, para que o
 * controller devolva 200 ao provedor. O motivo é operacional: 500 faz o PagBank
 * reenviar o mesmo evento em laço, e o evento já está guardado aqui com o
 * payload inteiro, então quem reprocessa é
 * `plataforma:reprocessar-eventos`, na hora em que a causa tiver sido corrigida.
 *
 * "Fatura não localizada" entra como erro de processamento pelo mesmo caminho, e
 * é o caso mais comum de todos: costuma significar que a cobrança foi emitida no
 * provedor e a gravação de `gateway_invoice_id` se perdeu, ou que o evento se
 * refere a uma cobrança feita fora do sistema. Marcar como processado sem efeito
 * seria descartar a confirmação de um pagamento real.
 *
 * ## Escopo por empresa
 *
 * `invoices` e `subscriptions` ficam fora do escopo global (Task 7.1), e aqui
 * isso é o comportamento desejado: o webhook chega sem usuário, sem sessão e sem
 * tenant resolvido, e o único vínculo que ele traz é o identificador do recurso
 * no provedor. A empresa é consequência da fatura encontrada, nunca um filtro de
 * entrada.
 */
class GatewayEventService
{
    public function __construct(
        private readonly InvoiceService $invoiceService,
        private readonly SubscriptionService $subscriptionService,
    ) {}

    /**
     * Grava o evento recebido, ou devolve o que já estava gravado.
     *
     * Primeira barreira da idempotência (ver o cabeçalho da classe). O que é
     * gravado em `payload` é o corpo **cru** que o provedor enviou, e não o
     * `EventoDeGateway` interpretado: é o cru que permite reprocessar depois de
     * corrigir um bug de interpretação, e um evento guardado já traduzido
     * carregaria o mesmo erro para sempre.
     *
     * O evento reencontrado não é atualizado. `tipo` e `payload` da primeira
     * gravação são os que valem: reescrevê-los com o reenvio apagaria a única
     * cópia do que chegou da primeira vez, que é justamente o que se olha quando
     * algo deu errado.
     *
     * @param  string  $gateway  Nome do provedor, como veio na rota e como fica em `gateway_events.gateway`.
     * @param  array<string, mixed>  $payload  Corpo cru do webhook, já decodificado do JSON.
     */
    public function registrar(string $gateway, EventoDeGateway $eventoDeGateway, array $payload): GatewayEvent
    {
        return GatewayEvent::firstOrCreate(
            ['evento_id' => $eventoDeGateway->eventoId],
            [
                'gateway' => $gateway,
                'tipo' => $eventoDeGateway->tipo,
                'payload' => $payload,
            ]
        );
    }

    /**
     * Aplica no domínio o que o evento comunica e marca o evento como
     * processado.
     *
     * O efeito de domínio e a gravação de `processado_em` ficam na mesma
     * transação: um evento marcado como processado cuja baixa não foi gravada
     * seria uma confirmação de pagamento perdida sem deixar rastro, porque
     * nenhum reprocessamento o pegaria depois.
     *
     * A chamada ao provedor não acontece em lugar nenhum deste caminho, e é
     * proposital. O evento é o aviso de que algo já aconteceu lá; ligar de volta
     * seria segurar um lock de banco pelo tempo da rede, dentro da transação,
     * para confirmar um fato.
     *
     * Não lança. Ver o cabeçalho da classe.
     *
     * @param  EventoDeGateway  $eventoDeGateway  O evento já interpretado. Recebido pronto, e não reinterpretado a partir de `$evento->payload`, para que a tradução do formato do provedor aconteça uma vez só, no ponto em que o payload cru ainda existe.
     */
    public function processar(GatewayEvent $evento, EventoDeGateway $eventoDeGateway): void
    {
        try {
            DB::transaction(function () use ($evento, $eventoDeGateway): void {
                $this->aplicarNoDominio($eventoDeGateway);

                $evento->update([
                    'processado_em' => now(),
                    'erro' => null,
                ]);
            });
        } catch (Throwable $erro) {
            $this->registrarFalha($evento, $erro);
        }
    }

    // -----------------------------------------------------------------
    // Efeito de cada tipo de evento
    // -----------------------------------------------------------------

    /**
     * Traduz o tipo do evento na única operação de domínio que ele autoriza.
     *
     * `desconhecido` não faz nada e não é erro: o evento fica gravado com o
     * payload inteiro, marcado como processado. O PagBank manda dezenas de
     * eventos que este sistema não usa (`recurrence`, `updated`, `activated`,
     * cupom, assinante), e tratar cada um deles como falha encheria a fila de
     * reprocessamento de eventos que nunca terão o que processar.
     *
     * Tipo novo em `EventoDeGateway::TIPOS` sem ramo aqui é erro de propósito.
     * O construtor de `EventoDeGateway` já recusa tipo fora da lista, então este
     * `default` só é alcançado por um tipo acrescentado lá e esquecido aqui, e o
     * silêncio nesse caso seria perder confirmação de pagamento sem aviso.
     *
     * @throws RuntimeException Quando o recurso do evento não é localizado, ou o tipo não tem tratamento.
     */
    private function aplicarNoDominio(EventoDeGateway $eventoDeGateway): void
    {
        match ($eventoDeGateway->tipo) {
            EventoDeGateway::TIPO_COBRANCA_PAGA => $this->invoiceService->marcarComoPaga(
                $this->faturaDo($eventoDeGateway),
                $this->diaDoPagamento($eventoDeGateway)
            ),
            EventoDeGateway::TIPO_COBRANCA_VENCIDA => $this->invoiceService->marcarComoVencida(
                $this->faturaDo($eventoDeGateway)
            ),
            EventoDeGateway::TIPO_COBRANCA_CANCELADA => $this->invoiceService->marcarComoCancelada(
                $this->faturaDo($eventoDeGateway)
            ),
            EventoDeGateway::TIPO_ASSINATURA_CANCELADA => $this->subscriptionService->refletirCancelamentoDoGateway(
                $this->assinaturaDo($eventoDeGateway)
            ),
            EventoDeGateway::TIPO_DESCONHECIDO => null,
            default => throw new RuntimeException(sprintf(
                'Evento de tipo "%s" não tem tratamento em GatewayEventService. '
                .'Acrescente o ramo correspondente antes de o provedor mandar o próximo.',
                $eventoDeGateway->tipo
            )),
        };
    }

    /**
     * Fatura a que a cobrança do evento se refere.
     *
     * O casamento é por `gateway_invoice_id`, que é o identificador do pedido no
     * provedor gravado por `InvoiceService::gerarPara()`. Sem filtro de empresa:
     * o webhook não tem tenant resolvido, e é a fatura encontrada que diz de quem
     * ela é (ver o cabeçalho da classe).
     *
     * @throws RuntimeException Quando o evento não traz o identificador, ou nenhuma fatura tem esse identificador.
     */
    private function faturaDo(EventoDeGateway $eventoDeGateway): Invoice
    {
        $idNoGateway = $this->identificadorDo($eventoDeGateway, 'da cobrança');

        $fatura = Invoice::query()
            ->where('gateway_invoice_id', $idNoGateway)
            ->first();

        if (! $fatura instanceof Invoice) {
            throw new RuntimeException(sprintf(
                'Nenhuma fatura com gateway_invoice_id "%s". O evento ficou gravado com o payload inteiro: '
                .'concilie a cobrança com o provedor e rode plataforma:reprocessar-eventos.',
                $idNoGateway
            ));
        }

        return $fatura;
    }

    /**
     * Assinatura a que o evento se refere.
     *
     * Em `assinatura_cancelada` o identificador que o evento carrega é o da
     * **assinatura** no provedor (`gateway_subscription_id`), não o de uma
     * cobrança. Procurá-lo em `invoices` não encontraria nada, e o cancelamento
     * do contrato passaria despercebido.
     *
     * @throws RuntimeException Quando o evento não traz o identificador, ou nenhuma assinatura tem esse identificador.
     */
    private function assinaturaDo(EventoDeGateway $eventoDeGateway): Subscription
    {
        $idNoGateway = $this->identificadorDo($eventoDeGateway, 'da assinatura');

        $assinatura = Subscription::query()
            ->where('gateway_subscription_id', $idNoGateway)
            ->first();

        if (! $assinatura instanceof Subscription) {
            throw new RuntimeException(sprintf(
                'Nenhuma assinatura com gateway_subscription_id "%s". O evento ficou gravado com o payload '
                .'inteiro: concilie a assinatura com o provedor e rode plataforma:reprocessar-eventos.',
                $idNoGateway
            ));
        }

        return $assinatura;
    }

    /**
     * Identificador do recurso no provedor, exigido por todo evento que tem
     * efeito de domínio.
     *
     * @param  string  $recurso  Como citar o recurso na mensagem de erro.
     *
     * @throws RuntimeException
     */
    private function identificadorDo(EventoDeGateway $eventoDeGateway, string $recurso): string
    {
        $idNoGateway = $eventoDeGateway->faturaIdNoGateway;

        if (blank($idNoGateway)) {
            throw new RuntimeException(sprintf(
                'O evento "%s" chegou sem o identificador %s no provedor: não há o que localizar.',
                $eventoDeGateway->tipo,
                $recurso
            ));
        }

        return (string) $idNoGateway;
    }

    /**
     * Dia da confirmação do pagamento, no formato que `marcarComoPaga()` espera.
     *
     * Evento sem `pagoEm` cai para hoje no fuso do negócio. É a data menos errada
     * disponível: o evento acabou de chegar, e deixar `pago_em` vazio numa fatura
     * paga estragaria o histórico financeiro do tenant.
     */
    private function diaDoPagamento(EventoDeGateway $eventoDeGateway): string
    {
        return $eventoDeGateway->pagoEm?->toDateString() ?? BusinessDate::hoje()->toDateString();
    }

    // -----------------------------------------------------------------
    // Falha
    // -----------------------------------------------------------------

    /**
     * Guarda a falha no próprio evento, fora da transação que já foi desfeita.
     *
     * `refresh()` antes de gravar porque o rollback desfez a escrita no banco,
     * mas não os atributos que o `update()` da transação já tinha aplicado nesta
     * instância em memória. Sem ele, `processado_em` voltaria ao banco com o
     * instante da tentativa que falhou, e o evento apareceria processado e com
     * erro ao mesmo tempo.
     *
     * `processado_em` não é tocado aqui, nem para zerar. Depois do rollback ele
     * já é o que era antes desta tentativa: nulo no caminho normal, e preenchido
     * no caso raro de um reprocessamento manual (`--id`) de evento que já tinha
     * dado certo. Zerá-lo nesse segundo caso transformaria um evento resolvido em
     * pendência eterna da fila de reprocessamento.
     */
    private function registrarFalha(GatewayEvent $evento, Throwable $erro): void
    {
        $evento->refresh();

        $evento->update([
            'erro' => $erro->getMessage(),
            'tentativas' => (int) $evento->tentativas + 1,
        ]);

        Log::error('gateway_event.processamento_falhou', [
            'gateway_event_id' => $evento->getKey(),
            'gateway' => $evento->gateway,
            'evento_id' => $evento->evento_id,
            'tipo' => $evento->tipo,
            'tentativas' => $evento->tentativas,
            'erro' => $erro->getMessage(),
        ]);
    }
}
