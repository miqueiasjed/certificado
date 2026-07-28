<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tolerância antes do bloqueio por falta de pagamento
    |--------------------------------------------------------------------------
    |
    | Dias corridos, contados no fuso do negócio, entre o vencimento de uma
    | fatura e a suspensão do acesso do tenant (`plataforma:inadimplencia`,
    | Task 7.7). Vencida há menos que isto mantém a assinatura em atraso e o
    | acesso liberado; vencida há este número de dias ou mais suspende.
    |
    | A contagem é por dia, nunca por instante: uma fatura que vence hoje não
    | está vencida às 21h de Brasília, e comparar em UTC bloquearia o tenant um
    | dia antes do que o contrato dele diz (ver `App\Support\BusinessDate`).
    |
    | Cinco dias é o padrão do Plano 7. Encurtar isto é a mudança mais cara que
    | se pode fazer neste arquivo: o fim da contagem é bloqueio de acesso à
    | operação de um cliente real.
    |
    */

    'dias_de_tolerancia' => (int) env('ASSINATURA_DIAS_DE_TOLERANCIA', 5),

    /*
    |--------------------------------------------------------------------------
    | Avisos antes do vencimento
    |--------------------------------------------------------------------------
    |
    | Dias ANTES do vencimento em que a régua registra o aviso de "sua fatura
    | vence em breve". Cada marco avisa uma vez, no dia exato em que faltam
    | aqueles dias, e não em toda a contagem regressiva: aviso repetido vira
    | regra de caixa de entrada e para de ser lido (mesmo critério de
    | `config/notificacoes.php`).
    |
    | Não vai para `env()`: é uma lista, e lista em variável de ambiente vira
    | string que alguém precisa lembrar de explodir. Quem quiser outros marcos
    | muda aqui, com deploy.
    |
    */

    'dias_de_aviso' => [3, 1],

    /*
    |--------------------------------------------------------------------------
    | Cobranças depois do vencimento
    |--------------------------------------------------------------------------
    |
    | Dias DEPOIS do vencimento em que a régua registra a cobrança do que está
    | em aberto, enquanto a tolerância ainda não estourou. O dia que coincide
    | com `dias_de_tolerancia` não gera cobrança: naquele dia o que acontece é a
    | suspensão, e a mensagem é outra.
    |
    | Mesma decisão de `dias_de_aviso` quanto a `env()`.
    |
    */

    'dias_de_cobranca' => [1, 3, 5],

];
