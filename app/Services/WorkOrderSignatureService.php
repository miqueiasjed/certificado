<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderSignature;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Coleta, recusa e correção justificada da assinatura do cliente em uma
 * ordem de serviço (Plano 13, Task 13.3).
 *
 * Três operações, cada uma com um efeito próprio sobre o travamento:
 *
 * - `coletar()` grava a assinatura e trava a OS (`situacao_assinatura`
 *   `assinada`), exceto quando a OS já estava assinada: aí a operação vira
 *   recoleta, tratada como correção justificada (ver docblock do método).
 * - `registrarRecusa()` marca a recusa como situação própria. Não trava:
 *   o serviço foi prestado e o escritório ainda precisa fechar o documento.
 * - `corrigirComJustificativa()` é a única porta de alteração de uma OS já
 *   assinada, e por isso é o mecanismo que a recoleta reaproveita.
 *
 * Decisão de design: o travamento de `WorkOrder` é aplicado pelos métodos de
 * atualização do `WorkOrderService` (guarda contra o "caminho comum"), não
 * por este Service. Este Service nunca chama `WorkOrderService` para gravar
 * os próprios campos da assinatura (`$os->update([...])` direto no Model),
 * porque a guarda do `WorkOrderService` protege apenas os métodos dele, não
 * o Model. A única ponte com aquela guarda é `WorkOrderService::comTravamentoIgnorado()`,
 * usada em `corrigirComJustificativa()` para permitir que o *callback* do
 * chamador (que pode, esse sim, invocar `WorkOrderService::updateWorkOrder()`
 * para alterar outros campos da OS) não esbarre na própria trava.
 */
class WorkOrderSignatureService
{
    /**
     * Tamanho mínimo da justificativa de uma correção em OS já assinada.
     * "Ajuste" e "erro" não explicam nada a quem for auditar depois.
     */
    private const JUSTIFICATIVA_MINIMA = 20;

    /**
     * Único status de `work_orders.status` que libera a coleta da assinatura.
     */
    private const STATUS_QUE_LIBERA_ASSINATURA = 'completed';

    /**
     * Permissão exigida para corrigir (ou recoletar) uma OS já assinada.
     * Fora do padrão "recurso-acao" das demais permissões do catálogo, de
     * propósito: nome definido no Plano 13 para a família de permissões da
     * assinatura em campo (ver `App\Console\Commands\SyncPermissions`).
     */
    public const PERMISSAO_CORRECAO = 'os.corrigir_assinada';

    /**
     * Coleta a assinatura do cliente para a OS informada.
     *
     * `$dados` aceita: `assinante_nome` (obrigatório), `assinante_documento`
     * (opcional), `assinante_vinculo` (opcional), `imagem` (obrigatório, PNG em
     * base64 do canvas, com ou sem o prefixo `data:image/png;base64,`),
     * `coletada_em` (opcional, cai em `now()` quando ausente), `latitude`,
     * `longitude` e `precisao_metros` (opcionais, só gravados quando vierem
     * preenchidos: o servidor nunca infere localização por IP) e `origem`
     * (`aplicativo` ou `web`, cai em `aplicativo` quando ausente).
     *
     * Exige que a OS esteja concluída (`status === 'completed'`), grava a
     * imagem em arquivo (nunca base64 em coluna), cria a `WorkOrderSignature`
     * e marca `situacao_assinatura = assinada`/`assinada_em`, tudo em uma
     * única transação.
     *
     * Decisão de design (recoleta): chamar `coletar()` de novo em uma OS que
     * já está `assinada` (`work_order_signatures.work_order_id` é unique, uma
     * linha por OS) é tratado como correção justificada, e não como uma nova
     * coleta livre. A spec desta task deixa a assinatura de `coletar()` com
     * três parâmetros, então o quarto parâmetro (`$justificativa`) é opcional
     * e só é exigido nesse caminho: quando a OS já está assinada, esta chamada
     * delega inteira para `corrigirComJustificativa()`, que exige a permissão
     * `os.corrigir_assinada` e a justificativa de 20 caracteres antes de
     * sobrescrever a linha existente. A assinatura anterior fica preservada na
     * auditoria pela própria trait `Auditavel` de `WorkOrderSignature` (o
     * `update()` da linha existente dispara o evento `updated` com o antes e o
     * depois dos campos da assinatura), e a justificativa em si entra em um
     * registro próprio de `audit_logs`, gravado por `corrigirComJustificativa()`.
     * Sem uma justificativa de 20 caracteres, a recoleta é recusada com o
     * mesmo erro de validação de uma correção comum.
     *
     * @throws ValidationException Quando a OS não está concluída, quando faltam
     *                             dados obrigatórios da assinatura ou (na
     *                             recoleta) quando a justificativa é curta.
     * @throws AuthorizationException Na recoleta, quando o usuário não tem a
     *                                permissão `os.corrigir_assinada`.
     */
    public function coletar(WorkOrder $os, array $dados, User $usuario, ?string $justificativa = null): WorkOrderSignature
    {
        if ($os->situacao_assinatura === 'assinada') {
            return $this->recoletar($os, $dados, $usuario, $justificativa);
        }

        $this->garantirOsConcluida($os);

        return DB::transaction(fn () => $this->gravarAssinatura($os, $dados, $usuario));
    }

    /**
     * Registra a recusa do cliente em assinar.
     *
     * Recusa é situação própria, nunca inferida pela ausência de assinatura:
     * as duas coisas significam coisas diferentes na hora de cobrar o
     * serviço. Por isso ela NÃO chama `estaTravada()`/não altera
     * `situacao_assinatura` para nada que trave a OS: o serviço foi prestado
     * e o escritório ainda precisa fechar o documento normalmente.
     *
     * @throws ValidationException Quando o motivo vem vazio.
     */
    public function registrarRecusa(WorkOrder $os, string $motivo, User $usuario): WorkOrder
    {
        $motivo = trim($motivo);

        if ($motivo === '') {
            throw ValidationException::withMessages([
                'motivo' => 'Informe o motivo da recusa de assinatura.',
            ]);
        }

        $os->update([
            'situacao_assinatura' => 'recusada',
            'recusa_motivo' => $motivo,
            'recusa_registrada_em' => now(),
        ]);

        return $os->refresh();
    }

    /**
     * Única porta de alteração de uma OS já assinada.
     *
     * `$alteracao` é o callback que faz a mudança de fato (por exemplo,
     * `fn (WorkOrder $os) => $workOrderService->updateWorkOrder($os, $dados)`
     * ou um `$os->update([...])` direto, montado por quem chama). Ele roda
     * dentro de `WorkOrderService::comTravamentoIgnorado()`, a única exceção
     * aceita à guarda de OS travada daquele Service: se o callback delegar a
     * `WorkOrderService::updateWorkOrder()` (ou a outro método guardado), a
     * chamada passa mesmo com a OS assinada; um `$os->update()` direto no
     * Model nunca esbarraria na guarda de qualquer forma, porque ela vive nos
     * métodos do Service, não no Model.
     *
     * Exige a permissão `os.corrigir_assinada` e justificativa de no mínimo
     * 20 caracteres. Grava em `audit_logs` o antes e o depois dos campos da
     * OS que o callback alterou, quem corrigiu e a justificativa (dentro de
     * `valores_depois`, sob a chave `justificativa`: reaproveita a mesma
     * exibição genérica de `AuditoriaService::descreverAlteracao()` sem
     * precisar de coluna nova em `audit_logs`).
     *
     * Tudo dentro de uma transação: se o callback falhar, nada é gravado, nem
     * a alteração nem o registro de auditoria.
     *
     * @param  callable(WorkOrder): mixed  $alteracao
     * @return mixed O valor devolvido por `$alteracao`.
     *
     * @throws AuthorizationException Quando o usuário não tem `os.corrigir_assinada`.
     * @throws ValidationException Quando a justificativa tem menos de 20 caracteres.
     */
    public function corrigirComJustificativa(WorkOrder $os, callable $alteracao, string $justificativa, User $usuario): mixed
    {
        $this->garantirPermissaoDeCorrecao($usuario);
        $this->garantirJustificativaValida($justificativa);

        return DB::transaction(function () use ($os, $alteracao, $justificativa, $usuario) {
            $antes = $os->atributosAuditaveis();

            $resultado = WorkOrderService::comTravamentoIgnorado(fn () => $alteracao($os));

            $os->refresh();
            $depois = $os->atributosAuditaveis();

            $this->registrarCorrecaoNaAuditoria($os, $antes, $depois, $justificativa, $usuario);

            return $resultado;
        });
    }

    // -----------------------------------------------------------------
    // coletar(): passos internos
    // -----------------------------------------------------------------

    /**
     * Recoleta: mesma gravação de `gravarAssinatura()`, só que passando pelo
     * mecanismo de correção justificada, documentado no docblock de `coletar()`.
     */
    private function recoletar(WorkOrder $os, array $dados, User $usuario, ?string $justificativa): WorkOrderSignature
    {
        return $this->corrigirComJustificativa(
            $os,
            fn (WorkOrder $os) => $this->gravarAssinatura($os, $dados, $usuario),
            (string) $justificativa,
            $usuario
        );
    }

    private function garantirOsConcluida(WorkOrder $os): void
    {
        if ($os->status !== self::STATUS_QUE_LIBERA_ASSINATURA) {
            throw ValidationException::withMessages([
                'status' => 'A assinatura só pode ser coletada depois que a ordem de serviço estiver concluída.',
            ]);
        }
    }

    /**
     * Grava a imagem em arquivo, cria ou atualiza a `WorkOrderSignature`
     * (unique em `work_order_id`: `updateOrCreate` nunca insere uma segunda
     * linha) e marca a OS como assinada.
     *
     * Chamada tanto pela primeira coleta quanto pela recoleta (via
     * `corrigirComJustificativa()`): nos dois casos o efeito sobre os campos
     * é o mesmo, e a trait `Auditavel` de `WorkOrderSignature` só grava um
     * novo registro em `audit_logs` quando algum campo realmente muda.
     */
    private function gravarAssinatura(WorkOrder $os, array $dados, User $usuario): WorkOrderSignature
    {
        $this->garantirDadosObrigatorios($dados);

        $imagemPath = $this->gravarImagem($os, (string) $dados['imagem']);
        $coletadaEm = $dados['coletada_em'] ?? now();

        $atributos = [
            'assinante_nome' => trim((string) $dados['assinante_nome']),
            'assinante_documento' => $dados['assinante_documento'] ?? null,
            'assinante_vinculo' => $dados['assinante_vinculo'] ?? null,
            'imagem_path' => $imagemPath,
            'coletada_em' => $coletadaEm,
            'user_id' => $usuario->id,
            'origem' => $dados['origem'] ?? 'aplicativo',
        ];

        // Coordenada só entra quando vier preenchida. O servidor nunca infere
        // localização por IP para completar o campo.
        foreach (['latitude', 'longitude', 'precisao_metros'] as $campoDeCoordenada) {
            if (array_key_exists($campoDeCoordenada, $dados) && $dados[$campoDeCoordenada] !== null) {
                $atributos[$campoDeCoordenada] = $dados[$campoDeCoordenada];
            }
        }

        $assinatura = WorkOrderSignature::updateOrCreate(
            ['work_order_id' => $os->id],
            $atributos
        );

        $os->update([
            'situacao_assinatura' => 'assinada',
            'assinada_em' => $assinatura->coletada_em,
        ]);

        return $assinatura;
    }

    private function garantirDadosObrigatorios(array $dados): void
    {
        if (trim((string) ($dados['assinante_nome'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'assinante_nome' => 'Informe o nome de quem está assinando.',
            ]);
        }

        if (trim((string) ($dados['imagem'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'imagem' => 'A imagem da assinatura é obrigatória.',
            ]);
        }
    }

    /**
     * Decodifica o PNG em base64 do canvas e grava em disco. Nunca guarda
     * base64 em coluna: só o caminho do arquivo vai para `imagem_path`.
     */
    private function gravarImagem(WorkOrder $os, string $imagemBase64): string
    {
        $conteudo = $imagemBase64;

        if (str_starts_with($conteudo, 'data:') && str_contains($conteudo, ',')) {
            [, $conteudo] = explode(',', $conteudo, 2);
        }

        $binario = base64_decode($conteudo, true);

        if ($binario === false) {
            throw ValidationException::withMessages([
                'imagem' => 'A imagem da assinatura não é um base64 válido.',
            ]);
        }

        $path = "work-orders/{$os->id}/signatures/".(string) Str::uuid().'.png';

        Storage::disk('public')->put($path, $binario);

        return $path;
    }

    // -----------------------------------------------------------------
    // corrigirComJustificativa(): passos internos
    // -----------------------------------------------------------------

    private function garantirPermissaoDeCorrecao(User $usuario): void
    {
        if (! $usuario->can(self::PERMISSAO_CORRECAO)) {
            throw new AuthorizationException(
                'Você não tem permissão para corrigir uma ordem de serviço já assinada.'
            );
        }
    }

    private function garantirJustificativaValida(string $justificativa): void
    {
        if (mb_strlen(trim($justificativa)) < self::JUSTIFICATIVA_MINIMA) {
            throw ValidationException::withMessages([
                'justificativa' => sprintf(
                    'A justificativa da correção precisa ter pelo menos %d caracteres, para explicar de '
                    .'verdade o motivo a quem for auditar depois.',
                    self::JUSTIFICATIVA_MINIMA
                ),
            ]);
        }
    }

    /**
     * Grava, em `audit_logs`, apenas os campos da OS que mudaram entre
     * `$antes` e `$depois`, quem corrigiu e a justificativa. Mesmo padrão
     * manual de `AuditLog::create()` já usado por outros Services do projeto
     * (ex.: `AppSyncService::registrarResolucaoNaAuditoria()`), com `ip` e
     * `user_agent` nulos: esta gravação é deliberada, não decorre de uma
     * requisição HTTP específica, e o Service não deve depender do contexto
     * de requisição para ser testável.
     *
     * A justificativa entra dentro de `valores_depois`, sob a chave
     * `justificativa`: não existe coluna própria em `audit_logs` para isso, e
     * colocá-la ali faz `AuditoriaService::descreverAlteracao()` já exibi-la
     * como mais um campo alterado, sem precisar de UI nova.
     */
    private function registrarCorrecaoNaAuditoria(WorkOrder $os, array $antes, array $depois, string $justificativa, User $usuario): void
    {
        $camposAlterados = array_keys(array_diff_assoc($depois, $antes));

        $antesFiltrado = array_intersect_key($antes, array_flip($camposAlterados));
        $depoisFiltrado = array_intersect_key($depois, array_flip($camposAlterados));

        AuditLog::create([
            'auditable_type' => $os->getMorphClass(),
            'auditable_id' => $os->id,
            'acao' => 'correcao_justificada',
            'user_id' => $usuario->id,
            'autor_nome' => $this->nomeDoAutor($usuario),
            'valores_antes' => $antesFiltrado,
            'valores_depois' => array_merge($depoisFiltrado, ['justificativa' => trim($justificativa)]),
            'ip' => null,
            'user_agent' => null,
        ]);
    }

    private function nomeDoAutor(User $usuario): string
    {
        $nome = trim((string) ($usuario->name ?? ''));

        return $nome !== '' ? $nome : 'Usuário #'.$usuario->id;
    }
}
