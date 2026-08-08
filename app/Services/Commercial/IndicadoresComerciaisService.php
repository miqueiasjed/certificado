<?php

namespace App\Services\Commercial;

use App\Models\AuditLog;
use App\Models\Budget;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Painel comercial (Plano 23, Task 23.3): orçamento enviado, taxa de
 * conversão, ticket médio dos aprovados e tempo médio entre envio e
 * aprovação, com evolução mês a mês. Tudo apurado a partir de `budgets`, sem
 * tocar em comissão, meta ou parcela — ver `GoalService` para o
 * acompanhamento de meta por pessoa.
 *
 * ## De onde vem o tempo até o fechamento
 *
 * `budgets` não guarda quando um orçamento foi enviado nem quando foi
 * aprovado, só o `status` corrente. O dado existe em outro lugar: `Budget` usa
 * a trait `Auditavel`, e toda troca de status vira uma linha em `audit_logs`
 * com o instante exato (`created_at`) e o valor novo (`valores_depois`). Este
 * Service lê esse histórico para reconstruir "quando ficou enviado" (primeira
 * vez que `status` virou `sent`) e "quando ficou aprovado" (primeira vez que
 * virou `approved` ou `converted`), e a diferença entre os dois é o tempo até
 * o fechamento. Orçamento sem os dois instantes (aprovado sem nunca ter
 * passado por `sent` — por exemplo, criado direto como aprovado — ou sem
 * nenhum log porque foi editado antes de a auditoria existir) não entra nessa
 * média: é melhor faltar amostra do que inventar uma data.
 *
 * ## Nenhuma conclusão em texto
 *
 * O retorno é número e contagem, nunca frase pronta ("vendedor em queda",
 * "meta em risco"). Interpretar o número é conversa de gestão, não linha de
 * código.
 */
class IndicadoresComerciaisService
{
    /**
     * Mesmo critério de `GoalService::STATUS_APROVADOS`: orçamento convertido
     * continua sendo um orçamento aprovado.
     *
     * @var array<int, string>
     */
    public const STATUS_APROVADOS = ['approved', 'converted'];

    public const STATUS_RASCUNHO = 'draft';

    /**
     * Mesma regra e mesmo número da pesquisa de satisfação do Plano 16
     * (`SatisfactionSurveyService::MINIMO_DE_RESPOSTAS_PARA_MEDIA`): corte com
     * menos orçamentos que isto no período omite a taxa de conversão, mas
     * continua mostrando a contagem.
     */
    public const MINIMO_DE_ORCAMENTOS_PARA_CONVERSAO = 3;

    /**
     * Janela padrão quando nenhuma data é informada: o mês corrente e os onze
     * anteriores, mesmo padrão de `SatisfactionSurveyService`.
     */
    public const MESES_PADRAO = 12;

    /**
     * Indicadores comerciais da empresa corrente.
     *
     * Filtros aceitos, todos opcionais:
     *
     * - `de` e `ate` (`Y-m-d`): recorte de dias, no fuso do negócio. Sem eles,
     *   vale o mês corrente e os onze anteriores.
     * - `user_id`: restringe a apuração a uma pessoa só.
     *
     * @param  array<string, mixed>  $filtros
     * @return array{
     *     periodo: array{de: string, ate: string},
     *     minimo_de_orcamentos_para_conversao: int,
     *     geral: array<string, mixed>,
     *     por_pessoa: array<int, array<string, mixed>>,
     *     evolucao_mensal: array<int, array<string, mixed>>
     * }
     */
    public function indicadores(array $filtros = []): array
    {
        [$de, $ate] = $this->janelaDosIndicadores($filtros);

        $userId = filled($filtros['user_id'] ?? null) ? (int) $filtros['user_id'] : null;

        $orcamentos = $this->orcamentosDoPeriodo($de, $ate, $userId);

        $idsAprovados = $orcamentos
            ->filter(fn (object $orcamento): bool => in_array($orcamento->status, self::STATUS_APROVADOS, true))
            ->pluck('id');

        $duracoes = $this->duracoesDeFechamento($idsAprovados);

        return [
            'periodo' => ['de' => $de->toDateString(), 'ate' => $ate->toDateString()],
            'minimo_de_orcamentos_para_conversao' => self::MINIMO_DE_ORCAMENTOS_PARA_CONVERSAO,
            'geral' => $this->resumoDoGrupo($orcamentos, $duracoes),
            'por_pessoa' => $this->porPessoa($orcamentos, $duracoes),
            'evolucao_mensal' => $this->porMes($orcamentos, $duracoes),
        ];
    }

    // -----------------------------------------------------------------
    // Consulta base: orçamentos do período, com o valor já calculado
    // -----------------------------------------------------------------

    /**
     * Todo orçamento que saiu do rascunho no período, com o vendedor e o
     * valor (soma dos serviços menos desconto, mesmo critério de
     * `GoalService::valorVendidoPorPessoa()`) já resolvidos em uma única
     * consulta agregada no banco.
     *
     * `LEFT JOIN` em `users`: orçamento sem vendedor (`user_id` nulo) continua
     * contando para os indicadores gerais e para a evolução mensal, só fica de
     * fora do corte por pessoa (`porPessoa()` descarta explicitamente).
     *
     * @return Collection<int, object{id: int, user_id: int|null, usuario: string|null, data: string, status: string, valor: float}>
     */
    private function orcamentosDoPeriodo(CarbonImmutable $inicio, CarbonImmutable $fim, ?int $userId): Collection
    {
        $valorPorOrcamento = DB::table('budget_services')
            ->select('budget_id')
            ->selectRaw('SUM(subtotal) as soma_servicos')
            ->groupBy('budget_id');

        return Budget::query()
            ->leftJoin('users', 'users.id', '=', 'budgets.user_id')
            ->leftJoinSub($valorPorOrcamento, 'valor_servicos', function ($join): void {
                $join->on('valor_servicos.budget_id', '=', 'budgets.id');
            })
            ->where('budgets.status', '!=', self::STATUS_RASCUNHO)
            ->whereBetween('budgets.date', [$inicio->toDateString(), $fim->toDateString()])
            ->when($userId !== null, fn ($consulta) => $consulta->where('budgets.user_id', $userId))
            ->select([
                'budgets.id as id',
                'budgets.user_id as user_id',
                'users.name as usuario',
                'budgets.date as data',
                'budgets.status as status',
                DB::raw('COALESCE(valor_servicos.soma_servicos, 0) - budgets.discount as valor'),
            ])
            ->get()
            ->map(function ($linha): object {
                $linha->valor = (float) $linha->valor;

                return $linha;
            });
    }

    /**
     * "Enviado ficou {status}" pela primeira vez, para cada orçamento
     * aprovado do período: instante de envio e instante de aprovação, lidos
     * de `audit_logs`. Ver o docblock da classe.
     *
     * A consulta é restrita aos ids já filtrados pelo período (poucas dezenas
     * a poucas centenas de orçamentos, mesmo em 5 anos de histórico), pelo
     * índice `[auditable_type, auditable_id, created_at]` de `audit_logs` —
     * nunca uma varredura da tabela de auditoria inteira, que também guarda o
     * histórico de todo o resto do sistema.
     *
     * @param  Collection<int, int>  $idsAprovados
     * @return Collection<int, float> Mapa `budget_id => dias corridos entre envio e aprovação`.
     */
    private function duracoesDeFechamento(Collection $idsAprovados): Collection
    {
        if ($idsAprovados->isEmpty()) {
            return collect();
        }

        $logs = AuditLog::query()
            ->where('auditable_type', (new Budget)->getMorphClass())
            ->whereIn('auditable_id', $idsAprovados)
            ->where('acao', 'alterado')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['auditable_id', 'valores_depois', 'created_at']);

        $enviadoEm = [];
        $aprovadoEm = [];

        foreach ($logs as $log) {
            $status = $log->valores_depois['status'] ?? null;

            if ($status === null) {
                continue;
            }

            $id = $log->auditable_id;

            if ($status === 'sent' && ! isset($enviadoEm[$id])) {
                $enviadoEm[$id] = $log->created_at;
            }

            if (in_array($status, self::STATUS_APROVADOS, true) && ! isset($aprovadoEm[$id])) {
                $aprovadoEm[$id] = $log->created_at;
            }
        }

        $duracoes = [];

        foreach ($aprovadoEm as $id => $instanteAprovado) {
            if (! isset($enviadoEm[$id]) || $instanteAprovado->lessThan($enviadoEm[$id])) {
                // Sem os dois instantes, ou aprovação registrada antes do
                // envio (dado inconsistente): fora da média, nunca inventado.
                continue;
            }

            $duracoes[$id] = $enviadoEm[$id]->diffInSeconds($instanteAprovado) / 86400;
        }

        return collect($duracoes);
    }

    // -----------------------------------------------------------------
    // Agregação: geral, por pessoa e por mês, todas sobre a mesma base
    // -----------------------------------------------------------------

    /**
     * Os quatro indicadores para um grupo de orçamentos: enviados, conversão
     * (com contagem ao lado, omitida com amostra pequena), ticket médio dos
     * aprovados e tempo médio de fechamento.
     *
     * @param  Collection<int, object{id: int, status: string, valor: float}>  $orcamentos
     * @param  Collection<int, float>  $duracoes  Mapa `budget_id => dias`, já calculado para o período inteiro.
     * @return array<string, mixed>
     */
    private function resumoDoGrupo(Collection $orcamentos, Collection $duracoes): array
    {
        $enviados = $orcamentos->count();
        $aprovadosLinhas = $orcamentos->filter(
            fn (object $orcamento): bool => in_array($orcamento->status, self::STATUS_APROVADOS, true)
        );
        $aprovados = $aprovadosLinhas->count();
        $amostraSuficiente = $enviados >= self::MINIMO_DE_ORCAMENTOS_PARA_CONVERSAO;

        $duracoesDoGrupo = $aprovadosLinhas
            ->map(fn (object $orcamento) => $duracoes->get($orcamento->id))
            ->filter(fn ($dias): bool => $dias !== null);

        return [
            'enviados' => $enviados,
            'aprovados' => $aprovados,
            'conversao' => $amostraSuficiente ? round($aprovados / $enviados * 100, 1) : null,
            'conversao_omitida' => ! $amostraSuficiente,
            'ticket_medio' => $aprovados > 0 ? round($aprovadosLinhas->sum('valor') / $aprovados, 2) : null,
            'amostra_ticket_medio' => $aprovados,
            'tempo_medio_fechamento_dias' => $duracoesDoGrupo->isNotEmpty() ? round($duracoesDoGrupo->avg(), 1) : null,
            'amostra_tempo_fechamento' => $duracoesDoGrupo->count(),
        ];
    }

    /**
     * O resumo, quebrado por vendedor. Orçamento sem vendedor
     * (`user_id` nulo) não entra aqui — não há "pessoa" a quem atribuir.
     *
     * @param  Collection<int, object{user_id: int|null, usuario: string|null}>  $orcamentos
     * @param  Collection<int, float>  $duracoes
     * @return array<int, array<string, mixed>>
     */
    private function porPessoa(Collection $orcamentos, Collection $duracoes): array
    {
        return $orcamentos
            ->filter(fn (object $orcamento): bool => $orcamento->user_id !== null)
            ->groupBy('user_id')
            ->map(function (Collection $grupo, int $userId) use ($duracoes): array {
                return array_merge(
                    ['user_id' => $userId, 'usuario' => $grupo->first()->usuario],
                    $this->resumoDoGrupo($grupo, $duracoes)
                );
            })
            ->sortByDesc('enviados')
            ->values()
            ->all();
    }

    /**
     * O resumo, quebrado por mês de competência (`budgets.date`), do mais
     * antigo para o mais recente. Mesmo critério de
     * `SatisfactionSurveyService::porPeriodo()`: mês sem orçamento não
     * aparece.
     *
     * @param  Collection<int, object{data: string}>  $orcamentos
     * @param  Collection<int, float>  $duracoes
     * @return array<int, array<string, mixed>>
     */
    private function porMes(Collection $orcamentos, Collection $duracoes): array
    {
        $grupos = $orcamentos->groupBy(
            fn (object $orcamento): string => CarbonImmutable::parse($orcamento->data)->format('Y-m')
        );

        return $grupos->keys()->sort()->values()
            ->map(function (string $mes) use ($grupos, $duracoes): array {
                [$ano, $numeroDoMes] = explode('-', $mes);

                return array_merge(
                    ['periodo' => $mes, 'rotulo' => $numeroDoMes.'/'.$ano],
                    $this->resumoDoGrupo($grupos->get($mes), $duracoes)
                );
            })
            ->values()
            ->all();
    }

    // -----------------------------------------------------------------
    // Janela padrão
    // -----------------------------------------------------------------

    /**
     * Primeiro e último dia da apuração, no fuso do negócio. Datas invertidas
     * são trocadas, mesmo critério de `SatisfactionSurveyService`.
     *
     * @param  array<string, mixed>  $filtros
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function janelaDosIndicadores(array $filtros): array
    {
        $hoje = BusinessDate::hoje();

        $de = BusinessDate::paraFusoNegocio($filtros['de'] ?? null)
            ?? $hoje->startOfMonth()->subMonths(self::MESES_PADRAO - 1);

        $ate = BusinessDate::paraFusoNegocio($filtros['ate'] ?? null) ?? $hoje;

        return $de->greaterThan($ate) ? [$ate, $de] : [$de, $ate];
    }
}
