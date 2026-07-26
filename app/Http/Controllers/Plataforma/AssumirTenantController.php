<?php

namespace App\Http\Controllers\Plataforma;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\AssumirTenantService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Entrada e saída do modo suporte.
 *
 * Controller fino, como todo controller do projeto: pega o usuário autenticado
 * e a empresa da rota, chama o Service e redireciona. Toda a regra, inclusive a
 * auditoria obrigatória, está em `AssumirTenantService`.
 *
 * Sem FormRequest: as duas ações não recebem campo nenhum do formulário. A
 * empresa chega por route-model binding e o usuário pelo guard.
 *
 * Autorização: o grupo `/plataforma` já roda atrás de `auth` e `platform.admin`,
 * então usuário comum nem chega aqui, e a tentativa dele fica registrada como
 * `plataforma_negada` pelo próprio middleware.
 */
class AssumirTenantController extends Controller
{
    public function __construct(private readonly AssumirTenantService $servico) {}

    /**
     * Assume o tenant e joga o super admin na área das empresas.
     *
     * O binding encontra qualquer empresa, e isso é o correto aqui: `Company`
     * não é model de domínio escopado, e escolher entre todos os tenants é o
     * propósito da tela. Fora de `/plataforma` nenhuma rota recebe `{company}`.
     */
    public function assumir(Request $request, Company $company): RedirectResponse
    {
        $this->servico->assumir($request->user(), $company);

        // Volta para o dashboard das empresas, já dentro do tenant assumido. A
        // faixa de aviso da Task 5.9 aparece a partir daqui, em toda tela.
        return redirect('/dashboard')
            ->with('success', "Modo suporte ativo. Você está operando dentro de {$company->name}.");
    }

    public function liberar(Request $request): RedirectResponse
    {
        $this->servico->liberar($request->user());

        return redirect('/plataforma')
            ->with('success', 'Modo suporte encerrado. Você voltou para a área da plataforma.');
    }
}
