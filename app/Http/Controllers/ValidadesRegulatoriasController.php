<?php

namespace App\Http\Controllers;

use App\Http\Requests\ValidadesRegulatoriasRequest;
use App\Models\Company;
use App\Services\Compliance\ValidadeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

/**
 * Validades dos documentos regulatórios da empresa (Plano 24, Task 24.6).
 *
 * Tela própria, e não mais um bloco em `Settings/Company.vue`: aquele
 * formulário já tem trinta campos e quatro uploads, e enfiar ali quatro datas
 * mais quatro anexos deixaria a informação que o fiscal pede escondida no meio
 * de dados de cabeçalho de PDF. Aqui cada documento aparece com número,
 * validade, situação em texto e anexo, na mesma linha.
 *
 * A situação de cada documento vem de `ValidadeService`, o mesmo que alimenta
 * o checklist e a rotina de avisos: a tela nunca recalcula regra própria, e
 * por isso não tem como divergir do e-mail que a empresa recebeu de manhã.
 *
 * O tenant é sempre `Company::current()`, resolvido pelo usuário autenticado —
 * nenhum identificador de empresa chega pela requisição.
 */
class ValidadesRegulatoriasController extends Controller
{
    /**
     * Pasta dos anexos no disco `public`, ao lado do que
     * `CompanyController::update()` já usa para logo e assinaturas.
     */
    private const PASTA = 'company/documentos';

    public function __construct(private readonly ValidadeService $validades) {}

    public function edit()
    {
        $empresa = Company::current();

        return Inertia::render('Settings/Validades', [
            'empresa' => [
                'register_crea' => $empresa->register_crea,
                'crq' => $empresa->crq,
                'license_sanitary' => $empresa->license_sanitary,
                'license_environmental' => $empresa->license_environmental,
                'license_business' => $empresa->license_business,
            ],
            'documentos' => $this->documentosParaTela($empresa),
        ]);
    }

    public function update(ValidadesRegulatoriasRequest $request): RedirectResponse
    {
        $empresa = Company::current();
        $dados = $request->validated();

        // Os campos de arquivo saem do array validado: `null` ali significa
        // "não enviei arquivo novo nesta submissão", e gravar esse `null`
        // apagaria o anexo que já estava lá. Mesmo cuidado que
        // `CompanyController::update()` toma com logo e assinaturas.
        foreach ($request->camposDeArquivo() as $campo) {
            unset($dados[$campo]);

            if (! $request->hasFile($campo)) {
                continue;
            }

            $anterior = $empresa->getAttribute($campo);

            if (filled($anterior)) {
                Storage::disk('public')->delete($anterior);
            }

            $dados[$campo] = $request->file($campo)->store(self::PASTA, 'public');
        }

        // As datas são gravadas como vieram (`Y-m-d`), sem conversão de fuso:
        // as colunas são `date`, representam um dia sem hora, e converter fuso
        // em campo sem hora é exatamente o que produz o erro de um dia numa
        // validade. String vazia vira `null` — limpar a data é alteração
        // legítima, e "não informado" nunca é "vencido".
        foreach (ValidadeService::DOCUMENTOS_DA_EMPRESA as $definicao) {
            $coluna = $definicao['validade'];

            if (array_key_exists($coluna, $dados) && blank($dados[$coluna])) {
                $dados[$coluna] = null;
            }
        }

        // O `updated` de `Company` dispara `ValidadeRegulatoriaObserver`, que
        // encerra na hora os avisos de vencimento dos documentos cuja validade
        // mudou. É por isso que o save é um `update()` no model, e não um
        // `DB::table()->update()`: sem o evento, a empresa continuaria
        // recebendo aviso de um documento que acabou de renovar.
        $empresa->update($dados);

        return redirect()
            ->route('settings.validades.edit')
            ->with('success', 'Validades atualizadas. Os avisos de vencimento já refletem as novas datas.');
    }

    /**
     * Cada documento com número, validade, situação e anexo, pronto para a
     * tela.
     *
     * @return array<int, array<string, mixed>>
     */
    private function documentosParaTela(Company $empresa): array
    {
        return collect($this->validades->documentosDaEmpresa($empresa))
            ->map(function (array $documento) use ($empresa): array {
                $item = (string) $documento['item'];
                $definicao = ValidadeService::DOCUMENTOS_DA_EMPRESA[$item];
                $arquivo = $empresa->getAttribute($item.'_arquivo');

                return [
                    'item' => $item,
                    'rotulo' => $documento['rotulo'],
                    'campo_validade' => $definicao['validade'],
                    'campo_arquivo' => $item.'_arquivo',
                    'campo_numero' => $definicao['numero'][0],
                    'validade' => $documento['validade'],
                    'numero' => $documento['numero'],
                    'situacao' => $documento['situacao'],
                    'detalhe' => $documento['detalhe'],
                    'dias_para_vencer' => $documento['dias_para_vencer'],
                    'anexo_url' => filled($arquivo) ? Storage::disk('public')->url($arquivo) : null,
                ];
            })
            ->all();
    }
}
