<?php

namespace App\Support;

/**
 * Fonte única das rotinas agendadas do sistema.
 *
 * O agendamento em bootstrap/app.php e o comando de diagnóstico leem esta
 * mesma lista, para que assinatura e horário nunca divirjam entre os dois.
 *
 * Utilitário de infraestrutura: sem regra de domínio e sem acesso a banco.
 */
final class RotinasAgendadas
{
    /**
     * Assinatura do comando => horário diário, no formato HH:MM.
     *
     * Os horários são lidos no fuso do negócio (America/Sao_Paulo), nunca em
     * UTC. A ordem entre eles é regra de operação: o saldo diário só fecha
     * depois que os status de pagamento do dia já foram atualizados.
     *
     * @var array<string, string>
     */
    public const DIARIAS = [
        'certificates:update-status' => '00:10',
        'payments:update-statuses' => '00:20',
        'cash:sync-daily-balances' => '00:30',
        'cash:create-missing-balances' => '00:40',
    ];

    /**
     * Minutos que a trava de sobreposição segura antes de expirar sozinha.
     *
     * Serve de rede de segurança para processo morto sem liberar o mutex.
     */
    public const MINUTOS_DE_TRAVA = 30;

    /**
     * Apenas as assinaturas dos comandos, sem os horários.
     *
     * @return array<int, string>
     */
    public static function assinaturas(): array
    {
        return array_keys(self::DIARIAS);
    }
}
