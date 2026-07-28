<?php

namespace Tests;

use App\Services\ModuleService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * `ModuleService::$cache` (Task 6.3) memoiza os módulos ativos por
     * empresa numa propriedade estática, pensada para o ciclo de uma única
     * requisição HTTP real (processo novo a cada request). A suíte inteira
     * roda no mesmo processo PHP, então sem isto o resultado calculado por um
     * teste (inclusive um cálculo errado, por exemplo um teste que cria
     * usuário sem semear o catálogo de módulos) vazaria para o próximo teste
     * que consultar a mesma empresa. Na prática isso quase sempre é a empresa
     * fundadora (id 1): é a mesma linha, criada uma única vez pela migration
     * de fundação, reaproveitada em toda a suíte.
     *
     * Zerar aqui, antes de cada teste, é o equivalente de teste ao "processo
     * novo a cada request" que a produção já tem de graça.
     */
    protected function setUp(): void
    {
        parent::setUp();

        app(ModuleService::class)->esquecerCache();
    }
}
