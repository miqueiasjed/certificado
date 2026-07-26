<?php

namespace App\Services\Notification;

use App\Models\NotificationQueue;

/**
 * Contrato de quem entrega, de fato, um item da fila.
 *
 * O despachante (`App\Services\NotificationDispatcher`) escolhe o driver pelo
 * `canal` do item e não sabe nada além disto: nem SMTP, nem API de provedor,
 * nem anexo. Essa fronteira é o que permite ao WhatsApp entrar depois, como um
 * segundo driver, sem tocar em template, em regra de disparo nem em
 * retentativa.
 *
 * Três obrigações de quem implementa:
 *
 * 1. **Não lançar exceção de envio.** Falha de provedor vira
 *    `ResultadoDeEnvio::falhaTemporaria()` ou `::falhaPermanente()`. O
 *    despachante ainda protege com try/catch, mas quem sabe traduzir o erro do
 *    canal em "vale repetir?" é o driver, não ele.
 * 2. **Não gravar nada.** Nem na fila, nem no histórico. Quem grava a tentativa
 *    e decide a próxima é o despachante, e é isso que mantém a regra de
 *    retentativa em um lugar só, igual para todos os canais.
 * 3. **Usar o remetente do tenant dono do item** (`company_id` da linha), nunca
 *    o da plataforma. O cliente final conhece a dedetizadora, não o sistema.
 */
interface DriverDeEnvio
{
    /**
     * Canal que este driver atende, entre os de `EventosDeNotificacao::CANAIS`.
     *
     * É a chave usada pelo despachante para casar item e driver, então o valor
     * precisa ser exatamente o gravado na coluna `canal`.
     */
    public function canal(): string;

    /**
     * Entrega o item ao provedor e descreve o que aconteceu.
     */
    public function enviar(NotificationQueue $item): ResultadoDeEnvio;
}
