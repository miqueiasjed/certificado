<?php

namespace App\Services\Sync;

use App\Models\User;
use App\Services\Ppe\ConfirmacaoDeEpiService;
use App\Services\Sync\Concerns\OperacaoDeCampo;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Aplica uma operação de sincronização do tipo `confirmacao_epi` (Plano 29,
 * Task 29.3): o que o técnico confirmou vestir na execução da ordem de serviço.
 *
 * A gravação inteira é delegada a `ConfirmacaoDeEpiService::registrar()` (Task
 * 29.2). **Nada de regra é repetido aqui**: a exigência de justificativa para a
 * falta, o descarte silencioso de um EPI que deixou de ser exigido, a preservação
 * do instante original no reenvio e a idempotência da linha do par (OS, EPI) vivem
 * lá, e reimplementar qualquer uma delas produziria duas versões da mesma regra —
 * com a do aplicativo divergindo da do escritório.
 *
 * Formato do payload
 * ------------------
 * ```
 * {
 *   "uuid": "...", "tipo": "confirmacao_epi", "work_order_id": 12,
 *   "registrada_em": "2026-08-08T07:12:00-03:00",
 *   "payload": {
 *     "confirmacoes": [
 *       {"personal_protective_equipment_id": 3, "confirmado": true},
 *       {"personal_protective_equipment_id": 5, "confirmado": false,
 *        "justificativa": "Respirador com a troca vencida, sem reposição na base."}
 *     ]
 *   }
 * }
 * ```
 *
 * Duas camadas de idempotência, e as duas são necessárias
 * ------------------------------------------------------
 * 1. `AppSyncService` reconhece o **mesmo `operacao_uuid`** e devolve o resultado
 *    da primeira aplicação sem chamar este aplicador de novo. Cobre o reenvio do
 *    lote inteiro quando a resposta se perde na rede.
 * 2. `ConfirmacaoDeEpiService` reconhece o **mesmo par (OS, EPI)** e atualiza a
 *    linha em vez de inserir a segunda. Cobre o caso que a primeira não cobre: o
 *    aplicativo que perdeu a fila local, ou o técnico que corrigiu a resposta e
 *    enviou uma operação nova, com uuid novo, sobre a mesma OS.
 *
 * Sem a segunda camada, o segundo envio estouraria a unique composta da Task 29.1
 * e a operação terminaria como conflito na tela do técnico, por um registro que o
 * servidor já tem.
 *
 * `confirmado_em` é o instante do aparelho
 * ---------------------------------------
 * Cada item pode trazer o seu; sem ele, vale o `registrada_em` da própria operação
 * — o mesmo instante gravado em `sync_operations.registrada_em`, e nunca a hora em
 * que a sincronização chegou ao servidor. A confirmação é fato de campo, e pode
 * chegar horas depois, quando o técnico volta a ter sinal.
 *
 * Nada aqui bloqueia a execução da OS
 * -----------------------------------
 * Decisão registrada do plano. A única recusa possível é sobre o conteúdo do
 * próprio registro (falta de EPI sem justificativa), e ela vira conflito
 * `regra_de_negocio` com a mensagem em português, tratada pelo fluxo já existente
 * do Plano 12 (`SyncConflict`, `ResolucaoDeConflito.vue`). A OS conclui, assina e
 * gera documento do mesmo jeito.
 */
class AplicadorDeConfirmacaoDeEpi implements AplicadorDeOperacao
{
    use OperacaoDeCampo;

    public function __construct(
        private readonly ConfirmacaoDeEpiService $confirmacoes,
    ) {}

    public function tipo(): string
    {
        return 'confirmacao_epi';
    }

    /**
     * @throws ValidationException Quando a lista vem vazia ou malformada, ou
     *                             quando uma falta chega sem justificativa. Vira
     *                             conflito `regra_de_negocio` em `AppSyncService`.
     */
    public function aplicar(array $payload, User $usuario): Model
    {
        $workOrder = $this->workOrderDestravada($payload);

        $confirmacoes = $payload['confirmacoes'] ?? null;

        if (! is_array($confirmacoes) || $confirmacoes === []) {
            throw ValidationException::withMessages([
                'confirmacoes' => 'A confirmação de EPI chegou sem nenhum item. '
                    .'Informe, para cada EPI exigido, se ele foi usado nesta ordem de serviço.',
            ]);
        }

        $instante = $this->instanteDoCelular($payload);

        $this->confirmacoes->registrar(
            $workOrder,
            $this->comInstanteEAutor($confirmacoes, $instante, $usuario)
        );

        // O aviso ao gestor sai daqui, e não do fluxo de conclusão da OS: a
        // paramentação acontece antes da execução, então esta operação chega
        // antes (ou junto com) a de `execucao`, e é só depois de as confirmações
        // estarem gravadas que existe o que avisar. `avisarExecucaoSemEpi()`
        // engole o próprio erro de propósito e é idempotente por OS pela chave do
        // `NotificationService`: chamá-lo de novo num reenvio não produz um
        // segundo e-mail.
        $this->confirmacoes->avisarExecucaoSemEpi($workOrder);

        // A operação grava N linhas — uma por EPI exigido —, e não existe "o"
        // registro afetado. A OS é o agregado a que a operação se refere, e é o
        // que `AplicadorDeExecucao` também devolve. O aplicativo não depende deste
        // retorno: ele lê a situação da própria operação.
        return $workOrder;
    }

    /**
     * Completa cada item com o autor e com o instante da operação, quando o
     * aparelho não mandou um instante próprio.
     *
     * `user_id` é injetado aqui porque o Service nunca chama `Auth::user()` por
     * dentro — ele também roda em comando artisan e em teste, onde não há usuário
     * autenticado. Item que já traz `user_id` é preservado: quem confirmou é quem
     * estava com o aparelho, não necessariamente quem sincronizou.
     *
     * Item que não é sequer um array é preservado como veio: quem descarta lixo do
     * payload é o `normalizar()` do Service, e adiantar essa decisão aqui criaria
     * um segundo lugar onde a regra vive.
     *
     * **O instante sai daqui já em UTC, como objeto `Carbon`.** O aparelho manda a
     * hora com o fuso dele (`2026-08-08T07:12:00-03:00`), e o Eloquent grava um
     * `datetime` formatando o valor como ele chega, sem converter o fuso: sem esta
     * normalização, "07:12 de Brasília" iria para a coluna como se fosse 07:12 em
     * UTC, e a ficha mostraria a confirmação três horas antes da execução. É a
     * exigência da skill `datas-timezone` — instante é gravado em UTC e convertido
     * só na exibição — e vale tanto para o instante da operação quanto para o que
     * cada item traz por conta própria.
     *
     * Valor ilegível segue como veio, sem exceção: `ConfirmacaoDeEpiService` já
     * trata isso transformando em nulo, porque a hora do registro é informação de
     * apoio e recusar a confirmação inteira por causa do formato de uma data
     * jogaria fora o fato — que é o que a NR-6 e a RDC 622/2022 cobram.
     *
     * @param  array<int|string, mixed>  $confirmacoes
     * @return array<int|string, mixed>
     */
    private function comInstanteEAutor(array $confirmacoes, Carbon $instante, User $usuario): array
    {
        $daOperacao = $instante->clone()->utc();

        foreach ($confirmacoes as $chave => $item) {
            if (! is_array($item)) {
                continue;
            }

            $item['confirmado_em'] = blank($item['confirmado_em'] ?? null)
                ? $daOperacao
                : $this->emUtc($item['confirmado_em']);

            if (blank($item['user_id'] ?? null)) {
                $item['user_id'] = $usuario->getKey();
            }

            $confirmacoes[$chave] = $item;
        }

        return $confirmacoes;
    }

    /**
     * Instante enviado pelo item, convertido para UTC.
     *
     * O que não dá para interpretar volta como veio, para o Service aplicar a
     * própria tolerância (ver o docblock de `comInstanteEAutor()`).
     */
    private function emUtc(mixed $valor): mixed
    {
        if ($valor instanceof Carbon) {
            return $valor->clone()->utc();
        }

        try {
            return Carbon::parse($valor)->utc();
        } catch (Throwable) {
            return $valor;
        }
    }
}
