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
        // 01:00: depois das rotinas financeiras e de certificado (00:10 a
        // 00:40), que ela não depende, e antes da purga de auditoria
        // (02:00), para não competir com a rotina mais pesada da janela.
        'contratos:gerar-visitas' => '01:00',
        // 02:00, fora da janela das outras: é a rotina mais pesada, porque
        // percorre e apaga em lotes as tabelas de auditoria inteiras.
        'auditoria:purge' => '02:00',
        // 03:00, depois da purga de auditoria (02:00), para não competir com
        // ela: apuração de uso é leitura pura (inclusive um `stat` de disco
        // por foto), mas ainda assim custa I/O, e a purga é a rotina mais
        // pesada da janela.
        'plataforma:apurar-uso' => '03:00',
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
