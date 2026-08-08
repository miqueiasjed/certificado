<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\Compliance\ChecklistService;
use App\Services\Compliance\ConformidadeDaExecucaoService;
use App\Support\BusinessDate;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Checklist de conformidade com a RDC nº 622/2022 (Plano 24, Task 24.5).
 *
 * Controller fino: toda a regra vive em `ChecklistService` e em
 * `ConformidadeDaExecucaoService`. O isolamento entre empresas vem de
 * `Company::current()` (tenant resolvido pelo usuário autenticado, nunca por
 * parâmetro da requisição) somado ao escopo global dos models de domínio que
 * os serviços consultam; não há um único identificador de empresa vindo do
 * corpo ou da URL, então não há por onde um tenant alcançar o checklist de
 * outro.
 *
 * Permissões nas rotas (`routes/web.php`): `conformidade-ver` para as
 * leituras, `conformidade-gerenciar` para o recálculo sob demanda e para o
 * cadastro da referência normativa.
 */
class ComplianceController extends Controller
{
    public function __construct(
        private readonly ChecklistService $checklist,
        private readonly ConformidadeDaExecucaoService $execucao,
    ) {}

    /**
     * Checklist do tenant.
     *
     * Leitura pura: abrir a tela não regrava `compliance_checks`. Quem regrava
     * é `verificar()`, no botão de recalcular — abrir uma tela não pode ter
     * efeito colateral de escrita.
     */
    public function index()
    {
        return Inertia::render('Conformidade/Index', [
            'checklist' => $this->checklist->montar(Company::current()),
        ]);
    }

    /**
     * Recalcula o checklist sob demanda e grava o resultado.
     *
     * Existe além da rotina diária porque quem acabou de preencher a validade
     * de uma licença quer ver o checklist mudar agora, e não amanhã de manhã.
     */
    public function verificar()
    {
        $this->checklist->verificar(Company::current());

        return redirect()
            ->route('conformidade.index')
            ->with('success', 'Checklist de conformidade recalculado.');
    }

    /**
     * Ordens de serviço concluídas do período com documentação incompleta ou
     * com aviso de registro de produto.
     *
     * O período padrão é o último trimestre, o mesmo do item de checklist. As
     * datas chegam como `Y-m-d` e são interpretadas no fuso do negócio: elas
     * são comparadas com `scheduled_date`, que é campo `date`.
     */
    public function pendenciasDeExecucao(Request $request)
    {
        $hoje = BusinessDate::hoje();

        $de = $this->diaValido($request->query('de'))
            ?? $hoje->subMonths(ChecklistService::MESES_DE_EXECUCAO_CONFERIDOS)->format('Y-m-d');

        $ate = $this->diaValido($request->query('ate')) ?? $hoje->format('Y-m-d');

        return Inertia::render('Conformidade/PendenciasDeExecucao', [
            'de' => $de,
            'ate' => $ate,
            'ressalva' => $this->execucao->ressalva(),
            'pendencias' => $this->execucao->pendenciasDoPeriodo($de, $ate),
        ]);
    }

    /**
     * `Y-m-d` informado na query, ou `null`.
     *
     * Validação mínima e local, em vez de FormRequest: são dois parâmetros
     * opcionais de filtro de uma tela de leitura, e data mal formatada aqui
     * cai no padrão em vez de virar erro de validação na cara de quem só
     * digitou errado na URL.
     */
    private function diaValido(mixed $valor): ?string
    {
        $texto = trim((string) $valor);

        if ($texto === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $texto) !== 1) {
            return null;
        }

        return BusinessDate::paraFusoNegocio($texto)?->format('Y-m-d');
    }
}
