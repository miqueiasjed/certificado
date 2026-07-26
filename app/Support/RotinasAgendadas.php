<?php

namespace App\Support;

/**
 * Fonte única das rotinas agendadas do sistema.
 *
 * O agendamento em bootstrap/app.php e o comando de diagnóstico leem estas
 * mesmas listas, para que assinatura e horário nunca divirjam entre os dois.
 *
 * São duas listas porque são duas periodicidades: `DIARIAS`, com hora marcada
 * no dia, e `POR_INTERVALO`, que roda de N em N minutos. `todas()` junta as
 * duas para quem só precisa saber quais rotinas existem.
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
        // 07:00: depois de tudo que muda o dado que ela lê (status de
        // certificado, status de pagamento e geração de visitas, entre 00:10 e
        // 01:00), e antes das 08:00, que é a hora padrão de envio da central de
        // notificações. A folga de uma hora é o que garante que o lembrete da
        // véspera já esteja na fila quando o despachante passar às 08:00; às
        // 08:10 os avisos do dia só sairiam na passada seguinte.
        'notificacoes:avisos-diarios' => '07:00',
    ];

    /**
     * Assinatura da rotina que verifica se as outras estão rodando (Task 14.5).
     *
     * Ganha constante própria porque aparece em dois papéis. Ela é uma rotina
     * agendada como as demais, declarada logo abaixo em `POR_INTERVALO`, e é
     * também a única que a detecção de rotina parada precisa reconhecer pelo
     * nome, para não acusar a si mesma de não ter rodado.
     */
    public const VERIFICACAO = 'rotinas:verificar';

    /**
     * Assinatura do comando => intervalo entre execuções, em minutos.
     *
     * Rotina que não tem hora marcada: roda o dia inteiro, de N em N minutos.
     * Fica fora de `DIARIAS` porque lá o valor da chave é o horário `HH:MM` do
     * disparo único do dia, e misturar as duas semânticas no mesmo array faria
     * `bootstrap/app.php` ter que adivinhar, pelo formato do valor, se chama
     * `dailyAt()` ou `cron()`.
     *
     * Continua sendo a mesma fonte única: quem agenda, quem diagnostica
     * (`routines:status`) e quem verifica rotina parada (`rotinas:verificar`)
     * leem daqui, e por isso a verificação consegue saber a janela esperada de
     * uma rotina de 5 minutos sem inferir nada.
     *
     * @var array<string, int>
     */
    public const POR_INTERVALO = [
        // Envio da fila de notificações (Plano 14). A cada 5 minutos, e não a
        // cada minuto: aviso de visita não é tempo real, e a folga reduz o
        // custo de um processo que sobe e desce o dia inteiro.
        'notificacoes:despachar' => 5,

        // Verificação das demais rotinas (Task 14.5). De hora em hora, e não de
        // 5 em 5 minutos: o aviso que ela gera é único por rotina por dia, então
        // passar com mais frequência não adiantaria a descoberta de nada, só
        // multiplicaria consultas. De hora em hora também é o que dá granularidade
        // suficiente para a rotina diária das 00:10 ser cobrada ainda no mesmo dia
        // em que estourou a janela de 26 horas.
        self::VERIFICACAO => 60,
    ];

    /**
     * Rotinas que não pertencem a empresa nenhuma.
     *
     * Vazia hoje, e o que importa é o motivo. Todas as rotinas atuais processam
     * dado de várias empresas dentro da mesma execução, seja pela trait
     * `OperaPorTenant`, seja pelo laço próprio. Nenhuma delas é de plataforma no
     * sentido de não tocar dado de tenant nenhum, e por isso a verificação avisa
     * o administrador de cada empresa quando uma para: todas são afetadas
     * igualmente.
     *
     * A lista existe para o dia em que aparecer a primeira rotina genuinamente
     * de plataforma (limpeza de tabela compartilhada, consolidação entre
     * tenants, cobrança da assinatura do próprio SaaS). Nesse dia, o
     * destinatário do aviso é o super admin do Plano 5, e não o administrador
     * de cada empresa, que não tem o que fazer a respeito.
     * `VerificarRotinasAgendadas` já separa os dois caminhos lendo daqui.
     *
     * @var array<int, string>
     */
    public const DE_PLATAFORMA = [];

    /**
     * Minutos que a trava de sobreposição segura antes de expirar sozinha.
     *
     * Serve de rede de segurança para processo morto sem liberar o mutex.
     */
    public const MINUTOS_DE_TRAVA = 30;

    /**
     * Trava das rotinas de intervalo curto.
     *
     * Precisa ser bem menor que a das diárias: com a rotina rodando de 5 em 5
     * minutos, uma trava de 30 minutos deixada para trás por processo morto
     * bloquearia seis passadas seguidas.
     */
    public const MINUTOS_DE_TRAVA_CURTA = 10;

    /**
     * Minutos entre duas execuções de uma rotina diária. Existe para que quem
     * calcula janela de tolerância (Task 14.5) trate diária e intervalo curto
     * pela mesma conta.
     */
    public const MINUTOS_DE_UM_DIA = 1440;

    /**
     * Folga somada ao intervalo da rotina antes de ela ser dada como parada.
     *
     * Duas horas é o número do plano: uma diária só é cobrada depois de 26 horas
     * sem rodar (1440 + 120), e não depois de 24, porque servidor reiniciado,
     * deploy no meio da madrugada e fila de cron disputada atrasam a passada sem
     * que nada esteja quebrado. Avisar às 24 horas cravadas transformaria o
     * alerta em ruído de rotina, que é o mesmo que não avisar.
     *
     * A folga é uma constante somada, e não uma proporção do intervalo, de
     * propósito. Proporcional, a rotina de 5 minutos ganharia dez minutos de
     * tolerância e acusaria falha em qualquer soluço passageiro; o aviso existe
     * para rotina que parou, não para rotina que atrasou.
     */
    public const MINUTOS_DE_TOLERANCIA_EXTRA = 120;

    /**
     * Todas as rotinas agendadas, diárias e de intervalo curto, com a descrição
     * do horário em que rodam.
     *
     * @return array<string, string>
     */
    public static function todas(): array
    {
        $rotinas = self::DIARIAS;

        foreach (self::POR_INTERVALO as $assinatura => $minutos) {
            $rotinas[$assinatura] = "a cada {$minutos} min";
        }

        return $rotinas;
    }

    /**
     * Apenas as assinaturas dos comandos, sem os horários.
     *
     * @return array<int, string>
     */
    public static function assinaturas(): array
    {
        return array_keys(self::todas());
    }

    /**
     * Intervalo esperado entre duas execuções da rotina, em minutos, ou `null`
     * quando a assinatura não é de uma rotina agendada.
     */
    public static function intervaloEmMinutos(string $assinatura): ?int
    {
        if (array_key_exists($assinatura, self::DIARIAS)) {
            return self::MINUTOS_DE_UM_DIA;
        }

        return self::POR_INTERVALO[$assinatura] ?? null;
    }

    /**
     * Minutos que a rotina pode passar sem uma execução bem-sucedida antes de
     * ser dada como parada, ou `null` quando a assinatura não é conhecida.
     *
     * É o intervalo declarado mais a folga: 1440 + 120 = 1560 minutos (26 horas)
     * para uma diária, 5 + 120 = 125 minutos para a rotina de 5 em 5. Quem
     * detecta rotina parada usa este número, e nunca `intervaloEmMinutos()`
     * cru: cobrar a diária às 24 horas exatas acusaria falha em toda passada
     * que atrasasse alguns minutos.
     */
    public static function janelaDeToleranciaEmMinutos(string $assinatura): ?int
    {
        $intervalo = self::intervaloEmMinutos($assinatura);

        return $intervalo === null ? null : $intervalo + self::MINUTOS_DE_TOLERANCIA_EXTRA;
    }

    /**
     * A rotina é de plataforma, sem dono entre as empresas?
     *
     * Hoje devolve `false` para todas, porque `DE_PLATAFORMA` está vazia. Ver o
     * comentário da constante para o motivo e para quando isso muda.
     */
    public static function ehDePlataforma(string $assinatura): bool
    {
        return in_array($assinatura, self::DE_PLATAFORMA, true);
    }
}
