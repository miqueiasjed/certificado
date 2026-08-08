<?php

namespace App\Services\Commercial;

use App\Models\Budget;
use App\Models\Goal;
use App\Models\ReceivableInstallment;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\BusinessDate;
use App\Support\TenantAtual;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Acompanhamento de metas por pessoa (Plano 23, Task 23.3): alvo, realizado e
 * porcentagem de atingimento de cada `Goal` de uma competência, com a
 * projeção para o fim do período quando já existe dado suficiente para
 * projetar sem virar ruído.
 *
 * ## Realizado usa a mesma base da comissão
 *
 * `valor_vendido` = soma dos orçamentos aprovados; `valor_recebido` = soma das
 * parcelas baixadas; `quantidade_os` = ordens de serviço concluídas;
 * `conversao` = aprovados sobre enviados. São as mesmas quatro definições que
 * `CommissionCalculationService` (Task 23.2) usa para apurar comissão, porque
 * é o mesmo fato de negócio olhado por dois ângulos: quanto a pessoa ganha e
 * quanto ela já entregou da meta.
 *
 * ## O elo que falta no schema entre parcela recebida e vendedor
 *
 * `budgets.user_id` identifica quem vendeu, mas nenhuma tabela financeira
 * (`receivables`, `receivable_installments`) referencia o orçamento que
 * originou a cobrança: o título nasce da OS ou do contrato (Plano 18, Task
 * 18.3), nunca do orçamento. Não existe, hoje, um caminho de chave estrangeira
 * de "parcela paga" até "vendedor".
 *
 * `valorRecebidoPorPessoa()` faz a ponte pelo cliente: atribui o valor
 * recebido ao vendedor do orçamento aprovado/convertido MAIS RECENTE daquele
 * cliente. É uma aproximação deliberada, documentada aqui para não ser lida
 * como acidente: a alternativa de somar por TODOS os vendedores que já
 * venderam para aquele cliente contaria o mesmo real duas vezes quando dois
 * vendedores diferentes atenderam o mesmo cliente em momentos diferentes, o
 * que é pior que uma atribuição aproximada. Quando a Task 23.2 (ou uma futura)
 * criar um vínculo direto, esta função é o primeiro lugar a atualizar.
 *
 * ## Projeção só a partir do quinto dia útil
 *
 * `BusinessDate` resolve fuso e "hoje" do negócio, mas não tem calendário de
 * feriado (é utilitário de infraestrutura, deliberadamente sem regra de
 * domínio); "dia útil" aqui é segunda a sexta. Projetar o mês inteiro a partir
 * de um ou dois dias de dado é ruído, e é exatamente o que leva um gestor a
 * cobrar a equipe por um número que ainda não significa nada.
 *
 * ## Meta de conversão não recebe projeção
 *
 * As outras três metas são quantidades cumulativas (o valor vendido de hoje se
 * soma ao de ontem), e por isso escalar por "dias corridos ÷ dias do período"
 * faz sentido. Taxa de conversão não se acumula: projetar uma taxa pela
 * proporção de dias já passados produziria um número sem significado (ex.:
 * 20% de conversão em 10 dos 30 dias do mês NÃO vira "60% até o fim do mês").
 * A meta de conversão mostra sempre o realizado atual, sem projeção.
 */
class GoalService
{
    /**
     * Status de orçamento tratados como "aprovado" para toda apuração desta
     * classe. Inclui `converted` porque um orçamento aprovado que já virou
     * cliente/OS continua sendo uma venda aprovada; excluí-lo faria o valor
     * vendido do mês sumir assim que a operação avançasse o orçamento.
     *
     * @var array<int, string>
     */
    public const STATUS_APROVADOS = ['approved', 'converted'];

    /**
     * Orçamento que nunca saiu do rascunho não é uma tentativa de venda.
     */
    public const STATUS_RASCUNHO = 'draft';

    /**
     * Orçamentos no período são necessários para a taxa de conversão não virar
     * ilusão. Mesma regra e mesmo número da pesquisa de satisfação do Plano 16
     * (`SatisfactionSurveyService::MINIMO_DE_RESPOSTAS_PARA_MEDIA`): vendedor
     * com dois orçamentos no mês não é comparável a quem teve trinta.
     */
    public const MINIMO_DE_ORCAMENTOS_PARA_CONVERSAO = 3;

    /**
     * Dia útil da competência a partir do qual a projeção passa a aparecer.
     */
    public const DIA_UTIL_MINIMO_PARA_PROJECAO = 5;

    /**
     * Acompanhamento das metas de uma competência, por pessoa e por tipo.
     *
     * Sem `$usuario`, traz a meta de todas as pessoas da empresa corrente
     * (escopo de `Goal` já filtra por `company_id`). Com `$usuario`, só as
     * metas dela — uso típico de uma tela "minhas metas".
     *
     * @return array{
     *     competencia: string,
     *     periodo: array{de: string, ate: string},
     *     dia_util_minimo_para_projecao: int,
     *     projecao_disponivel: bool,
     *     metas: array<int, array<string, mixed>>
     * }
     *
     * @throws InvalidArgumentException Competência fora do formato AAAA-MM.
     */
    public function acompanhamento(string $competencia, ?User $usuario = null): array
    {
        [$inicio, $fim] = $this->periodoDaCompetencia($competencia);

        $metas = Goal::query()
            ->where('competencia', $competencia)
            ->when($usuario !== null, fn (Builder $consulta) => $consulta->where('user_id', $usuario->id))
            ->with('user:id,name')
            ->orderBy('user_id')
            ->orderBy('tipo')
            ->get();

        $tiposPresentes = $metas->pluck('tipo')->unique();

        $realizadoPorTipo = [];
        foreach (Goal::TIPOS as $tipo) {
            if (! $tiposPresentes->contains($tipo)) {
                continue;
            }

            $realizadoPorTipo[$tipo] = match ($tipo) {
                'valor_vendido' => $this->valorVendidoPorPessoa($inicio, $fim),
                'valor_recebido' => $this->valorRecebidoPorPessoa($inicio, $fim),
                'quantidade_os' => $this->quantidadeOsPorPessoa($inicio, $fim),
                'conversao' => $this->conversaoPorPessoa($inicio, $fim),
            };
        }

        [$diasCorridos, $diasDoPeriodo, $projecaoDisponivel] = $this->janelaDeProjecao($inicio, $fim);

        $linhas = $metas
            ->map(fn (Goal $meta) => $this->linhaDoAcompanhamento(
                $meta,
                $realizadoPorTipo[$meta->tipo],
                $diasCorridos,
                $diasDoPeriodo,
                $projecaoDisponivel
            ))
            ->values()
            ->all();

        return [
            'competencia' => $competencia,
            'periodo' => ['de' => $inicio->toDateString(), 'ate' => $fim->toDateString()],
            'dia_util_minimo_para_projecao' => self::DIA_UTIL_MINIMO_PARA_PROJECAO,
            'projecao_disponivel' => $projecaoDisponivel,
            'metas' => $linhas,
        ];
    }

    // -----------------------------------------------------------------
    // Montagem da linha de acompanhamento
    // -----------------------------------------------------------------

    /**
     * @param  Collection<int|string, mixed>  $realizadoDoTipo  Coleção específica do tipo desta meta:
     *                                                           mapa `user_id => valor` para os tipos cumulativos, ou
     *                                                           mapa `user_id => {enviados, aprovados}` para conversão.
     * @return array<string, mixed>
     */
    private function linhaDoAcompanhamento(
        Goal $meta,
        Collection $realizadoDoTipo,
        float $diasCorridos,
        int $diasDoPeriodo,
        bool $projecaoDisponivel
    ): array {
        $alvo = (float) $meta->alvo;

        $linha = [
            'goal_id' => $meta->id,
            'user_id' => $meta->user_id,
            'usuario' => $meta->user?->name,
            'tipo' => $meta->tipo,
            'alvo' => $alvo,
        ];

        if ($meta->tipo === 'conversao') {
            return array_merge($linha, $this->realizadoDeConversao($realizadoDoTipo->get($meta->user_id), $alvo));
        }

        $realizado = (float) ($realizadoDoTipo->get($meta->user_id) ?? 0);

        return array_merge($linha, [
            'realizado' => round($realizado, 2),
            'percentual_atingido' => $alvo > 0 ? round($realizado / $alvo * 100, 1) : null,
            'projecao' => ($projecaoDisponivel && $diasCorridos > 0)
                ? round($realizado / $diasCorridos * $diasDoPeriodo, 2)
                : null,
        ]);
    }

    /**
     * Campos da linha de uma meta de conversão: sempre com enviados/aprovados
     * ao lado, taxa omitida com amostra pequena, e nunca projeção (ver o
     * docblock da classe).
     *
     * @return array<string, mixed>
     */
    private function realizadoDeConversao(mixed $dados, float $alvo): array
    {
        $enviados = (int) ($dados->enviados ?? 0);
        $aprovados = (int) ($dados->aprovados ?? 0);
        $amostraSuficiente = $enviados >= self::MINIMO_DE_ORCAMENTOS_PARA_CONVERSAO;
        $realizado = $amostraSuficiente ? round($aprovados / $enviados * 100, 1) : null;

        return [
            'enviados' => $enviados,
            'aprovados' => $aprovados,
            'amostra_insuficiente' => ! $amostraSuficiente,
            'realizado' => $realizado,
            'percentual_atingido' => ($realizado !== null && $alvo > 0) ? round($realizado / $alvo * 100, 1) : null,
            'projecao' => null,
        ];
    }

    // -----------------------------------------------------------------
    // Realizado por tipo de meta, agregado no banco
    // -----------------------------------------------------------------

    /**
     * Valor vendido por pessoa: soma dos orçamentos aprovados/convertidos no
     * período, por `user_id`. O valor do orçamento é a soma dos serviços
     * (`budget_services.subtotal`, o mesmo total usado no PDF do orçamento,
     * ver `resources/views/budgets/pdf.blade.php`) menos o desconto; produto
     * sem preço no pivot (`budget_products` não guarda `unit_price`) não entra
     * na conta, limitação do cadastro atual, não desta apuração.
     *
     * O desconto é por orçamento, não por serviço: por isso a agregação é em
     * duas etapas (soma dos serviços por orçamento primeiro, soma dos
     * orçamentos por pessoa depois) — somar `budget_services.subtotal` direto
     * após o join e subtrair `budgets.discount` uma vez só descontaria de
     * menos (ou de mais) proporcional à quantidade de serviços do orçamento.
     *
     * @return Collection<int, float> Mapa `user_id => valor`.
     */
    private function valorVendidoPorPessoa(CarbonImmutable $inicio, CarbonImmutable $fim): Collection
    {
        $porOrcamento = Budget::query()
            ->join('budget_services', 'budget_services.budget_id', '=', 'budgets.id')
            ->whereIn('budgets.status', self::STATUS_APROVADOS)
            ->whereNotNull('budgets.user_id')
            ->whereBetween('budgets.date', [$inicio->toDateString(), $fim->toDateString()])
            ->groupBy('budgets.id', 'budgets.user_id', 'budgets.discount')
            ->select([
                'budgets.user_id as user_id',
                DB::raw('SUM(budget_services.subtotal) - budgets.discount as valor'),
            ]);

        return DB::query()
            ->fromSub($porOrcamento, 'por_orcamento')
            ->groupBy('user_id')
            ->selectRaw('user_id, SUM(valor) as total')
            ->pluck('total', 'user_id')
            ->map(fn ($valor): float => (float) $valor);
    }

    /**
     * Valor recebido por pessoa: soma das parcelas baixadas no período,
     * atribuídas ao vendedor do orçamento aprovado/convertido mais recente do
     * cliente da parcela. Ver o docblock da classe para o motivo da
     * aproximação: não existe, hoje, vínculo direto entre parcela e orçamento.
     *
     * @return Collection<int, float> Mapa `user_id => valor`.
     */
    private function valorRecebidoPorPessoa(CarbonImmutable $inicio, CarbonImmutable $fim): Collection
    {
        $statusAprovados = self::STATUS_APROVADOS;
        $marcadores = implode(',', array_fill(0, count($statusAprovados), '?'));

        $vendedorMaisRecentePorCliente = Budget::query()
            ->whereIn('status', $statusAprovados)
            ->whereNotNull('client_id')
            ->whereNotNull('user_id')
            ->whereRaw(
                "id = (select max(b2.id) from budgets b2 where b2.client_id = budgets.client_id and b2.status in ({$marcadores}))",
                $statusAprovados
            )
            ->select(['client_id', 'user_id']);

        $consulta = ReceivableInstallment::query()
            ->join('receivables', 'receivables.id', '=', 'receivable_installments.receivable_id')
            ->joinSub($vendedorMaisRecentePorCliente, 'vendedor_do_cliente', function ($join): void {
                $join->on('vendedor_do_cliente.client_id', '=', 'receivables.client_id');
            })
            ->whereNotNull('receivable_installments.pago_em')
            ->whereBetween('receivable_installments.pago_em', [$inicio->toDateString(), $fim->toDateString()]);

        $consulta = $this->comEscopoDeEmpresa($consulta, 'receivables');

        return $consulta
            ->groupBy('vendedor_do_cliente.user_id')
            ->selectRaw('vendedor_do_cliente.user_id as user_id, SUM(receivable_installments.valor_pago) as total')
            ->pluck('total', 'user_id')
            ->map(fn ($valor): float => (float) $valor);
    }

    /**
     * Quantidade de OS concluída por pessoa, quando a pessoa é um técnico
     * (`User::technician()`). Data de conclusão: `end_time` quando existe,
     * senão `scheduled_date` — mesmo critério de
     * `SatisfactionSurveyService::convidar()` para "quando a visita aconteceu".
     *
     * @return Collection<int, int> Mapa `user_id => quantidade`.
     */
    private function quantidadeOsPorPessoa(CarbonImmutable $inicio, CarbonImmutable $fim): Collection
    {
        $fuso = $this->fusoDaAplicacao();
        $inicioInstante = $inicio->startOfDay()->setTimezone($fuso);
        $fimInstante = $fim->endOfDay()->setTimezone($fuso);
        $inicioDia = $inicio->toDateString();
        $fimDia = $fim->toDateString();

        $consulta = WorkOrder::query()
            ->join('technicians', 'technicians.id', '=', 'work_orders.technician_id')
            ->completed()
            ->whereNotNull('technicians.user_id')
            ->where(function (Builder $filtroDeData) use ($inicioInstante, $fimInstante, $inicioDia, $fimDia): void {
                $filtroDeData->where(function (Builder $comFim) use ($inicioInstante, $fimInstante): void {
                    $comFim->whereNotNull('work_orders.end_time')
                        ->whereBetween('work_orders.end_time', [$inicioInstante, $fimInstante]);
                })->orWhere(function (Builder $semFim) use ($inicioDia, $fimDia): void {
                    $semFim->whereNull('work_orders.end_time')
                        ->whereBetween('work_orders.scheduled_date', [$inicioDia, $fimDia]);
                });
            });

        $consulta = $this->comEscopoDeEmpresa($consulta, 'technicians');

        return $consulta
            ->groupBy('technicians.user_id')
            ->selectRaw('technicians.user_id as user_id, COUNT(*) as total')
            ->pluck('total', 'user_id')
            ->map(fn ($valor): int => (int) $valor);
    }

    /**
     * Enviados e aprovados por pessoa, base da meta de conversão. "Enviado" é
     * todo orçamento que saiu do rascunho (`status <> draft`), independente do
     * status atual: recusado e expirado também foram, de fato, enviados.
     *
     * @return Collection<int, object{enviados: int, aprovados: int}> Mapa `user_id => {enviados, aprovados}`.
     */
    private function conversaoPorPessoa(CarbonImmutable $inicio, CarbonImmutable $fim): Collection
    {
        return Budget::query()
            ->whereNotNull('user_id')
            ->where('status', '!=', self::STATUS_RASCUNHO)
            ->whereBetween('date', [$inicio->toDateString(), $fim->toDateString()])
            ->groupBy('user_id')
            ->selectRaw(
                'user_id, COUNT(*) as enviados, SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) as aprovados',
                self::STATUS_APROVADOS
            )
            ->get()
            ->keyBy('user_id');
    }

    // -----------------------------------------------------------------
    // Janela da competência e do quinto dia útil
    // -----------------------------------------------------------------

    /**
     * Primeiro e último dia da competência, no fuso do negócio.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     *
     * @throws InvalidArgumentException Formato diferente de AAAA-MM.
     */
    private function periodoDaCompetencia(string $competencia): array
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $competencia) !== 1) {
            throw new InvalidArgumentException(
                "Competência inválida: \"{$competencia}\". O formato esperado é AAAA-MM."
            );
        }

        $inicio = BusinessDate::paraFusoNegocio($competencia.'-01');
        $fim = $inicio->endOfMonth()->startOfDay();

        return [$inicio, $fim];
    }

    /**
     * Dias corridos já passados na competência, dias do período inteiro, e se
     * a projeção já pode aparecer (quinto dia útil já alcançado).
     *
     * A referência de "hoje" nunca ultrapassa o fim do período: numa
     * competência já fechada (mês passado), o período inteiro já aconteceu, e
     * a projeção coincide com o realizado, sem extrapolar. Numa competência
     * futura, não há um dia sequer de dado, e a projeção fica indisponível.
     *
     * @return array{0: float, 1: int, 2: bool}
     */
    private function janelaDeProjecao(CarbonImmutable $inicio, CarbonImmutable $fim): array
    {
        $diasDoPeriodo = $inicio->diffInDays($fim) + 1;
        $hoje = BusinessDate::hoje();
        $referencia = $hoje->lessThan($fim) ? $hoje : $fim;

        if ($referencia->lessThan($inicio)) {
            return [0.0, $diasDoPeriodo, false];
        }

        $diasCorridos = (float) ($inicio->diffInDays($referencia) + 1);
        $diasUteis = $this->diasUteisEntre($inicio, $referencia);

        return [$diasCorridos, $diasDoPeriodo, $diasUteis >= self::DIA_UTIL_MINIMO_PARA_PROJECAO];
    }

    /**
     * Quantidade de dias úteis (segunda a sexta) entre duas datas, inclusive.
     * Sem calendário de feriado: ver o docblock da classe.
     */
    private function diasUteisEntre(CarbonImmutable $inicio, CarbonImmutable $fim): int
    {
        $total = 0;
        $dia = $inicio;

        while (! $dia->greaterThan($fim)) {
            if ($dia->isWeekday()) {
                $total++;
            }

            $dia = $dia->addDay();
        }

        return $total;
    }

    // -----------------------------------------------------------------
    // Infraestrutura
    // -----------------------------------------------------------------

    /**
     * Acrescenta o filtro por empresa corrente numa tabela alcançada por
     * `join()` bruto, que não passa pelo escopo global do Eloquent do model
     * dono da tabela (o escopo só se aplica ao model da consulta base). Sem
     * tenant resolvido, não filtra nada — mesmo critério de
     * `BelongsToCompany`, para não quebrar comando artisan, seeder e teste
     * sem usuário autenticado.
     */
    private function comEscopoDeEmpresa(Builder $consulta, string $tabela): Builder
    {
        $empresa = TenantAtual::id();

        if ($empresa !== null) {
            $consulta->where("{$tabela}.company_id", $empresa);
        }

        return $consulta;
    }

    /**
     * Fuso em que a aplicação grava `datetime`, necessário para comparar
     * corretamente um instante (`end_time`) contra a competência informada no
     * fuso do negócio. Mesmo critério de `SatisfactionSurveyService`.
     */
    private function fusoDaAplicacao(): string
    {
        return config('app.timezone') ?: 'UTC';
    }
}
