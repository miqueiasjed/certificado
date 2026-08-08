<?php

return [

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

    /*
    |--------------------------------------------------------------------------
    | Envio automático da pesquisa de satisfação
    |--------------------------------------------------------------------------
    |
    | Chave de liga e desliga da rotina `pesquisas:enviar` (Plano 16, Task
    | 16.5). Desligada, a rotina roda, informa que o envio está desligado e não
    | cria pesquisa nenhuma.
    |
    | Nasce desligada de propósito, e isto é exigência da ordem de aplicação em
    | produção do plano: o Deploy 3 sobe a pesquisa com o envio desligado, e a
    | empresa confere a fila gerada antes de ligar. Sem a chave, a primeira
    | passada do cron depois do deploy mandaria e-mail para todos os clientes
    | com visita concluída no dia anterior, sem ninguém ter visto uma mensagem
    | antes. Para conferir sem enviar nada, rode a rotina com o envio desligado
    | e leia a contagem, ou chame `SatisfactionSurveyService::criarParaVisita()`
    | em uma visita escolhida.
    |
    | Limitação conhecida: o valor é global da aplicação, não por tenant. Não
    | existe coluna nem tela para uma chave por empresa, e criá-las está fora
    | do escopo desta task. Quando a configuração por tenant entrar (o lugar
    | natural é `company_availability_settings`, junto de
    | `aceita_agendamento_online`), esta chave vira o padrão de quem não
    | configurou. Mesmo caminho que a Task 23.5 já percorreu para o prazo de
    | aviso de contrato a vencer, hoje em `CompanyContractAlertSetting`.
    |
    */

    'pesquisa_satisfacao_ativa' => (bool) env('NOTIFICACOES_PESQUISA_SATISFACAO_ATIVA', false),

];
