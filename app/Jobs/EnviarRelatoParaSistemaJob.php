<?php

namespace App\Jobs;

use App\Jobs\Concerns\CarregaTenant;
use App\Models\ProblemReport;
use App\Services\ProblemReportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response as RespostaHttp;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Repassa um relato de problema ao sistema central de suporte técnico, onde
 * ele vira tarefa `bug` (`POST /api/v1/intake/reports`).
 *
 * O contrato do endpoint está em `docs/intake-relatos.md` do sistema central.
 * O que importa aqui:
 *
 * - O corpo leva só o relato e um identificador opaco de quem relatou
 *   (`reporter_ref`). Nome, e-mail e telefone ficam neste sistema, e o
 *   sistema central recusa esses campos com 422.
 * - `external_id` é o id do relato. Reenviar o mesmo relato devolve 200 com a
 *   mesma tarefa, então repetir depois de timeout ou queda é seguro.
 * - 401 (token errado ou revogado), 409 (projeto na lixeira) e 422 (corpo fora
 *   do contrato) são finais: repetir dá a mesma resposta. O job falha de vez e
 *   deixa o motivo no log.
 * - 403 (troca de senha pendente do dono do token), 429 e 5xx são passageiros,
 *   assim como timeout e falha de conexão.
 *
 * Como o job roda
 * ---------------
 * Este sistema não tem worker de fila garantido em produção, então
 * `ProblemReportService::registrar()` despacha o job com `afterResponse()`: a
 * primeira tentativa roda no mesmo processo, logo depois de a resposta sair
 * para o navegador, em uma `SyncJob`. Nesse caminho, falha passageira não
 * lança exceção (o relato já está gravado e quem relatou já recebeu a
 * confirmação; uma exceção ali só sujaria o log de erro do PHP). Em vez
 * disso, o job se reenfileira na conexão padrão (`QUEUE_CONNECTION`) com a
 * primeira espera do `$backoff`. Com worker rodando, as tentativas seguintes
 * acontecem sozinhas; sem worker, o job espera em `jobs` até alguém rodar a
 * fila, e o relato aparece na plataforma sem o selo "Enviado ao suporte
 * técnico".
 *
 * Já dentro de um worker, falha passageira lança, e a própria fila repete
 * seguindo `$tries` e `$backoff`.
 *
 * Usa `CarregaTenant` como todo job deste projeto: a empresa do relato viaja
 * no payload e o `find()` roda dentro dela.
 */
class EnviarRelatoParaSistemaJob implements ShouldQueue
{
    use CarregaTenant, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var list<int> segundos de espera antes da 2ª, 3ª, 4ª e 5ª tentativa */
    public array $backoff = [10, 60, 300, 900];

    /**
     * Respostas definitivas: repetir o mesmo corpo dá a mesma resposta.
     */
    private const STATUS_FINAIS = [401, 409, 422];

    private const TEMPO_LIMITE_EM_SEGUNDOS = 15;

    public function __construct(public readonly int $problemReportId)
    {
        $this->capturarTenantAtual();
    }

    public function handle(ProblemReportService $relatos): void
    {
        $url = config('services.sistema_intake.url');
        $token = config('services.sistema_intake.token');

        // Sem token configurado a integração está desligada: o relato fica
        // só aqui, sem chamada nenhuma.
        if (blank($url) || blank($token)) {
            return;
        }

        $relato = ProblemReport::query()->find($this->problemReportId);

        if ($relato === null) {
            Log::info('relatos.envio.relato_nao_encontrado', [
                'problem_report_id' => $this->problemReportId,
            ]);

            return;
        }

        // Tentativa repetida de um relato que já chegou: nada a fazer.
        if ($relato->foiEnviado()) {
            return;
        }

        try {
            $resposta = Http::withToken($token)
                ->acceptJson()
                ->timeout(self::TEMPO_LIMITE_EM_SEGUNDOS)
                ->post($url, $this->corpo($relato));
        } catch (ConnectionException $falha) {
            $this->tentarDeNovoDepois($relato, $falha);

            return;
        }

        $status = $resposta->status();

        if (in_array($status, [200, 201], true)) {
            $tarefaId = $resposta->json('data.task_id');
            $tarefaUrl = $resposta->json('data.task_url');

            $relatos->marcarComoEnviado(
                $relato,
                is_numeric($tarefaId) ? (int) $tarefaId : null,
                is_string($tarefaUrl) ? $tarefaUrl : null,
            );

            Log::info('relatos.envio.enviado', [
                'problem_report_id' => $relato->id,
                'task_id' => $tarefaId,
                'status' => $status,
            ]);

            return;
        }

        if (in_array($status, self::STATUS_FINAIS, true)) {
            Log::error('relatos.envio.recusado', [
                'problem_report_id' => $relato->id,
                'status' => $status,
                'message' => $resposta->json('message'),
            ]);

            $this->fail(new RuntimeException(
                "O sistema central recusou o relato {$relato->id} com {$status}."
            ));

            return;
        }

        $this->tentarDeNovoDepois($relato, $this->falhaDaResposta($resposta));
    }

    /**
     * Corpo do envio, no formato do contrato.
     *
     * @return array<string, string|null>
     */
    private function corpo(ProblemReport $relato): array
    {
        return [
            'external_id' => (string) $relato->id,
            'title' => mb_substr($relato->titulo, 0, ProblemReport::LIMITE_TITULO),
            'description' => mb_substr($relato->descricao, 0, 20000),
            'source_url' => route('plataforma.relatos.show', $relato->id),
            'page_url' => $relato->pagina_url,
            'user_agent' => $relato->user_agent !== null
                ? mb_substr($relato->user_agent, 0, ProblemReport::LIMITE_USER_AGENT)
                : null,
            'reporter_ref' => $relato->referenciaDeQuemRelatou(),
        ];
    }

    /**
     * Falha passageira (403, 429, 5xx, timeout, conexão).
     *
     * No worker, lança e a fila repete. Na primeira tentativa, que roda
     * depois da resposta em uma `SyncJob`, reenfileira na conexão padrão em
     * vez de lançar; ver o cabeçalho da classe. Conexão padrão `sync` não tem
     * como esperar, e reenfileirar ali seria repetir na hora, em laço: nesse
     * caso o relato fica sem envio, registrado no log.
     */
    private function tentarDeNovoDepois(ProblemReport $relato, Throwable $falha): void
    {
        if (! $this->job instanceof SyncJob) {
            throw $falha;
        }

        $conexao = (string) config('queue.default');

        if ($conexao === 'sync') {
            Log::warning('relatos.envio.falhou_sem_fila', [
                'problem_report_id' => $relato->id,
                'erro' => $falha->getMessage(),
            ]);

            return;
        }

        static::dispatch($relato->id)
            ->onConnection($conexao)
            ->delay(now()->addSeconds($this->backoff[0]));

        Log::warning('relatos.envio.reenfileirado', [
            'problem_report_id' => $relato->id,
            'conexao' => $conexao,
            'erro' => $falha->getMessage(),
        ]);
    }

    private function falhaDaResposta(RespostaHttp $resposta): Throwable
    {
        return $resposta->toException()
            ?? new RuntimeException("Resposta inesperada do sistema central: {$resposta->status()}.");
    }
}
