<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\OnboardingService;

/**
 * Ações da trilha de primeiros passos do tenant novo (Plano 8, Task 8.9).
 *
 * Só dois verbos, os dois disparados pelo bloco do dashboard: dispensar um
 * passo pendente e trazer de volta um passo dispensado. Nenhum dos dois marca
 * passo como concluído — isso é `OnboardingService::avaliar()`, rodado pelo
 * middleware do Inertia a cada requisição, nunca por clique do usuário.
 *
 * Sem verificação de dono no `{chave}`: ela não é um recurso identificado na
 * URL, é uma string fixa do catálogo (`PassosDeOnboarding::catalogo()`), e o
 * Service já opera dentro do tenant de `Company::current()`. Chave fora do
 * catálogo não quebra nada: os dois métodos do Service só agem sobre um
 * registro existente ou recém-criado com aquela chave, sem efeito colateral
 * em mais nada.
 *
 * Sem permissão própria: quem já vê o dashboard já pode decidir o que fazer
 * com a própria trilha.
 */
class OnboardingController extends Controller
{
    public function __construct(
        private readonly OnboardingService $onboarding,
    ) {
    }

    /**
     * Dispensa um passo pendente, tirando-o da conta de pendentes.
     */
    public function ignorar(string $chave)
    {
        $this->onboarding->ignorar(Company::current(), $chave);

        return back();
    }

    /**
     * Traz de volta um passo dispensado, devolvendo-o para pendente.
     */
    public function retomar(string $chave)
    {
        $this->onboarding->retomar(Company::current(), $chave);

        return back();
    }
}
