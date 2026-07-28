<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\SyncConflict;
use App\Models\SyncOperation;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Sync\AplicadorDeFoto;
use App\Services\Sync\AplicadorDeOperacao;
use App\Services\Sync\ResultadoDeSincronizacao;
use App\Support\TenantAtual;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Aplica, de forma idempotente, as operações que o aplicativo do técnico
 * sincroniza com o servidor, e transforma qualquer divergência em conflito
 * registrado (Plano 12, Task 12.4).
 *
 * Duas garantias sustentam este Service:
 *
 * 1. **Idempotência pelo `operacao_uuid`.** Reenviar a mesma operação (porque
 *    a resposta se perdeu na rede) nunca reaplica nem duplica o efeito dela:
 *    devolve o mesmo `ResultadoDeSincronizacao` da primeira aplicação.
 * 2. **Conflito nunca descarta o trabalho do técnico.** Divergência entre o
 *    que o aplicativo enviou e o estado atual do servidor vira um
 *    `sync_conflict` aberto, com o valor do técnico preservado em
 *    `valor_do_aplicativo`, nunca um erro genérico nem uma perda silenciosa.
 *
 * Cada tipo de operação (`sync_operations.tipo`) tem um aplicador próprio, que
 * implementa `AplicadorDeOperacao` e é resolvido pelo mapa `$aplicadores`.
 * Nesta task só `foto` está implementado; os aplicadores de evento de
 * dispositivo, avistamento, adequação e conclusão de OS entram no Plano 13,
 * cada um somando a própria entrada a este construtor e ao mapa.
 *
 * A ordem de aplicação das operações do mesmo aparelho (pela `registrada_em`)
 * e o isolamento entre falhas de operações diferentes são responsabilidade de
 * quem chama `aplicar()` em lote (Task 12.5): este Service processa uma
 * operação por vez e nunca lança para fora dos casos já mapeados como
 * conflito ou recusa, então uma chamada nunca impede a seguinte.
 */
class AppSyncService
{
    /**
     * Escolhas aceitas por `resolver()`.
     */
    private const ESCOLHAS_VALIDAS = ['aplicativo', 'servidor'];

    /**
     * Aplicador por tipo de operação.
     *
     * @var array<string, AplicadorDeOperacao>
     */
    private array $aplicadores;

    public function __construct(
        private readonly WorkOrderAccessService $workOrderAccessService,
        AplicadorDeFoto $aplicadorDeFoto,
    ) {
        $this->aplicadores = [
            $aplicadorDeFoto->tipo() => $aplicadorDeFoto,
        ];
    }

    /**
     * Aplica uma operação enviada pelo aplicativo.
     *
     * `$operacao` traz `uuid`, `tipo`, `work_order_id` (nullable), `payload`,
     * `registrada_em` e `updated_at_conhecido` (nullable: o `updated_at` da OS
     * que o aplicativo tinha na última carga).
     *
     * Em transação: se o `operacao_uuid` já existe, devolve o resultado da
     * primeira aplicação sem reprocessar nada; senão grava a operação,
     * valida o acesso do usuário à OS, compara o `updated_at`, delega ao
     * aplicador do tipo e marca o desfecho.
     */
    public function aplicar(array $operacao, User $usuario): ResultadoDeSincronizacao
    {
        $empresa = (int) $usuario->company_id;

        return TenantAtual::comTenant(
            $empresa,
            fn () => DB::transaction(fn () => $this->aplicarDentroDaTransacao($operacao, $usuario))
        );
    }

    /**
     * Resolve um conflito aberto, aplicando o lado escolhido.
     *
     * `aplicativo` aplica o valor guardado em `valor_do_aplicativo` através
     * do aplicador do tipo, e marca a `sync_operation` como aplicada.
     * `servidor` mantém o estado atual do servidor, sem tocar em nada além do
     * próprio conflito. Nos dois casos, quem resolveu e quando ficam
     * gravados no conflito, e o evento vai para `audit_logs` (Plano 3).
     *
     * Conflito que não está mais `aberto` é devolvido como está, sem
     * reprocessar: resolver duas vezes o mesmo conflito não é erro, é
     * idempotente.
     */
    public function resolver(SyncConflict $conflito, string $escolha, User $usuario): SyncConflict
    {
        if (! in_array($escolha, self::ESCOLHAS_VALIDAS, true)) {
            throw new InvalidArgumentException(
                "Escolha de resolução de conflito inválida: \"{$escolha}\". Use \"aplicativo\" ou \"servidor\"."
            );
        }

        if ($conflito->situacao !== 'aberto') {
            return $conflito;
        }

        $empresa = (int) $usuario->company_id;

        return TenantAtual::comTenant($empresa, function () use ($conflito, $escolha, $usuario) {
            return DB::transaction(function () use ($conflito, $escolha, $usuario) {
                if ($escolha === 'aplicativo') {
                    $this->aplicarLadoDoAplicativo($conflito, $usuario);
                }

                $conflito->update([
                    'situacao' => $escolha === 'aplicativo' ? 'resolvido_aplicativo' : 'resolvido_servidor',
                    'resolvido_por' => $usuario->id,
                    'resolvido_em' => now(),
                ]);

                $this->registrarResolucaoNaAuditoria($conflito, $escolha, $usuario);

                return $conflito->fresh();
            });
        });
    }

    // -----------------------------------------------------------------
    // aplicar(): passos internos
    // -----------------------------------------------------------------

    private function aplicarDentroDaTransacao(array $operacao, User $usuario): ResultadoDeSincronizacao
    {
        $uuid = (string) ($operacao['uuid'] ?? '');

        $existente = SyncOperation::where('operacao_uuid', $uuid)->first();

        if ($existente !== null) {
            return $this->resultadoDaOperacaoExistente($existente);
        }

        $workOrderId = $operacao['work_order_id'] ?? null;

        // Resolvida antes de gravar a sync_operation: `work_order_id` da
        // tabela é uma FK de verdade, e uma OS removida (ou de outro tenant,
        // pelo escopo global) não pode ir parar lá, sob pena de a própria
        // inserção falhar por violação de integridade. O id original pedido
        // continua preservado dentro do payload guardado no conflito.
        $workOrder = $workOrderId !== null ? WorkOrder::find($workOrderId) : null;

        // Grava a operação como recebida. `situacao` é atualizada para o
        // desfecho final (aplicada, conflito ou recusada) antes do fim desta
        // mesma transação, então "recebida" nunca fica visível fora dela.
        $syncOperation = SyncOperation::create([
            'user_id' => $usuario->id,
            'operacao_uuid' => $uuid,
            'tipo' => (string) ($operacao['tipo'] ?? ''),
            'work_order_id' => $workOrder?->id,
            'payload' => $operacao['payload'] ?? [],
            'situacao' => 'recebida',
            'registrada_em' => $operacao['registrada_em'] ?? now(),
        ]);

        if ($workOrderId !== null && $workOrder === null) {
            return $this->registrarConflito(
                $syncOperation,
                'registro_removido',
                'A ordem de serviço desta operação não existe mais ou foi removida.',
                $operacao,
                null
            );
        }

        return $this->processar($syncOperation, $operacao, $usuario, $workOrder);
    }

    private function processar(
        SyncOperation $syncOperation,
        array $operacao,
        User $usuario,
        ?WorkOrder $workOrder
    ): ResultadoDeSincronizacao {
        if ($workOrder !== null) {
            try {
                $this->workOrderAccessService->garantirAcesso($workOrder, $usuario);
            } catch (AuthorizationException $excecao) {
                return $this->recusar($syncOperation, $excecao->getMessage());
            }

            if ($this->osEstaTravada($workOrder)) {
                return $this->registrarConflito(
                    $syncOperation,
                    'os_travada',
                    'Esta ordem de serviço já foi assinada e não aceita mais alterações de campo.',
                    $operacao,
                    $workOrder
                );
            }

            $updatedAtConhecido = $this->paraInstante($operacao['updated_at_conhecido'] ?? null);

            if ($updatedAtConhecido !== null
                && $workOrder->updated_at !== null
                && $workOrder->updated_at->gt($updatedAtConhecido)
            ) {
                return $this->registrarConflito(
                    $syncOperation,
                    'registro_alterado',
                    'Esta ordem de serviço foi alterada por outra pessoa depois que você começou a registrar esta informação.',
                    $operacao,
                    $workOrder
                );
            }
        }

        $aplicador = $this->aplicadores[$syncOperation->tipo] ?? null;

        if ($aplicador === null) {
            return $this->recusar(
                $syncOperation,
                "O tipo de operação \"{$syncOperation->tipo}\" ainda não é suportado por esta versão do sistema."
            );
        }

        $payload = is_array($syncOperation->payload) ? $syncOperation->payload : [];

        if ($workOrder !== null) {
            $payload['work_order_id'] = $workOrder->id;
        }

        try {
            $registro = $aplicador->aplicar($payload, $usuario);
        } catch (ValidationException $excecao) {
            return $this->registrarConflito(
                $syncOperation,
                'regra_de_negocio',
                $this->primeiraMensagemDeValidacao($excecao),
                $operacao,
                $workOrder
            );
        }

        $syncOperation->update([
            'situacao' => 'aplicada',
            'aplicada_em' => now(),
        ]);

        return ResultadoDeSincronizacao::aplicada($syncOperation->fresh(), $registro);
    }

    /**
     * Resultado de uma operação já processada antes, reconstruído a partir do
     * que ficou gravado. Nunca reaplica nada.
     */
    private function resultadoDaOperacaoExistente(SyncOperation $syncOperation): ResultadoDeSincronizacao
    {
        if ($syncOperation->situacao === 'conflito') {
            $conflito = $syncOperation->conflicts()->orderByDesc('id')->first();

            if ($conflito !== null) {
                return ResultadoDeSincronizacao::conflito($syncOperation, $conflito, (string) $syncOperation->erro);
            }
        }

        if ($syncOperation->situacao === 'recusada') {
            return ResultadoDeSincronizacao::recusada($syncOperation, (string) $syncOperation->erro);
        }

        return ResultadoDeSincronizacao::aplicada($syncOperation, null);
    }

    /**
     * A ordem de serviço já foi assinada pelo cliente e está travada para
     * alterações de campo?
     *
     * A coluna que vai marcar a assinatura ainda não existe: nasce no
     * Plano 13, junto com o fluxo de assinatura do técnico em campo. Este
     * método já fica pronto para aquele plano só trocar o corpo por uma
     * leitura real, sem espalhar o motivo `os_travada` por mais de um lugar
     * do código.
     */
    private function osEstaTravada(WorkOrder $workOrder): bool
    {
        return false;
    }

    private function registrarConflito(
        SyncOperation $syncOperation,
        string $motivo,
        string $mensagem,
        array $operacao,
        ?WorkOrder $workOrder
    ): ResultadoDeSincronizacao {
        $syncOperation->update([
            'situacao' => 'conflito',
            'erro' => $mensagem,
        ]);

        $conflito = SyncConflict::create([
            'sync_operation_id' => $syncOperation->id,
            'work_order_id' => $workOrder?->id,
            'motivo' => $motivo,
            'valor_do_aplicativo' => $this->valorDoAplicativo($operacao),
            'valor_do_servidor' => $this->valorDoServidor($workOrder),
            'situacao' => 'aberto',
        ]);

        return ResultadoDeSincronizacao::conflito($syncOperation->fresh(), $conflito, $mensagem);
    }

    private function recusar(SyncOperation $syncOperation, string $mensagem): ResultadoDeSincronizacao
    {
        $syncOperation->update([
            'situacao' => 'recusada',
            'erro' => $mensagem,
        ]);

        return ResultadoDeSincronizacao::recusada($syncOperation->fresh(), $mensagem);
    }

    /**
     * Retrato do que o técnico enviou, guardado até alguém decidir. Inclui o
     * `work_order_id` originalmente pedido mesmo quando ele não existe mais
     * (conflito `registro_removido`), para o histórico não perder qual OS o
     * técnico tinha em mãos.
     */
    private function valorDoAplicativo(array $operacao): array
    {
        $payload = $operacao['payload'] ?? [];

        return array_merge(
            ['work_order_id' => $operacao['work_order_id'] ?? null],
            is_array($payload) ? $payload : []
        );
    }

    /**
     * Retrato mínimo do estado do servidor no momento do conflito, para quem
     * for resolver entender o que mudou sem precisar abrir a OS à parte.
     */
    private function valorDoServidor(?WorkOrder $workOrder): array
    {
        if ($workOrder === null) {
            return [];
        }

        return [
            'work_order_id' => $workOrder->id,
            'status' => $workOrder->status,
            'updated_at' => $workOrder->updated_at?->toJSON(),
        ];
    }

    private function paraInstante(mixed $valor): ?Carbon
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        try {
            return Carbon::parse($valor);
        } catch (Throwable) {
            return null;
        }
    }

    private function primeiraMensagemDeValidacao(ValidationException $excecao): string
    {
        foreach ($excecao->errors() as $mensagens) {
            if (! empty($mensagens)) {
                return (string) $mensagens[0];
            }
        }

        return $excecao->getMessage();
    }

    // -----------------------------------------------------------------
    // resolver(): passos internos
    // -----------------------------------------------------------------

    private function aplicarLadoDoAplicativo(SyncConflict $conflito, User $usuario): void
    {
        if ($conflito->motivo === 'registro_removido') {
            throw new RuntimeException(
                'Não é possível aplicar o lado do aplicativo: a ordem de serviço ou o registro alvo '
                .'deste conflito não existe mais.'
            );
        }

        $syncOperation = $conflito->syncOperation;
        $aplicador = $this->aplicadores[$syncOperation->tipo] ?? null;

        if ($aplicador === null) {
            throw new RuntimeException(
                "Não há aplicador registrado para o tipo \"{$syncOperation->tipo}\", "
                .'e por isso o lado do aplicativo deste conflito não pode ser aplicado.'
            );
        }

        $payload = is_array($conflito->valor_do_aplicativo) ? $conflito->valor_do_aplicativo : [];

        if ($conflito->work_order_id !== null) {
            $payload['work_order_id'] = $conflito->work_order_id;
        }

        $aplicador->aplicar($payload, $usuario);

        $syncOperation->update([
            'situacao' => 'aplicada',
            'aplicada_em' => now(),
            'erro' => null,
        ]);
    }

    /**
     * Registra a resolução em `audit_logs` (Plano 3). Falha ao auditar nunca
     * derruba a resolução do conflito, mesmo critério já aplicado pela trait
     * `Auditavel`.
     */
    private function registrarResolucaoNaAuditoria(SyncConflict $conflito, string $escolha, User $usuario): void
    {
        try {
            AuditLog::create([
                'auditable_type' => SyncConflict::class,
                'auditable_id' => $conflito->id,
                'acao' => 'conflito_resolvido',
                'user_id' => $usuario->id,
                'autor_nome' => $this->nomeDoAutor($usuario),
                'valores_antes' => ['situacao' => 'aberto'],
                'valores_depois' => [
                    'situacao' => $conflito->situacao,
                    'escolha' => $escolha,
                ],
                'ip' => null,
                'user_agent' => null,
            ]);
        } catch (Throwable $falha) {
            Log::error('Falha ao registrar em auditoria a resolução de um conflito de sincronização.', [
                'sync_conflict_id' => $conflito->id,
                'erro' => $falha->getMessage(),
            ]);
        }
    }

    private function nomeDoAutor(User $usuario): string
    {
        $nome = trim((string) ($usuario->name ?? ''));

        return $nome !== '' ? $nome : 'Usuário #'.$usuario->id;
    }
}
