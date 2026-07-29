<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Grupos de rotas expostos ao JavaScript
    |--------------------------------------------------------------------------
    |
    | `@routes` sem grupo imprime a lista inteira de rotas nomeadas do sistema
    | dentro do HTML. No painel autenticado isso é aceitável: quem lê já entrou.
    | Na página pública de agendamento (Plano 16, Task 16.3) não é: ela é aberta
    | por qualquer pessoa da internet, e entregaria o mapa completo da aplicação
    | (financeiro, plataforma do super admin, portal, aplicativo do técnico) a
    | quem só quer marcar uma visita. Não vaza dado, vaza a planta do prédio.
    |
    | O grupo `publico` limita a lista às rotas de `routes/publico.php`, e é o
    | que `resources/views/publico/pagina.blade.php` usa. Rota pública nova
    | entra sozinha aqui, porque o padrão casa pelo prefixo de nome.
    |
    */

    'groups' => [
        'publico' => ['publico.*'],
    ],
];
