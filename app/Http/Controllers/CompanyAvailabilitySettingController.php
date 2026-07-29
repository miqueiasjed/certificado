<?php

namespace App\Http\Controllers;

use App\Http\Requests\AtualizarDisponibilidadeRequest;
use App\Models\Company;
use App\Services\AvailabilityService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tela de configuração do agendamento online (Plano 16, Task 16.7): dias de
 * atendimento, teto de visitas por período, antecedência, janela, chave de
 * ligar/desligar a página pública e o slug dela.
 *
 * Mesma permissão de `CompanyController` (`empresa-configurar`): é a mesma
 * família de configuração de empresa, só que numa tela própria em vez de
 * mais uma seção na tela de dados gerais.
 */
class CompanyAvailabilitySettingController extends Controller
{
    public function __construct(
        private readonly AvailabilityService $disponibilidade,
    ) {}

    /**
     * GET /settings/disponibilidade
     */
    public function edit(): Response
    {
        $empresa = Company::current();

        return Inertia::render('Settings/Disponibilidade', [
            'configuracao' => $this->disponibilidade->configuracaoDaEmpresa($empresa),
            'slugPublico' => $empresa->slug_publico,
            'urlPublicaBase' => rtrim(url('/agendar'), '/').'/',
        ]);
    }

    /**
     * PUT /settings/disponibilidade
     */
    public function update(AtualizarDisponibilidadeRequest $request): RedirectResponse
    {
        $this->disponibilidade->salvarConfiguracao(Company::current(), $request->validated());

        return redirect()->back()->with('success', 'Configuração de agendamento salva.');
    }
}
