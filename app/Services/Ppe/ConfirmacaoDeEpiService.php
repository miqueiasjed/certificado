<?php

namespace App\Services\Ppe;

use App\Models\PersonalProtectiveEquipment;
use App\Models\ServicePpeRequirement;
use App\Models\WorkOrder;
use App\Models\WorkOrderPpeConfirmation;
use App\Services\NotificationService;
use App\Support\EventosDeNotificacao;
use App\Support\TenantAtual;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * O que o técnico confirmou vestir numa ordem de serviço (Plano 29, Task 29.2).
 *
 * **Nenhuma escrita em `work_order_ppe_confirmations` acontece fora daqui.**
 * Gravar a linha por Eloquent direto pula a exigência de justificativa e a
 * idempotência do reenvio, que são as duas únicas coisas que este Service
 * existe para garantir.
 *
 * Nada aqui bloqueia a execução da OS
 * -----------------------------------
 * Decisão registrada do plano, e a que mais provavelmente será questionada: a
 * confirmação é **registro, não trava**. `registrar()` não impede concluir,
 * assinar nem emitir documento, e `avisarExecucaoSemEpi()` engole o próprio erro
 * de propósito. Travar o técnico em campo por pendência cadastral de escritório
 * tira a operação do ar — a mesma escolha já feita no checklist do Plano 24: o
 * sistema informa, não impede.
 *
 * A única recusa que existe aqui é a da justificativa ausente, e ela é sobre o
 * conteúdo do próprio registro, não sobre a OS: uma falta de EPI sem motivo
 * declarado é um registro que não serve para nada depois, nem para o gestor nem
 * para a fiscalização.
 *
 * Idempotência de verdade, não só a unique
 * ----------------------------------------
 * A confirmação chega pela fila offline do Plano 12, e o aplicativo reenvia
 * quando a conexão cai depois do envio e antes da resposta. O reenvio **atualiza
 * a linha existente**, nunca insere a segunda. Quem garante isso:
 *
 * 1. a busca pela linha do par (OS, EPI) com `lockForUpdate()`, dentro da
 *    transação, antes de decidir entre inserir e atualizar;
 * 2. o `catch` da violação da unique composta do banco, para a corrida em que
 *    duas sincronizações do mesmo aparelho chegam ao servidor ao mesmo tempo —
 *    ali o insert perdedor vira atualização, e não erro na cara do técnico.
 *
 * Confiar só na unique deixaria o segundo envio estourar `QueryException` e a
 * operação da fila terminar como falha permanente, com o técnico reenviando para
 * sempre uma confirmação que o servidor já tem.
 *
 * `confirmado_em` é o instante do aparelho
 * ---------------------------------------
 * Vem do que o técnico enviou, não da hora em que a sincronização chegou ao
 * servidor: o registro é de um fato de campo que pode ter acontecido horas antes,
 * sem sinal. Mesmo critério de `sync_operations.registrada_em` (Plano 12). É a
 * exceção legítima ao `BusinessDate`: instante, não dia. O reenvio **preserva** o
 * instante original quando não manda outro — sobrescrever com `now()` faria a
 * hora do registro andar a cada tentativa de sincronização.
 */
class ConfirmacaoDeEpiService
{
    /**
     * O aviso ao gestor passa pela central de notificações do Plano 14, como
     * todo aviso do sistema.
     */
    public function __construct(private readonly NotificationService $notificacoes) {}

    /**
     * Grava o que o técnico confirmou vestir nesta OS: uma linha por EPI exigido
     * pelos serviços dela.
     *
     * `$confirmacoes` é uma lista de itens com:
     *
     * - `personal_protective_equipment_id` (obrigatório; também aceito como
     *   chave do array, que é como a fila offline costuma indexar);
     * - `confirmado` (obrigatório): o técnico vestiu?
     * - `justificativa`: obrigatória quando `confirmado` é falso;
     * - `confirmado_em`: instante do aparelho; agora, quando ausente e a linha é
     *   nova;
     * - `user_id`: quem confirmou.
     *
     * O que este método garante, e por isso ninguém escreve na tabela por fora:
     *
     * 1. A OS é do tenant corrente, e o EPI é um dos exigidos pelos serviços
     *    dela — o id cru vindo do aparelho nunca é gravado sem passar por uma
     *    consulta escopada.
     * 2. `confirmado = false` sem justificativa é recusado.
     * 3. Reenviar a mesma confirmação atualiza a linha, não duplica.
     *
     * EPI que não é exigido por nenhum serviço da OS é **ignorado em silêncio**,
     * e não recusado: o aplicativo trabalha com a carga do dia (Task 29.3), que
     * pode ter sido baixada antes de alguém mexer na exigência do serviço no
     * escritório. Recusar faria a operação da fila falhar em campo, sem tela onde
     * corrigir. A exceção é o EPI que já tem linha nesta OS: ele continua
     * aceitando atualização mesmo que a exigência tenha sido removida depois, ou
     * o reenvio de uma confirmação legítima deixaria de ser idempotente.
     *
     * EPI exigido que **não veio** na lista simplesmente não gera linha. Inventar
     * uma linha `confirmado = false` para o que ninguém respondeu seria acusar o
     * técnico de não ter usado o equipamento por causa de um campo ausente; a
     * ausência de resposta é justamente o que o checklist da Task 29.4 lê como
     * pendência, e "não informado" nunca é "irregular".
     *
     * @param  array<int|string, array<string, mixed>>  $confirmacoes
     *
     * @throws ValidationException Quando falta o EPI, falta o `confirmado` ou
     *                             falta a justificativa de uma negativa.
     */
    public function registrar(WorkOrder $os, array $confirmacoes): void
    {
        $this->garantirOsDoTenant($os);

        if ($confirmacoes === []) {
            return;
        }

        $exigidos = $this->episExigidos($os);
        $normalizadas = [];

        foreach ($confirmacoes as $chave => $confirmacao) {
            $item = $this->normalizar($chave, $confirmacao);

            if ($item === null) {
                continue;
            }

            $epiId = $item['personal_protective_equipment_id'];

            // Exigido hoje, ou já registrado nesta OS. Ver o docblock.
            if (! in_array($epiId, $exigidos, true) && ! $this->jaRegistrado($os, $epiId)) {
                continue;
            }

            $normalizadas[$epiId] = $item;
        }

        if ($normalizadas === []) {
            return;
        }

        DB::transaction(function () use ($os, $normalizadas): void {
            foreach ($normalizadas as $item) {
                $this->gravar($os, $item);
            }
        });
    }

    /**
     * Avisa o gestor quando a OS foi executada com EPI obrigatório em falta.
     *
     * Quem chama é o fluxo de conclusão da OS (Tasks 29.3 e 29.5), depois de
     * `registrar()`. **Nunca lança**: um aviso que não sai é um problema de
     * escritório, e derrubar a conclusão de uma OS por causa dele seria
     * exatamente a trava que o plano recusa. O erro vai para log, que é como a
     * verificação de rotinas da Task 14.5 enxerga a falha.
     *
     * Sai **uma vez por OS**, não a cada rotina diária: é evento, não vencimento.
     * A garantia é a chave de idempotência do `NotificationService` (evento +
     * destinatário + referência), sem marco — chamar este método de novo para a
     * mesma OS devolve "duplicada" e não enfileira nada.
     *
     * Só conta o EPI **obrigatório naquele serviço** (`ServicePpeRequirement`),
     * marcado como não usado. EPI recomendado que o técnico não vestiu fica no
     * registro e não vira aviso: transformar recomendação em alarme faria o
     * gestor desligar o evento inteiro, e junto com ele o aviso que importa.
     */
    public function avisarExecucaoSemEpi(WorkOrder $os): void
    {
        try {
            $faltas = $this->faltasObrigatorias($os);

            if ($faltas === []) {
                return;
            }

            $this->notificacoes->enfileirar(
                EventosDeNotificacao::EXECUCAO_SEM_EPI_EXIGIDO,
                $os,
                [
                    'variaveis' => [
                        'epis_em_falta' => $this->emLista(array_column($faltas, 'epi')),
                        'justificativas' => $this->emLista(array_column($faltas, 'texto')),
                    ],
                ]
            );
        } catch (Throwable $erro) {
            // Nada aqui pode impedir a conclusão da OS. Ver o cabeçalho.
            Log::warning('Não foi possível enfileirar o aviso de execução sem EPI exigido.', [
                'work_order_id' => $os->getKey(),
                'erro' => $erro->getMessage(),
            ]);
        }
    }

    // -----------------------------------------------------------------
    // registrar(): passos internos
    // -----------------------------------------------------------------

    /**
     * Grava uma confirmação, inserindo ou atualizando a linha do par (OS, EPI).
     *
     * O `lockForUpdate()` resolve a corrida comum (duas sincronizações do mesmo
     * aparelho em paralelo) e o `catch` da unique resolve a que sobra: dois
     * inserts que passaram juntos pela leitura. Nos dois caminhos o resultado é o
     * mesmo, uma linha só, porque é isso que o reenvio significa.
     *
     * @param  array<string, mixed>  $item
     */
    private function gravar(WorkOrder $os, array $item): void
    {
        $epiId = $item['personal_protective_equipment_id'];

        $existente = $this->confirmacaoExistente($os, $epiId, travar: true);

        if ($existente !== null) {
            $existente->update($this->atributos($item, $existente));

            return;
        }

        try {
            WorkOrderPpeConfirmation::create(array_merge(
                $this->atributos($item, null),
                [
                    'work_order_id' => $os->getKey(),
                    'personal_protective_equipment_id' => $epiId,
                ]
            ));
        } catch (QueryException $excecao) {
            if (! $this->violacaoDaUnique($excecao)) {
                throw $excecao;
            }

            // Outra sincronização gravou a mesma confirmação entre a leitura e
            // este insert. É o que a unique existe para impedir, e aqui isso é
            // sucesso: a confirmação está registrada, e o reenvio a atualiza.
            $this->confirmacaoExistente($os, $epiId, travar: false)
                ?->update($this->atributos($item, null));
        }
    }

    /**
     * Atributos gravados, com a regra da justificativa aplicada.
     *
     * `confirmado_em` do reenvio preserva o instante original quando o aparelho
     * não manda outro: a hora do registro é a do fato em campo, e não pode andar
     * a cada tentativa de sincronização.
     *
     * A justificativa é limpa quando a confirmação passa a ser positiva. Manter o
     * motivo antigo colado numa confirmação corrigida faria o registro dizer duas
     * coisas contraditórias ao mesmo tempo, e é o registro que vai à fiscalização.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function atributos(array $item, ?WorkOrderPpeConfirmation $existente): array
    {
        $confirmado = $item['confirmado'];

        return [
            'confirmado' => $confirmado,
            'justificativa' => $confirmado ? null : $item['justificativa'],
            'confirmado_em' => $item['confirmado_em'] ?? $existente?->confirmado_em ?? now(),
            'user_id' => $item['user_id'] ?? $existente?->user_id,
        ];
    }

    /**
     * Um item da lista, já validado. Devolve null para item que não é sequer um
     * array — lixo no payload da fila não pode derrubar a sincronização inteira.
     *
     * @param  array<string, mixed>|mixed  $confirmacao
     * @return array<string, mixed>|null
     *
     * @throws ValidationException
     */
    private function normalizar(int|string $chave, mixed $confirmacao): ?array
    {
        if (! is_array($confirmacao)) {
            return null;
        }

        // A fila offline costuma indexar a lista pelo id do EPI; o id dentro do
        // item continua valendo quando os dois vêm.
        $epiId = $confirmacao['personal_protective_equipment_id'] ?? (is_numeric($chave) ? $chave : null);

        if (blank($epiId) || ! is_numeric($epiId)) {
            throw ValidationException::withMessages([
                'personal_protective_equipment_id' => 'Informe qual EPI está sendo confirmado.',
            ]);
        }

        if (! array_key_exists('confirmado', $confirmacao) || $confirmacao['confirmado'] === null) {
            // Sem valor, o registro não diz nada: gravar `false` acusaria o
            // técnico de não ter usado o EPI por causa de um campo ausente, e
            // gravar `true` seria pior ainda perante fiscalização. Mesma razão
            // pela qual a coluna nasceu sem default na Task 29.1.
            throw ValidationException::withMessages([
                'confirmado' => 'Informe se o EPI foi usado nesta ordem de serviço.',
            ]);
        }

        $confirmado = filter_var($confirmacao['confirmado'], FILTER_VALIDATE_BOOLEAN);
        $justificativa = trim((string) ($confirmacao['justificativa'] ?? ''));

        if (! $confirmado && $justificativa === '') {
            throw ValidationException::withMessages([
                'justificativa' => 'Informe o motivo de o EPI não ter sido usado: '
                    .'a falta fica registrada, e é o motivo que explica a execução ao gestor e à fiscalização.',
            ]);
        }

        return [
            'personal_protective_equipment_id' => (int) $epiId,
            'confirmado' => $confirmado,
            'justificativa' => $justificativa === '' ? null : $justificativa,
            'confirmado_em' => $this->instante($confirmacao['confirmado_em'] ?? null),
            'user_id' => blank($confirmacao['user_id'] ?? null) ? null : (int) $confirmacao['user_id'],
        ];
    }

    /**
     * Ids dos modelos de EPI exigidos pelos serviços desta OS, obrigatórios ou
     * não.
     *
     * A confirmação é gravada para os dois: o técnico responde sobre o que a
     * etapa mostrou a ele. O que separa um do outro é o aviso ao gestor, que só
     * olha os obrigatórios (ver `faltasObrigatorias()`).
     *
     * A consulta é escopada por `ServicePpeRequirement`, que carrega
     * `BelongsToCompany`: é ela que garante que o id cru vindo do aparelho seja
     * de um EPI da própria empresa antes de virar linha no banco.
     *
     * @return array<int, int>
     */
    private function episExigidos(WorkOrder $os, bool $somenteObrigatorios = false): array
    {
        $servicos = $os->services()->pluck('services.id')->all();

        if ($servicos === []) {
            return [];
        }

        $consulta = ServicePpeRequirement::query()->whereIn('service_id', $servicos);

        if ($somenteObrigatorios) {
            $consulta->where('obrigatorio', true);
        }

        return $consulta
            ->pluck('personal_protective_equipment_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function jaRegistrado(WorkOrder $os, int $epiId): bool
    {
        return $this->confirmacaoExistente($os, $epiId, travar: false) !== null;
    }

    private function confirmacaoExistente(WorkOrder $os, int $epiId, bool $travar): ?WorkOrderPpeConfirmation
    {
        $consulta = WorkOrderPpeConfirmation::query()
            ->where('work_order_id', $os->getKey())
            ->where('personal_protective_equipment_id', $epiId);

        if ($travar) {
            $consulta->lockForUpdate();
        }

        return $consulta->first();
    }

    // -----------------------------------------------------------------
    // avisarExecucaoSemEpi(): passos internos
    // -----------------------------------------------------------------

    /**
     * EPIs obrigatórios desta OS marcados como não usados, com a justificativa
     * dada.
     *
     * @return array<int, array{epi: string, texto: string}>
     */
    private function faltasObrigatorias(WorkOrder $os): array
    {
        $obrigatorios = $this->episExigidos($os, somenteObrigatorios: true);

        if ($obrigatorios === []) {
            return [];
        }

        return WorkOrderPpeConfirmation::query()
            ->where('work_order_id', $os->getKey())
            ->whereIn('personal_protective_equipment_id', $obrigatorios)
            ->where('confirmado', false)
            ->with('personalProtectiveEquipment:id,nome,tipo')
            ->orderBy('personal_protective_equipment_id')
            ->get()
            ->map(function (WorkOrderPpeConfirmation $confirmacao): array {
                $nome = $this->nomeDoEpi($confirmacao->personalProtectiveEquipment);
                $motivo = trim((string) $confirmacao->justificativa);

                return [
                    'epi' => $nome,
                    // Justificativa vazia não deveria existir (a regra está em
                    // `normalizar()`), mas linha antiga ou importada não pode
                    // sair como um travessão solto no e-mail do gestor.
                    'texto' => $nome.': '.($motivo === '' ? 'sem motivo informado' : $motivo),
                ];
            })
            ->all();
    }

    /**
     * Nome do EPI para o texto do aviso, com o tipo entre parênteses.
     *
     * O rótulo vem de `PersonalProtectiveEquipment::ROTULOS_DE_TIPO`, e não de
     * uma tabela própria daqui: o vocabulário do banco (sem acento e com
     * sublinhado) não é o que a pessoa lê, e cópias separadas já divergiram uma
     * vez neste módulo.
     */
    private function nomeDoEpi(?PersonalProtectiveEquipment $epi): string
    {
        if ($epi === null) {
            return 'EPI não identificado';
        }

        $tipo = PersonalProtectiveEquipment::ROTULOS_DE_TIPO[$epi->tipo] ?? $epi->tipo;

        return $tipo === null || $tipo === '' ? (string) $epi->nome : $epi->nome.' ('.$tipo.')';
    }

    /**
     * @param  array<int, string>  $itens
     */
    private function emLista(array $itens): string
    {
        return implode('; ', $itens);
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * A OS é do tenant corrente.
     *
     * O escopo global de `BelongsToCompany` já impede alcançar a OS de outra
     * empresa por consulta, e os EPIs só entram pela lista de exigências, que
     * também é escopada. Esta é a segunda tranca do mesmo portão, no formato de
     * `FichaDeEpiService::empresaEmitente()`: registrar confirmação de EPI numa
     * OS de outra empresa é a pior falha possível deste sistema, e falhar alto é
     * o comportamento desejado.
     *
     * Sem tenant resolvido (comando artisan, job, seeder, teste) não há o que
     * comparar, e o vínculo entre OS e EPI continua garantido pela consulta de
     * exigências, que parte da própria OS.
     */
    private function garantirOsDoTenant(WorkOrder $os): void
    {
        $tenant = TenantAtual::id();

        if ($tenant === null || (int) $os->company_id === $tenant) {
            return;
        }

        throw new RuntimeException(
            "A ordem de serviço {$os->getKey()} não pertence à empresa {$tenant}, "
            .'e a confirmação de EPI dela não pode ser registrada neste contexto.'
        );
    }

    /**
     * Instante enviado pelo aparelho do técnico.
     *
     * Valor ilegível vira nulo em vez de exceção: a hora do registro é
     * informação de apoio, e recusar a confirmação inteira por causa do formato
     * de uma data jogaria fora o fato — que o técnico usou (ou não usou) o EPI —,
     * que é o que a NR-6 e a RDC 622/2022 cobram.
     */
    private function instante(mixed $valor): ?Carbon
    {
        if (blank($valor)) {
            return null;
        }

        if ($valor instanceof Carbon) {
            return $valor;
        }

        try {
            return Carbon::parse($valor);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A exceção é a violação da unique composta de
     * `work_order_ppe_confirmations`?
     *
     * Mesmo critério de `NotificationService::violacaoDaChaveDeIdempotencia()`:
     * o código SQLSTATE 23000 cobre toda violação de restrição de integridade, e
     * é preciso separar a unique do resto. Sem essa separação, um erro de chave
     * estrangeira viraria "reenvio" e o problema real sumiria em silêncio.
     *
     * Duas formas de mensagem porque duas são possíveis: o MySQL nomeia o índice
     * ("Duplicate entry ... for key '<índice>'") e o SQLite lista as colunas
     * ("UNIQUE constraint failed: <tabela>.<coluna>, ...").
     */
    private function violacaoDaUnique(QueryException $excecao): bool
    {
        if ((string) $excecao->getCode() !== '23000') {
            return false;
        }

        $mensagem = $excecao->getMessage();

        return str_contains($mensagem, 'work_order_ppe_confirmations_company_work_order_ppe_unique')
            || (str_contains($mensagem, 'UNIQUE constraint failed')
                && str_contains($mensagem, 'work_order_ppe_confirmations'));
    }
}
