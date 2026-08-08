<?php

namespace App\Http\Controllers;

use App\Http\Requests\NormativeReferenceRequest;
use App\Models\Company;
use App\Models\NormativeReference;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Cadastro da referência normativa do tenant (Plano 24, Task 24.5).
 *
 * A resolução da Anvisa muda — a RDC 52/2009 já virou RDC 622/2022, e a
 * próxima virá. Este cadastro é o que permite ao tenant trocar o texto que sai
 * nos documentos emitidos sem esperar por uma versão nova do sistema.
 *
 * Isolamento entre empresas, sem escopo global
 * --------------------------------------------
 * `NormativeReference` **não** usa `BelongsToCompany`, e o motivo está no
 * cabeçalho do model: a linha de `company_id` nulo é a referência padrão da
 * plataforma, que o escopo global esconderia. Sem o escopo, o isolamento é
 * responsabilidade deste controller, e ele é explícito em três pontos:
 *
 * 1. a listagem filtra por `daEmpresa()` (as do tenant mais as da plataforma);
 * 2. a criação força `company_id` com o tenant corrente, nunca com valor vindo
 *    da requisição;
 * 3. `garantirQueEDoTenant()` roda em toda alteração e exclusão, e responde
 *    404 — e não 403 — para registro de outra empresa: confirmar a existência
 *    de um registro alheio já vazaria informação entre concorrentes.
 *
 * A referência **padrão da plataforma** (`company_id` nulo) é somente leitura
 * aqui, pelo mesmo `garantirQueEDoTenant()`: ela é dado da plataforma e vale
 * para todos os tenants. Quem quiser outro texto cadastra o próprio, que tem
 * prioridade na resolução.
 */
class NormativeReferenceController extends Controller
{
    public function index()
    {
        $empresa = Company::current();

        return Inertia::render('Conformidade/Referencias', [
            'referencias' => NormativeReference::query()
                ->daEmpresa((int) $empresa->getKey())
                ->orderByRaw('company_id is null')
                ->orderBy('chave')
                ->get()
                ->map(fn (NormativeReference $referencia): array => [
                    'id' => $referencia->id,
                    'chave' => $referencia->chave,
                    'texto' => $referencia->texto,
                    'texto_curto' => $referencia->texto_curto,
                    'vigente_desde' => $referencia->vigente_desde?->format('Y-m-d'),
                    'ativo' => (bool) $referencia->ativo,
                    'da_plataforma' => $referencia->company_id === null,
                    'editavel' => $referencia->company_id !== null,
                ])
                ->values(),
            'chave_principal' => NormativeReference::CHAVE_PRINCIPAL,
            'vigente' => NormativeReference::resolver((int) $empresa->getKey())?->texto,
        ]);
    }

    public function store(NormativeReferenceRequest $request): RedirectResponse
    {
        $dados = $request->validated();

        // `company_id` do tenant corrente, sempre, e nunca do corpo da
        // requisição: uma referência gravada com `company_id` nulo passaria a
        // valer para todos os tenants da plataforma.
        $dados['company_id'] = Company::current()->getKey();
        $dados['ativo'] = $request->boolean('ativo', true);

        NormativeReference::query()->create($dados);

        return redirect()
            ->route('conformidade.referencias.index')
            ->with('success', 'Referência normativa cadastrada. Os próximos documentos emitidos já saem com ela.');
    }

    public function update(NormativeReferenceRequest $request, NormativeReference $referencia): RedirectResponse
    {
        $this->garantirQueEDoTenant($referencia);

        $dados = $request->validated();
        $dados['ativo'] = $request->boolean('ativo', true);

        // `company_id` fora do update de propósito: mover uma referência de
        // tenant (ou para a plataforma) não é edição, é outra operação, e não
        // existe caso de uso para ela nesta tela.
        unset($dados['company_id']);

        $referencia->update($dados);

        return redirect()
            ->route('conformidade.referencias.index')
            ->with('success', 'Referência normativa atualizada.');
    }

    public function destroy(NormativeReference $referencia): RedirectResponse
    {
        $this->garantirQueEDoTenant($referencia);

        $referencia->delete();

        return redirect()
            ->route('conformidade.referencias.index')
            ->with('success', 'Referência normativa removida. Os documentos voltam a usar o padrão do sistema.');
    }

    /**
     * 404 para referência que não é do tenant corrente.
     *
     * 404, e não 403, quando o registro é de outra empresa: confirmar a
     * existência de um registro alheio já vaza informação entre tenants, que
     * neste produto são concorrentes entre si.
     *
     * A referência padrão da plataforma (`company_id` nulo) também cai aqui, e
     * de propósito: ela é dado da plataforma, vale para todos os tenants, e
     * ninguém a edita ou apaga por esta tela. Quem quiser outro texto cadastra
     * o próprio, que tem prioridade na resolução.
     */
    private function garantirQueEDoTenant(NormativeReference $referencia): void
    {
        if ((int) $referencia->company_id !== (int) Company::current()->getKey()) {
            throw new NotFoundHttpException;
        }
    }
}
