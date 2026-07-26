<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Campos ignorados
    |--------------------------------------------------------------------------
    |
    | Campos nunca gravados no log, em nenhum model. Além desta lista, cada
    | model pode declarar a propriedade $naoAuditar com os seus próprios campos.
    | A trait Auditavel ainda mantém um piso próprio de campos sensíveis (senha,
    | token, segredo), que vale mesmo se esta lista for esvaziada.
    |
    */

    'campos_ignorados' => ['password', 'remember_token', 'updated_at', 'created_at'],

    /*
    |--------------------------------------------------------------------------
    | Retenção
    |--------------------------------------------------------------------------
    |
    | Dias de retenção do histórico antes do expurgo.
    |
    */

    'dias_de_retencao' => env('AUDITORIA_DIAS_DE_RETENCAO', 730),

];
