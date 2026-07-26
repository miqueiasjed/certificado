<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A raiz do sistema é o dashboard, que fica atrás do middleware auth.
     * Visitante sem sessão é mandado para o login.
     *
     * O teste que veio com o scaffold do Laravel esperava 200 nesta rota, o que
     * nunca valeu neste projeto: o dashboard sempre exigiu autenticação.
     */
    public function test_visitante_sem_sessao_e_redirecionado_para_o_login(): void
    {
        $resposta = $this->get('/');

        $resposta->assertRedirect(route('login'));
    }
}
