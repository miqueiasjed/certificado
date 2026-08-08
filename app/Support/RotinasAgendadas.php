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
        // 01:15, logo depois da geração de visitas do contrato (01:00), sem
        // dependência de dado entre as duas: uma gera OS a partir do
        // calendário do contrato, a outra lê `end_date`/`situacao_renovacao`
        // e grava fila de notificação mais o próprio `situacao_renovacao`
        // (marcação de `pendente`, Task 23.5). Ficam próximas por serem as
        // duas rotinas diárias de `contracts`, não por ordem obrigatória.
        'contratos:verificar-vencimento' => '01:15',
        // 02:00, fora da janela das outras: é a rotina mais pesada, porque
        // percorre e apaga em lotes as tabelas de auditoria inteiras.
        'auditoria:purge' => '02:00',
        // 03:00, depois da purga de auditoria (02:00), para não competir com
        // ela: apuração de uso é leitura pura (inclusive um `stat` de disco
        // por foto), mas ainda assim custa I/O, e a purga é a rotina mais
        // pesada da janela.
        'plataforma:apurar-uso' => '03:00',
        // 04:00, depois da apuração de uso (03:00) e antes dos avisos diários
        // (07:00). É a única rotina da janela que fala com o gateway da
        // PLATAFORMA (a cobrança da assinatura do tenant, Plano 7) e grava
        // Invoice: fica sozinha na hora dela para que uma lentidão desse
        // gateway não empurre nenhuma outra, e com três horas de folga até
        // os avisos, que precisam sair com a fatura do dia já emitida.
        // Diária, e não mensal, porque cada tenant assina no dia em que
        // assina e por isso tem o próprio dia de vencimento. As rotinas de
        // cobrança do Plano 19 (o tenant cobrando o cliente final dele, com
        // o gateway do próprio tenant) ficam em janela própria, às 06:15 e
        // 06:30, pelo mesmo motivo de isolamento, sem disputar esta hora.
        'plataforma:gerar-faturas' => '04:00',
        // 05:00, obrigatoriamente depois da geração de faturas (04:00). A régua
        // decide atraso e bloqueio a partir de `invoices.vencimento`, então
        // precisa enxergar o que a rotina de faturas do mesmo dia já gerou ou
        // atualizou. Invertida a ordem, o tenant seria avaliado com a foto de
        // ontem, e uma hora de folga é o que cobre a lentidão do gateway na
        // rotina anterior. Antes dos avisos diários (07:00), que é quando a
        // central de notificações do Plano 14 leva o aviso ao cliente.
        'plataforma:inadimplencia' => '05:00',
        // 05:30, logo depois da régua de inadimplência (05:00), sem disputar a
        // mesma janela: as duas leem e escrevem `companies.situacao`, mas de
        // tenants que nunca se sobrepõem (`suspensa`/`ativa`/`em_atraso` de um
        // lado, `em_avaliacao` do outro), então a ordem entre elas não muda o
        // resultado. Fica depois por semelhança de papel, não por dependência
        // de dado. Meia hora de folga é o que cobre uma passada de
        // inadimplência mais lenta que o normal sem empurrar esta para a
        // janela dos avisos diários (07:00).
        'plataforma:encerrar-avaliacoes' => '05:30',
        // 06:00, sem disputar janela com nenhuma das rotinas acima: os
        // alertas de estoque (Plano 17, Task 17.6) não dependem de status de
        // certificado, de pagamento nem de fatura, e o dado que leem
        // (`stock_balances`, `product_batches`) só muda por movimentação
        // manual, nunca por outra rotina desta lista. Fica antes das 07:00,
        // que é a hora padrão de envio da central de notificações, com uma
        // hora de folga: o suficiente para o aviso já estar na fila quando o
        // despachante passar, sem competir com a janela mais cheia (00:10 a
        // 05:30).
        'estoque:verificar' => '06:00',
        // 06:15, depois do estoque (06:00) e antes da régua de cobrança
        // (06:30), da qual ela é pré-requisito: emite a cobrança (boleto ou
        // Pix) das parcelas de contrato que vencem dentro da antecedência
        // configurada, falando com o gateway do próprio tenant (Plano 19,
        // Task 19.5). Isolada em horário próprio, longe de
        // plataforma:gerar-faturas (04:00), pelo mesmo motivo documentado
        // lá: rotina que fala com gateway de pagamento não pode ter a
        // lentidão de um provedor empurrando outra rotina da janela.
        'cobrancas:emitir-recorrentes' => '06:15',
        // 06:30, depois da emissão recorrente (06:15): o marco "3 dias antes
        // do vencimento" da régua só tem link de pagamento para mandar
        // quando a cobrança de hoje já foi emitida na passada anterior.
        // Antes dos avisos diários (07:00), pela mesma folga de uma hora
        // documentada para as demais rotinas que alimentam a fila de
        // notificações do dia.
        'cobrancas:regua' => '06:30',
        // 06:45, entre a régua de cobrança (06:30) e os avisos diários
        // (07:00). Sem dependência de dado com nenhuma das duas: lê validade
        // em `companies` e `organ_registrations`, que rotina nenhuma desta
        // lista escreve. Fica antes das 07:00 pela mesma folga já
        // documentada para `estoque:verificar` e `cobrancas:regua`: o aviso
        // precisa estar na fila quando o despachante de notificações passar
        // às 08:00, senão só sai na manhã seguinte.
        //
        // Diária, e não semanal, mesmo o reenvio do documento vencido sendo
        // semanal: quem decide a cadência de cada aviso é o marco da chave de
        // idempotência (`ValidadeService::marcoSemanal()`/`marcoMensal()`), e
        // não a frequência da rotina. Passando todo dia, o documento que
        // cruza o marco de 60, 30 ou 7 dias é avisado no próprio dia, e o
        // `compliance_checks` que a tela lê nunca fica com a foto da semana
        // passada.
        //
        // A rotina pula o tenant sem o módulo `conformidade` ativo, e o
        // módulo nasce desligado: é assim que esta entrega sobe sem disparar
        // aviso nenhum até a empresa preencher as validades reais.
        'conformidade:verificar-validades' => '06:45',
        // 07:00: depois de tudo que muda o dado que ela lê (status de
        // certificado, status de pagamento e geração de visitas, entre 00:10 e
        // 01:00), e antes das 08:00, que é a hora padrão de envio da central de
        // notificações. A folga de uma hora é o que garante que o lembrete da
        // véspera já esteja na fila quando o despachante passar às 08:00; às
        // 08:10 os avisos do dia só sairiam na passada seguinte.
        'notificacoes:avisos-diarios' => '07:00',
        // 07:30: depois dos avisos diários (07:00), que ela não depende, e antes
        // das 08:00, que é a hora padrão de envio da central de notificações. A
        // ordem entre as duas é higiene de janela, não dependência de dado: cada
        // uma lê o que a outra não escreve. Ficar antes das 08:00 é o que importa,
        // porque o convite da pesquisa é enfileirado para o dia de hoje e sai na
        // passada do despachante daquele horário; agendada às 08:10, a pesquisa
        // do dia esperaria a manhã inteira. Meia hora de folga cobre uma passada
        // de avisos diários mais lenta que o normal.
        'pesquisas:enviar' => '07:30',
    ];

    /**
     * Assinatura do comando => dia do mês e horário do disparo mensal, no
     * fuso do negócio.
     *
     * Lista própria, e não mais uma entrada em `DIARIAS`: lá o valor da
     * chave é só o horário (`dailyAt()`), e a rotina mensal precisa também do
     * dia do mês (`monthlyOn()`). Hoje tem uma única rotina: a apuração de
     * comissão (Plano 23, Task 23.2), no dia 1, depois que o mês anterior
     * inteiro já fechou. `01:40` fica na mesma janela das rotinas diárias de
     * madrugada, depois da purga de auditoria (02:00 é mais tarde, mas esta
     * roda só uma vez por mês, então o risco de disputa é baixo) e da
     * apuração de uso (03:00); a folga de minutos entre elas é só para não
     * cravarem o mesmo instante, sem dependência de dado entre uma e outra.
     *
     * @var array<string, array{dia: int, horario: string}>
     */
    public const MENSAIS = [
        'comissoes:apurar' => ['dia' => 1, 'horario' => '01:40'],
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
        // A emissão municipal é assíncrona. Dez minutos preservam uma espera
        // curta para o usuário sem consultar a prefeitura continuamente.
        'fiscal:processar-notas' => 10,

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
     * Minutos do mês mais longo do calendário (31 dias). Rotina mensal fixada
     * no dia 1 (`MENSAIS`) tem, no pior caso, 31 dias entre uma rodada e a
     * seguinte (ex.: de 1º de janeiro a 1º de fevereiro); usar o mês mais
     * longo como referência, e não uma média de 30 dias, é o que evita cobrar
     * "atrasada" uma rotina que só ainda não chegou o dia 1 do mês seguinte.
     */
    public const MINUTOS_DE_UM_MES = 31 * self::MINUTOS_DE_UM_DIA;

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
     * Todas as rotinas agendadas, diárias, mensais e de intervalo curto, com
     * a descrição do horário em que rodam.
     *
     * @return array<string, string>
     */
    public static function todas(): array
    {
        $rotinas = self::DIARIAS;

        foreach (self::MENSAIS as $assinatura => $quando) {
            $rotinas[$assinatura] = sprintf('todo dia %d, %s', $quando['dia'], $quando['horario']);
        }

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

        if (array_key_exists($assinatura, self::MENSAIS)) {
            return self::MINUTOS_DE_UM_MES;
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
