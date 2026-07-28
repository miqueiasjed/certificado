<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SyncBatchRequest;
use App\Http\Requests\Api\SyncConflitoResolverRequest;
use App\Http\Requests\Api\SyncFotoRequest;
use App\Models\SyncConflict;
use App\Models\SyncOperation;
use App\Models\User;
use App\Models\WorkOrderPhoto;
use App\Services\AppSessionService;
use App\Services\AppSyncService;
use App\Services\Sync\ResultadoDeSincronizacao;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sincronização em lote e envio de foto do aplicativo do técnico (Plano 12,
 * Task 12.5).
 *
 * Controller fino: valida via FormRequest, chama `AppSyncService` (Task 12.4)
 * operação a operação e traduz cada resultado em resposta JSON. Nenhuma regra
 * de domínio mora aqui: idempotência, conflito e recusa são decisões do
 * Service, e não deste controller.
 */
class AppSyncUploadController extends Controller
{
    public function __construct(
        private readonly AppSyncService $appSyncService,
        private readonly AppSessionService $appSessionService
    ) {}

    /**
     * `POST /api/app/sincronizar`. Recebe até 50 operações (validado por
     * `SyncBatchRequest`) e aplica cada uma isoladamente: uma falha não
     * derruba o lote, e a resposta é sempre 200 quando o lote foi processado,
     * mesmo com operações em conflito ou recusadas. Erro de transporte é o
     * único caso de 4xx/5xx (a validação do próprio FormRequest, e o limite
     * de taxa).
     */
    public function sincronizar(SyncBatchRequest $request): JsonResponse
    {
        $usuario = $request->user();
        $operacoes = $request->validated()['operacoes'];

        $resultados = array_map(
            fn (array $operacaoBruta) => $this->aplicarOperacaoDoLote($operacaoBruta, $usuario),
            $operacoes
        );

        $this->renovarToken($usuario);

        return response()->json(['resultados' => $resultados]);
    }

    /**
     * `POST /api/app/fotos`. `multipart/form-data` com o arquivo em requisição
     * própria, nunca embutido em base64 no lote. A gravação de fato é
     * delegada ao `AplicadorDeFoto` da Task 12.4, pelo mesmo
     * `AppSyncService::aplicar()` do lote (tipo `foto`), para reaproveitar a
     * idempotência e a transação já implementadas lá.
     *
     * O arquivo só é gravado em disco quando a operação ainda não existe:
     * reenviar o mesmo `uuid` nunca grava um segundo arquivo órfão, porque o
     * Service devolve o resultado da primeira aplicação sem sequer olhar o
     * `payload` desta chamada.
     */
    public function fotos(SyncFotoRequest $request): JsonResponse
    {
        $usuario = $request->user();
        $dados = $request->validated();
        $uuid = (string) $dados['uuid'];
        $workOrderId = (int) $dados['work_order_id'];

        $jaExistia = SyncOperation::where('operacao_uuid', $uuid)->exists();

        $path = $jaExistia ? '' : $request->file('foto')->store(
            "work-orders/{$workOrderId}/photos",
            'public'
        );

        $operacao = [
            'uuid' => $uuid,
            'tipo' => 'foto',
            'work_order_id' => $workOrderId,
            'registrada_em' => $dados['registrada_em'] ?? now(),
            'updated_at_conhecido' => $dados['updated_at_conhecido'] ?? null,
            'payload' => [
                'entity_type' => $dados['entity_type'],
                'entity_id' => $dados['entity_id'] ?? null,
                'room_id' => $dados['room_id'] ?? null,
                'path' => $path,
                'caption' => $dados['legenda'] ?? null,
            ],
        ];

        $resultado = $this->appSyncService->aplicar($operacao, $usuario);

        $this->renovarToken($usuario);

        $corpo = $this->paraResposta($uuid, $resultado, $jaExistia);

        if ($resultado->registro instanceof WorkOrderPhoto) {
            $corpo['foto'] = [
                'id' => $resultado->registro->id,
                'url' => $resultado->registro->url,
                'caption' => $resultado->registro->caption,
            ];
        }

        return response()->json($corpo);
    }

    /**
     * `GET /api/app/conflitos`. Lista os conflitos abertos visíveis ao
     * usuário autenticado: escopados à empresa pelo escopo global de
     * `BelongsToCompany`, e ao próprio técnico quando o usuário tem o papel
     * `tecnico` e não é administrador (mesmo critério de
     * `WorkOrderAccessService`: o escopo por empresa não separa um técnico do
     * outro).
     */
    public function conflitos(Request $request): JsonResponse
    {
        $usuario = $request->user();

        $consulta = SyncConflict::query()
            ->where('situacao', 'aberto')
            ->orderByDesc('id');

        if ($this->restritoAoProprioTecnico($usuario)) {
            $consulta->whereHas('syncOperation', function (Builder $query) use ($usuario): void {
                $query->where('user_id', $usuario->id);
            });
        }

        $conflitos = $consulta->get()->map(fn (SyncConflict $conflito) => $this->paraRespostaDeConflito($conflito));

        $this->renovarToken($usuario);

        return response()->json(['conflitos' => $conflitos->values()]);
    }

    /**
     * `POST /api/app/conflitos/{conflito}/resolver`. Aplica a escolha
     * (`aplicativo` ou `servidor`) através de `AppSyncService::resolver()`.
     *
     * `{conflito}` é route-model binding de `SyncConflict`: o escopo global
     * por empresa já barra o conflito de outro tenant com 404. Dentro da
     * mesma empresa, o técnico (não administrador) só resolve o próprio
     * conflito, pelo mesmo critério de `conflitos()`.
     */
    public function resolverConflito(SyncConflitoResolverRequest $request, SyncConflict $conflito): JsonResponse
    {
        $usuario = $request->user();

        if ($this->restritoAoProprioTecnico($usuario) && $conflito->syncOperation->user_id !== $usuario->id) {
            return response()->json([
                'message' => 'Você não tem permissão para resolver este conflito.',
            ], 403);
        }

        $conflitoResolvido = $this->appSyncService->resolver(
            $conflito,
            $request->validated()['escolha'],
            $usuario
        );

        $this->renovarToken($usuario);

        return response()->json(['conflito' => $this->paraRespostaDeConflito($conflitoResolvido)]);
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * Aplica uma operação do lote e traduz o desfecho em array de resposta.
     *
     * Envolvida em `try/catch` própria: `AppSyncService::aplicar()` já não
     * lança para fora dos casos mapeados como conflito ou recusa, mas esta
     * é a última linha de defesa contra qualquer falha inesperada em uma
     * operação (payload malformado, por exemplo) não derrubar as 49 outras
     * do mesmo lote.
     */
    private function aplicarOperacaoDoLote(array $operacaoBruta, User $usuario): array
    {
        $uuid = (string) $operacaoBruta['uuid'];

        try {
            $jaExistia = SyncOperation::where('operacao_uuid', $uuid)->exists();

            $operacao = [
                'uuid' => $uuid,
                'tipo' => (string) $operacaoBruta['tipo'],
                'work_order_id' => $operacaoBruta['work_order_id'] ?? null,
                'registrada_em' => $operacaoBruta['registrada_em'],
                'updated_at_conhecido' => $operacaoBruta['updated_at_conhecido'] ?? null,
                'payload' => $operacaoBruta['payload'],
            ];

            $resultado = $this->appSyncService->aplicar($operacao, $usuario);

            return $this->paraResposta($uuid, $resultado, $jaExistia);
        } catch (Throwable $falha) {
            Log::error('Falha inesperada ao aplicar uma operação de sincronização do aplicativo.', [
                'uuid' => $uuid,
                'excecao' => $falha::class,
                'mensagem' => $falha->getMessage(),
            ]);

            return [
                'uuid' => $uuid,
                'situacao' => 'recusada',
                'mensagem' => 'Não foi possível processar esta operação agora. Ela continua pendente e será reenviada.',
            ];
        }
    }

    /**
     * Traduz um `ResultadoDeSincronizacao` em array de resposta por uuid.
     *
     * `duplicada` é uma leitura própria deste controller, não do Service: uma
     * operação já aplicada antes e reenviada agora continua `foiAplicada()`
     * (o Service é idempotente), mas o aplicativo precisa de um sinal
     * diferente de "aplicada agora" para saber que pode limpar a fila local
     * sem se preocupar em conferir de novo. `$jaExistia`, apurado antes de
     * chamar `aplicar()`, é o que distingue os dois casos.
     */
    private function paraResposta(string $uuid, ResultadoDeSincronizacao $resultado, bool $jaExistia): array
    {
        if ($resultado->foiAplicada()) {
            return [
                'uuid' => $uuid,
                'situacao' => $jaExistia ? 'duplicada' : 'aplicada',
            ];
        }

        if ($resultado->estaEmConflito()) {
            return [
                'uuid' => $uuid,
                'situacao' => 'conflito',
                'conflito_id' => $resultado->conflito?->id,
                'motivo' => $resultado->conflito?->motivo,
                'mensagem' => $resultado->mensagem,
            ];
        }

        return [
            'uuid' => $uuid,
            'situacao' => 'recusada',
            'mensagem' => $resultado->mensagem,
        ];
    }

    private function paraRespostaDeConflito(SyncConflict $conflito): array
    {
        return [
            'id' => $conflito->id,
            'work_order_id' => $conflito->work_order_id,
            'motivo' => $conflito->motivo,
            'valor_do_aplicativo' => $conflito->valor_do_aplicativo,
            'valor_do_servidor' => $conflito->valor_do_servidor,
            'situacao' => $conflito->situacao,
            'resolvido_por' => $conflito->resolvido_por,
            'resolvido_em' => $conflito->resolvido_em,
            'criado_em' => $conflito->created_at,
        ];
    }

    /**
     * Só filtra quem tem o papel `tecnico` e não tem o papel `administrador`,
     * mesmo critério de `WorkOrderAccessService::precisaDeFiltro()`.
     */
    private function restritoAoProprioTecnico(User $usuario): bool
    {
        if ($usuario->hasRole('administrador')) {
            return false;
        }

        return $usuario->hasRole('tecnico');
    }

    /**
     * Toda requisição autenticada bem-sucedida do aplicativo renova a
     * validade do token, para o técnico ativo nunca ser deslogado no meio do
     * dia (`AppSessionService::renovar()`), mesmo padrão já aplicado pela
     * carga do dia (Task 12.3).
     */
    private function renovarToken(User $usuario): void
    {
        $token = $usuario->currentAccessToken();

        if ($token !== null) {
            $this->appSessionService->renovar($token);
        }
    }
}
