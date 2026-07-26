<?php

namespace App\Services;

use App\Models\NotificationLog;
use App\Models\NotificationQueue;
use App\Services\Notification\ClassificadorDeFalhaDeEnvio;
use App\Services\Notification\DriverDeEmail;
use App\Services\Notification\DriverDeEnvio;
use App\Services\Notification\ResultadoDeEnvio;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Tira da fila o que já venceu, manda pelo driver do canal e decide o que
 * acontece com a falha.
 *
 * É o único lugar que grava em `notification_logs` e o único que altera a
 * situação de um item depois do enfileiramento. Driver nenhum grava nada: a
 * regra de retentativa vale igual para e-mail e para o WhatsApp que entra
 * depois, e regra repetida em dois lugares vira duas regras.
 *
 * Ciclo de um item
 * ----------------
 * `pendente` -> `enviando` -> `enviada`, `falha` ou de volta a `pendente` com
 * hora marcada para a próxima tentativa. O `enviando` não é enfeite: é o que
 * impede duas execuções concorrentes de mandarem o mesmo aviso duas vezes, e
 * ele é assumido com um update condicional (`where situacao = pendente`), que
 * o banco resolve atomicamente. Quem perder a corrida encontra zero linhas
 * atualizadas e segue para o próximo item.
 *
 * Contrapartida do `enviando`: processo morto no meio deixa o item preso nele
 * para sempre. Daí `destravarPresos()`, que roda no começo de toda passada.
 *
 * Espera crescente
 * ----------------
 * A tentativa é contada no momento em que o item é assumido, e não no momento
 * em que o log é gravado. Assim o processo que morre no meio do envio consome a
 * tentativa dele, e um item que derruba o processo toda vez (falta de memória
 * ao gerar um PDF gigante, por exemplo) termina em `falha` depois de quatro
 * passadas em vez de bloquear a fila para sempre.
 *
 * Falha permanente não espera nada: vai direto para `falha`. Repetir e-mail
 * para caixa inexistente quatro vezes derruba a reputação do remetente do
 * tenant e leva junto a entrega de todos os outros avisos dele.
 *
 * Escopo por empresa
 * ------------------
 * Todas as consultas daqui passam pelo escopo global de `BelongsToCompany`, ou
 * seja, enxergam apenas o tenant corrente. Quem garante que existe um tenant
 * resolvido é quem chama: o comando `notificacoes:despachar` usa
 * `OperaPorTenant`, que roda uma passada por empresa dentro de
 * `TenantAtual::comTenant()`.
 */
class NotificationDispatcher
{
    /**
     * Espera até a próxima tentativa, em minutos, indexada pela tentativa que
     * acabou de falhar: 5min depois da primeira, 30min depois da segunda, 2h
     * depois da terceira.
     *
     * A quarta espera (6h) está declarada porque é o que a especificação lista,
     * mas com o teto em quatro tentativas ela só passa a valer se o teto subir:
     * a quarta falha temporária encerra o item em `falha`.
     *
     * @var array<int, int>
     */
    public const ESPERAS_EM_MINUTOS = [5, 30, 120, 360];

    /**
     * Teto de tentativas por item. A quarta falha temporária encerra em
     * `falha`: além disso, o provedor já respondeu quatro vezes que não vai
     * entregar, e o aviso perdeu a validade prática (lembrete de véspera
     * entregue no dia seguinte não serve para nada).
     */
    public const MAXIMO_DE_TENTATIVAS = 4;

    /**
     * Minutos em `enviando` a partir dos quais o item é considerado abandonado.
     *
     * Quinze minutos é folga de três passadas da rotina: envio real leva
     * segundos, e mesmo a geração do PDF de uma OS grande não chega perto
     * disso. Prazo menor arriscaria destravar um item que ainda está sendo
     * enviado e mandar o aviso duas vezes.
     */
    public const MINUTOS_PARA_DESTRAVAR = 15;

    /**
     * Drivers disponíveis, indexados pelo canal que cada um atende.
     *
     * @var array<string, DriverDeEnvio>
     */
    private array $drivers = [];

    /**
     * @param  array<int, DriverDeEnvio>|null  $drivers  Drivers desta execução. Sem isto, vale o de e-mail, que é o único desta entrega. A Task 14.9 injeta driver de mentira aqui para testar falha de provedor sem rede, e o driver de WhatsApp entra na mesma lista quando existir.
     */
    public function __construct(?array $drivers = null)
    {
        foreach ($drivers ?? [app(DriverDeEmail::class)] as $driver) {
            if (! $driver instanceof DriverDeEnvio) {
                throw new InvalidArgumentException(
                    'Todo driver do despachante precisa implementar '.DriverDeEnvio::class.'.'
                );
            }

            $this->drivers[$driver->canal()] = $driver;
        }
    }

    /**
     * Uma passada da rotina, no tenant corrente.
     *
     * @param  int  $limite  Máximo de itens processados nesta passada. Existe para que uma fila represada não segure o processo por minutos: o que sobrar sai na passada seguinte, 5 minutos depois.
     * @return array{
     *     destravados: int,
     *     processados: int,
     *     enviados: int,
     *     reagendados: int,
     *     falhas_permanentes: int,
     *     desistidos: int,
     *     disputados: int
     * }
     */
    public function despachar(int $limite = 100): array
    {
        $resumo = [
            'destravados' => $this->destravarPresos(),
            'processados' => 0,
            'enviados' => 0,
            'reagendados' => 0,
            'falhas_permanentes' => 0,
            'desistidos' => 0,
            'disputados' => 0,
        ];

        foreach ($this->itensProntos($limite) as $item) {
            if (! $this->assumir($item)) {
                // Outra execução pegou este item entre a consulta e o update.
                $resumo['disputados']++;

                continue;
            }

            $resumo['processados']++;

            $resultado = $this->entregar($item);
            $situacao = $this->concluirTentativa($item, $resultado);

            match (true) {
                $resultado->ehSucesso() => $resumo['enviados']++,
                $resultado->ehFalhaPermanente() => $resumo['falhas_permanentes']++,
                $situacao === NotificationQueue::SITUACAO_FALHA => $resumo['desistidos']++,
                default => $resumo['reagendados']++,
            };
        }

        return $resumo;
    }

    /**
     * Devolve à fila os itens abandonados em `enviando`.
     *
     * A tentativa abandonada é registrada como falha temporária, com o motivo
     * escrito: sem essa linha, o histórico teria um buraco justamente na
     * tentativa que ninguém viu acontecer, e é ele que responde "por que o
     * cliente não recebeu".
     *
     * @return int quantidade de itens devolvidos ou encerrados
     */
    public function destravarPresos(): int
    {
        $limite = CarbonImmutable::now()->subMinutes(self::MINUTOS_PARA_DESTRAVAR);

        $presos = NotificationQueue::query()
            ->where('situacao', NotificationQueue::SITUACAO_ENVIANDO)
            ->where('updated_at', '<=', $limite)
            ->get();

        foreach ($presos as $item) {
            // Item forçado a `enviando` sem passar por `assumir()` chega com
            // zero tentativas. Contar como a primeira mantém a espera crescente
            // coerente com o resto do ciclo.
            if ((int) $item->tentativas < 1) {
                $item->tentativas = 1;
            }

            $this->concluirTentativa($item, ResultadoDeEnvio::falhaTemporaria(
                'A execução que enviava este aviso foi interrompida antes de terminar. '
                .'A tentativa não chegou a ser confirmada pelo provedor.'
            ));
        }

        return $presos->count();
    }

    /**
     * Itens que já podem sair: pendentes, com a hora de envio vencida e sem
     * espera de retentativa pendente.
     *
     * O filtro por canal deixa de fora o que não tem driver. Item de WhatsApp
     * fica na fila esperando o clique no link `wa.me`, que é o fluxo manual
     * desta entrega, e não pode ser marcado como falha por não ter para onde
     * ir.
     *
     * @return Collection<int, NotificationQueue>
     */
    private function itensProntos(int $limite): Collection
    {
        $agora = CarbonImmutable::now();

        return NotificationQueue::query()
            ->where('situacao', NotificationQueue::SITUACAO_PENDENTE)
            ->whereIn('canal', array_keys($this->drivers))
            ->where('agendada_para', '<=', $agora)
            ->where(function ($consulta) use ($agora): void {
                $consulta->whereNull('proxima_tentativa_em')
                    ->orWhere('proxima_tentativa_em', '<=', $agora);
            })
            ->orderBy('agendada_para')
            ->orderBy('id')
            ->limit(max(1, $limite))
            ->get();
    }

    /**
     * Marca o item como `enviando` e conta a tentativa, em um update
     * condicional que só passa se ele ainda estiver `pendente`.
     *
     * É a trava contra envio duplicado quando duas execuções varrem a fila ao
     * mesmo tempo. O `Cache::lock` do comando evita o caso comum; este update
     * é o que fecha a janela que o lock não cobre, porque quem decide é o
     * banco.
     */
    private function assumir(NotificationQueue $item): bool
    {
        $agora = CarbonImmutable::now();
        $tentativa = (int) $item->tentativas + 1;

        $assumidos = NotificationQueue::query()
            ->whereKey($item->getKey())
            ->where('situacao', NotificationQueue::SITUACAO_PENDENTE)
            ->update([
                'situacao' => NotificationQueue::SITUACAO_ENVIANDO,
                'tentativas' => $tentativa,
                'proxima_tentativa_em' => null,
                // Explícito porque é este carimbo que `destravarPresos()` lê
                // para saber há quanto tempo o item está em posse de alguém.
                'updated_at' => $agora,
            ]);

        if ($assumidos === 0) {
            return false;
        }

        $item->forceFill([
            'situacao' => NotificationQueue::SITUACAO_ENVIANDO,
            'tentativas' => $tentativa,
            'proxima_tentativa_em' => null,
            'updated_at' => $agora,
        ])->syncOriginal();

        return true;
    }

    /**
     * Chama o driver do canal do item.
     *
     * O try/catch é rede de segurança sobre a obrigação do driver de não
     * lançar: um driver novo com defeito derrubaria a rotina inteira, e com ela
     * os avisos de todos os outros itens e de todas as outras empresas.
     */
    private function entregar(NotificationQueue $item): ResultadoDeEnvio
    {
        $driver = $this->drivers[$item->canal] ?? null;

        if ($driver === null) {
            return ResultadoDeEnvio::falhaPermanente(
                "Nenhum driver de envio registrado para o canal \"{$item->canal}\"."
            );
        }

        try {
            return $driver->enviar($item);
        } catch (Throwable $erro) {
            report($erro);

            return ClassificadorDeFalhaDeEnvio::classificar($erro);
        }
    }

    /**
     * Grava a tentativa e aplica o resultado ao item.
     *
     * @return string a situação em que o item ficou
     */
    private function concluirTentativa(NotificationQueue $item, ResultadoDeEnvio $resultado): string
    {
        $this->registrarTentativa($item, $resultado);

        $situacao = $this->situacaoDepoisDe($item, $resultado);

        $item->update([
            'situacao' => $situacao,
            'proxima_tentativa_em' => $situacao === NotificationQueue::SITUACAO_PENDENTE
                ? $this->proximaTentativa($item)
                : null,
            'tentativas' => (int) $item->tentativas,
        ]);

        if ($situacao === NotificationQueue::SITUACAO_FALHA) {
            Log::warning('Aviso encerrado sem entrega.', [
                'notification_queue_id' => $item->id,
                'evento' => $item->evento,
                'canal' => $item->canal,
                'destino' => $item->destino,
                'tentativas' => $item->tentativas,
                'resultado' => $resultado->resultado,
                'motivo' => $resultado->mensagem,
            ]);
        }

        return $situacao;
    }

    /**
     * Uma linha por tentativa, inclusive a que falhou.
     *
     * `company_id` vem do item, e não do tenant corrente: a linha do histórico
     * pertence à mesma empresa do aviso, sempre, mesmo que alguém chame o
     * despachante de um contexto mal resolvido.
     */
    private function registrarTentativa(NotificationQueue $item, ResultadoDeEnvio $resultado): void
    {
        NotificationLog::create([
            'company_id' => $item->company_id,
            'notification_queue_id' => $item->id,
            'tentativa' => max(1, (int) $item->tentativas),
            'resultado' => $resultado->resultado,
            'mensagem' => $resultado->mensagem,
            'resposta_bruta' => $resultado->respostaBruta === [] ? null : $resultado->respostaBruta,
            // Instante, gravado no fuso da aplicação como todo instante do
            // projeto e convertido para o fuso do negócio só na exibição.
            'ocorrida_em' => CarbonImmutable::now(),
        ]);
    }

    /**
     * Situação em que o item fica depois desta tentativa.
     */
    private function situacaoDepoisDe(NotificationQueue $item, ResultadoDeEnvio $resultado): string
    {
        if ($resultado->ehSucesso()) {
            return NotificationQueue::SITUACAO_ENVIADA;
        }

        if ($resultado->ehFalhaPermanente()) {
            return NotificationQueue::SITUACAO_FALHA;
        }

        return (int) $item->tentativas >= self::MAXIMO_DE_TENTATIVAS
            ? NotificationQueue::SITUACAO_FALHA
            : NotificationQueue::SITUACAO_PENDENTE;
    }

    /**
     * Quando a próxima tentativa pode acontecer, pela espera da tentativa que
     * acabou de falhar.
     */
    private function proximaTentativa(NotificationQueue $item): CarbonImmutable
    {
        $tentativa = max(1, (int) $item->tentativas);
        $ultima = self::ESPERAS_EM_MINUTOS[count(self::ESPERAS_EM_MINUTOS) - 1];
        $espera = self::ESPERAS_EM_MINUTOS[$tentativa - 1] ?? $ultima;

        return CarbonImmutable::now()->addMinutes($espera);
    }
}
