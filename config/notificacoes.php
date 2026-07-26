<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Antecedência do aviso de contrato a vencer
    |--------------------------------------------------------------------------
    |
    | Dias antes de `contracts.end_date` em que a rotina
    | `notificacoes:avisos-diarios` avisa a empresa que o contrato está
    | terminando. O aviso sai uma vez, no dia exato em que faltam estes dias,
    | e não todo dia da contagem regressiva: alerta repetido vira regra de
    | caixa de entrada e para de ser lido.
    |
    | Limitação conhecida: o valor é global da aplicação, não por tenant. O
    | Plano 14 previa "prazo configurado pelo tenant", mas não existe coluna
    | em `companies` nem tela para isso, e criá-las está fora do escopo da
    | Task 14.4. Quando a configuração por empresa entrar, esta chave vira o
    | padrão de quem não configurou.
    |
    */

    'dias_aviso_contrato_vencer' => (int) env('NOTIFICACOES_DIAS_AVISO_CONTRATO_VENCER', 30),

    /*
    |--------------------------------------------------------------------------
    | Marcos do aviso de certificado a vencer
    |--------------------------------------------------------------------------
    |
    | Quantos dias antes do fim da garantia o cliente (e a empresa) são
    | avisados. Cada marco gera um aviso próprio, distinguido na chave de
    | idempotência, então o mesmo certificado avisa três vezes ao longo do
    | tempo e nenhuma vez a mais. Três marcos, e não um: 30 dias dá tempo de
    | agendar a renovação, 7 dias é o empurrão de quem deixou passar.
    |
    */

    'marcos_certificado_a_vencer' => [30, 15, 7],

    /*
    |--------------------------------------------------------------------------
    | Antecedência do aviso de orçamento a expirar
    |--------------------------------------------------------------------------
    |
    | Dias antes de `budgets.validity_date` em que a empresa é avisada de que
    | um orçamento enviado ainda não teve resposta. Prazo curto de propósito:
    | o aviso existe para gerar o contato de última hora, não para lembrar de
    | um orçamento que acabou de sair.
    |
    */

    'dias_aviso_orcamento_a_expirar' => (int) env('NOTIFICACOES_DIAS_AVISO_ORCAMENTO_A_EXPIRAR', 3),

];
