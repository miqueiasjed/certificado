<?php

namespace App\Services\Sync;

/**
 * Aplicador que, além de aplicar a operação, tem algo a avisar ao técnico
 * (Plano 24, Task 24.4).
 *
 * Interface **opcional**, e separada de `AplicadorDeOperacao` de propósito: os
 * sete aplicadores existentes continuam válidos sem implementar nada, e o
 * `AppSyncService` só pergunta os avisos a quem declara ter algum. Acrescentar
 * o método na interface principal obrigaria os sete a devolver um array vazio
 * para satisfazer o contrato.
 *
 * Aviso não é recusa. Quem precisa impedir a operação lança
 * `ValidationException` de `aplicar()`, que vira `sync_conflict` com motivo
 * `regra_de_negocio`. O que passa por aqui é informação que acompanha uma
 * operação **aplicada com sucesso**: o registro foi gravado, e o técnico
 * precisa saber de algo a respeito dele.
 *
 * Único implementador hoje: `AplicadorDeAvistamento`, que avisa quando o
 * produto aplicado está com registro vencido ou cancelado na Anvisa. O plano é
 * explícito quanto a isso: aviso primeiro, bloqueio depois, e só com o cliente
 * ciente.
 */
interface AplicadorQueAvisa
{
    /**
     * Avisos gerados pela última chamada a `aplicar()` desta instância.
     *
     * Contrato de estado: `aplicar()` **zera** a lista no começo de cada
     * chamada. Os aplicadores são instâncias do container mantidas durante
     * toda a requisição, e um lote de sincronização chama `aplicar()` várias
     * vezes na mesma instância; sem o reset, o aviso de um avistamento
     * apareceria colado no seguinte.
     *
     * @return array<int, array{item: string, rotulo: string, detalhe: string, exigencia: string}>
     */
    public function avisosDaUltimaAplicacao(): array;
}
