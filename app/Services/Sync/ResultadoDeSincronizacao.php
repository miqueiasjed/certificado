<?php

namespace App\Services\Sync;

use App\Models\SyncConflict;
use App\Models\SyncOperation;
use Illuminate\Database\Eloquent\Model;

/**
 * Resultado de `AppSyncService::aplicar()` (Plano 12, Task 12.4).
 *
 * É o mesmo objeto, com o mesmo conteúdo, tanto na primeira aplicação de uma
 * operação quanto em qualquer reenvio dela pelo mesmo `operacao_uuid`: é isso
 * que permite ao aplicativo reenviar sem medo quando a resposta original se
 * perde na rede, sem reaplicar nem duplicar o efeito da operação.
 *
 * `situacao` é sempre uma de três: `aplicada`, `conflito` ou `recusada`, no
 * mesmo catálogo de `sync_operations.situacao`.
 */
final class ResultadoDeSincronizacao
{
    /**
     * @param  array<int, array{item: string, rotulo: string, detalhe: string, exigencia: string}>  $avisos
     *                                                                                                       Informação que acompanha uma operação **aplicada com sucesso**
     *                                                                                                       (Plano 24, Task 24.4): hoje, o produto aplicado estar com registro
     *                                                                                                       vencido ou cancelado na Anvisa. Aviso nunca é recusa — quem precisa
     *                                                                                                       impedir a operação lança `ValidationException` do aplicador, que vira
     *                                                                                                       conflito. Sempre vazio em conflito e em recusa, onde não existe
     *                                                                                                       registro gravado sobre o qual avisar.
     */
    private function __construct(
        public readonly string $situacao,
        public readonly SyncOperation $operacao,
        public readonly ?SyncConflict $conflito,
        public readonly ?string $mensagem,
        public readonly ?Model $registro,
        public readonly array $avisos = [],
    ) {}

    /**
     * Operação aplicada com sucesso. `$registro` é o model criado ou alterado
     * pelo aplicador do tipo; fica `null` quando o resultado vem de um
     * reenvio (a operação já tinha sido aplicada antes, nesta ou em uma
     * chamada anterior do processo).
     *
     * `$avisos` chega dos aplicadores que implementam `AplicadorQueAvisa`, e é
     * vazio para todos os outros. No reenvio de uma operação já aplicada a
     * lista também sai vazia: o aplicador não roda de novo (é o que torna a
     * sincronização idempotente), então não há aviso novo a produzir, e o
     * aplicativo já recebeu o da primeira vez.
     *
     * @param  array<int, array{item: string, rotulo: string, detalhe: string, exigencia: string}>  $avisos
     */
    public static function aplicada(SyncOperation $operacao, ?Model $registro, array $avisos = []): self
    {
        return new self('aplicada', $operacao, null, null, $registro, $avisos);
    }

    /**
     * Operação em conflito: o valor do técnico fica guardado no
     * `sync_conflict`, aberto, para resolução manual.
     */
    public static function conflito(SyncOperation $operacao, SyncConflict $conflito, string $mensagem): self
    {
        return new self('conflito', $operacao, $conflito, $mensagem, null);
    }

    /**
     * Operação recusada sem gerar conflito: hoje só acontece quando o usuário
     * não tem acesso à ordem de serviço da operação. Diferente do conflito,
     * não há dois lados para reconciliar: não existe o que resolver.
     */
    public static function recusada(SyncOperation $operacao, string $mensagem): self
    {
        return new self('recusada', $operacao, null, $mensagem, null);
    }

    public function foiAplicada(): bool
    {
        return $this->situacao === 'aplicada';
    }

    public function estaEmConflito(): bool
    {
        return $this->situacao === 'conflito';
    }

    public function foiRecusada(): bool
    {
        return $this->situacao === 'recusada';
    }
}
