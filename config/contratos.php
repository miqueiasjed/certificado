<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Janela de geração de visitas
    |--------------------------------------------------------------------------
    |
    | Quantos meses à frente de hoje (fuso do negócio) a rotina
    | `contratos:gerar-visitas` materializa como OS agendada. Decisão
    | registrada no Plano 9: gerar o contrato inteiro de uma vez enche a
    | agenda de OS que ainda vão mudar de data; janela curta demais impede a
    | equipe de enxergar o mês seguinte.
    |
    */

    'meses_de_janela' => (int) env('CONTRATOS_MESES_DE_JANELA', 3),

    /*
    |--------------------------------------------------------------------------
    | Alerta de contrato sem visita
    |--------------------------------------------------------------------------
    |
    | Dias sem nenhuma visita gerada para um contrato vigente antes de virar
    | pendência no painel de conformidade (Task 9.7). Não é lido por
    | `contratos:gerar-visitas`: fica aqui por ser a mesma configuração de
    | domínio "geração de visitas de contrato".
    |
    */

    'dias_de_alerta_sem_visita' => (int) env('CONTRATOS_DIAS_DE_ALERTA_SEM_VISITA', 30),

];
