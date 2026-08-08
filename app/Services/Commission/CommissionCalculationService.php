<?php

namespace App\Services\Commission;

use App\Models\AuditLog;
use App\Models\Budget;
use App\Models\Commission;
use App\Models\CommissionItem;
use App\Models\CommissionRule;
use App\Models\ReceivableInstallment;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\BusinessDate;
use App\Support\Dinheiro;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Apuração de comissão por competência (Plano 23, Task 23.2).
 *
 * `apurar()` percorre os fatos do mês (venda aprovada, parcela quitada, OS
 * concluída), seleciona para cada um a regra vigente na DATA DO FATO (nunca a
 * regra de hoje) e grava um `CommissionItem` com o percentual/valor aplicado
 * já congelado. Regra que muda amanhã nunca altera o que já foi apurado.
 *
 * Competência aberta é substituída por inteiro a cada chamada (os itens
 * antigos daquela pessoa são apagados e regravados do zero); competência
 * fechada ou paga é ignorada, silenciosamente, porque é imutável (ver
 * `CommissionClosingService`).
 *
 * Limitações conhecidas do modelo de dados atual (documentadas aqui, não
 * escondidas)
 * -------------------------------------------------------------------------
 * 1. **Base `recebido` sem vínculo direto ao orçamento.** Nem `WorkOrder` nem
 *    `Receivable` guardam `budget_id`: a conversão de orçamento em cliente
 *    (`BudgetService::prepareForConversion()`) não grava esse vínculo, e a OS
 *    é criada depois, à parte, sem referência ao orçamento de origem. Sem
 *    essa coluna, o vendedor de uma parcela recebida é resolvido pelo melhor
 *    proxy disponível (`resolverVendedorDoRecebimento()`): o orçamento
 *    aprovado/convertido mais recente do mesmo cliente, na data do
 *    recebimento ou antes dela. É uma aproximação sã para o caso comum (um
 *    orçamento ativo por cliente), mas erra se o mesmo cliente tiver mais de
 *    um orçamento aprovado por vendedores diferentes em períodos próximos.
 *    Corrigir de vez exige uma coluna `budget_id` nullable em `work_orders`
 *    (estrutura, sem backfill, um deploy), fora do escopo desta task.
 * 2. **Data de aprovação do orçamento.** `budgets` não tem `approved_at`. A
 *    data do fato de venda vem do próprio `AuditLog` que `Budget` já grava
 *    (`Auditavel`): o registro mais recente em que `valores_depois.status`
 *    virou `"approved"`. Sem esse log (orçamento criado já aprovado, fora do
 *    fluxo normal de `update()`), cai para `updated_at` do orçamento.
 * 3. **`commission_rules.service_type_id` não é alcançável a partir de
 *    `WorkOrder` nem de `Budget` hoje.** `work_orders.service_type_id` foi
 *    removida do schema (migration `2025_10_14_164621_remove_service_type_id_from_work_orders_table`)
 *    e `Service` não referencia `ServiceType`. Por isso, nesta apuração, todo
 *    fato é tratado como sem tipo de serviço: uma regra com
 *    `service_type_id` preenchido nunca é selecionada. Regra geral (sem
 *    restrição de serviço) continua funcionando normalmente.
 */
class CommissionCalculationService
{
    private const TIPO_VENDEDOR = 'vendedor';

    private const TIPO_TECNICO = 'tecnico';

    private const BASE_RECEBIDO = 'recebido';

    private const BASE_VENDIDO = 'vendido';

    private const BASE_EXECUTADO = 'executado';

    private const SITUACAO_ABERTA = 'aberta';

    private const SITUACAO_FECHADA = 'fechada';

    private const SITUACAO_PAGA = 'paga';

    /**
     * Status de orçamento que representam uma venda: `approved` é a
     * aprovação em si, `converted` é a mesma venda depois de virar cliente e
     * OS. Os dois contam, porque "foi aprovado" nunca deixa de ser verdade
     * só porque o orçamento avançou de estado.
     *
     * @var array<int, string>
     */
    private const STATUS_ORCAMENTO_VENDIDO = ['approved', 'converted'];

    /**
     * Apura a competência informada: para cada pessoa (vendedor ou técnico)
     * que tiver ao menos um fato do mês, ou uma comissão aberta já existente
     * daquela competência, grava os itens do zero.
     *
     * `$usuario` é quem disparou a apuração manual (tela do Plano 23), usado
     * só para contexto de log; a rotina agendada (`comissoes:apurar`) chama
     * com `null`, porque roda sem usuário autenticado.
     *
     * @return array{competencia: string, pessoas_processadas: int, itens_gravados: int, competencias_fechadas_ignoradas: int}
     */
    public function apurar(string $competencia, ?User $usuario = null): array
    {
        $limites = $this->limitesDaCompetencia($competencia);

        /** @var array<string, array<int, array<string, mixed>>> $itensPorPessoa */
        $itensPorPessoa = [];

        $this->apurarVendido($limites, $itensPorPessoa);
        $this->apurarRecebido($limites, $itensPorPessoa);
        $this->apurarExecutado($limites, $itensPorPessoa);

        // Toda comissão ABERTA já existente da competência entra na rodada,
        // mesmo sem fato novo: é o que zera os itens de quem não vendeu,
        // recebeu ou executou nada desde a última apuração, em vez de deixar
        // item velho parado ali.
        $comissoesAbertas = Commission::query()
            ->where('competencia', $competencia)
            ->where('situacao', self::SITUACAO_ABERTA)
            ->get();

        foreach ($comissoesAbertas as $comissaoAberta) {
            $chave = $this->chave($comissaoAberta->user_id, $comissaoAberta->technician_id);
            $itensPorPessoa[$chave] ??= [];
        }

        $processadas = 0;
        $itensGravados = 0;
        $ignoradas = 0;

        foreach ($itensPorPessoa as $chave => $itens) {
            [$userId, $technicianId] = $this->decompoeChave($chave);

            $resultado = DB::transaction(function () use ($userId, $technicianId, $competencia, $itens) {
                $comissao = Commission::query()->firstOrCreate(
                    ['user_id' => $userId, 'technician_id' => $technicianId, 'competencia' => $competencia],
                    ['situacao' => self::SITUACAO_ABERTA, 'valor_total' => '0.00']
                );

                if ($comissao->situacao !== self::SITUACAO_ABERTA) {
                    return ['ignorada' => true, 'itens' => 0];
                }

                $comissao->items()->delete();

                $totalEmCentavos = 0;

                foreach ($itens as $dadosDoItem) {
                    CommissionItem::create($dadosDoItem + ['commission_id' => $comissao->id]);
                    $totalEmCentavos += Dinheiro::centavos($dadosDoItem['valor']);
                }

                $comissao->update(['valor_total' => Dinheiro::paraDecimal($totalEmCentavos)]);

                return ['ignorada' => false, 'itens' => count($itens)];
            });

            if ($resultado['ignorada']) {
                $ignoradas++;

                continue;
            }

            $processadas++;
            $itensGravados += $resultado['itens'];
        }

        if ($usuario !== null) {
            Log::info('[comissao] Apuração manual concluída.', [
                'competencia' => $competencia,
                'usuario_id' => $usuario->id,
                'pessoas_processadas' => $processadas,
                'itens_gravados' => $itensGravados,
                'competencias_fechadas_ignoradas' => $ignoradas,
            ]);
        }

        return [
            'competencia' => $competencia,
            'pessoas_processadas' => $processadas,
            'itens_gravados' => $itensGravados,
            'competencias_fechadas_ignoradas' => $ignoradas,
        ];
    }

    /**
     * Trata o estorno de uma parcela baixada (Plano 18, `SettlementService::estornar()`)
     * na base `recebido`: se a comissão da competência original ainda está
     * aberta, o item é removido dela (reduzindo o total); se já está fechada
     * ou paga, gera um item negativo na competência ATUAL, com a mesma regra
     * e o mesmo percentual do item original, referenciando a parcela
     * estornada. Mexer em mês fechado nunca acontece.
     *
     * Sem item gravado para esta parcela (a competência em que ela foi paga
     * nunca chegou a ser apurada), não há o que desfazer: a próxima apuração
     * da competência original simplesmente não vai mais encontrar esta
     * parcela como paga.
     */
    public function tratarEstornoDeParcela(ReceivableInstallment $parcela, User $usuario): void
    {
        $item = CommissionItem::query()
            ->where('origem_tipo', CommissionItem::ORIGENS[1])
            ->where('origem_id', $parcela->id)
            ->orderByDesc('id')
            ->first();

        if ($item === null) {
            return;
        }

        $comissao = $item->commission;

        if ($comissao === null) {
            return;
        }

        if ($comissao->situacao === self::SITUACAO_ABERTA) {
            DB::transaction(function () use ($item, $comissao) {
                $item->delete();

                $novoTotal = $comissao->items()->get()->sum(fn (CommissionItem $i) => Dinheiro::centavos($i->valor));
                $comissao->update(['valor_total' => Dinheiro::paraDecimal($novoTotal)]);
            });

            return;
        }

        $competenciaAtual = BusinessDate::hoje()->format('Y-m');

        $comissaoAtual = Commission::query()->firstOrCreate(
            ['user_id' => $comissao->user_id, 'technician_id' => $comissao->technician_id, 'competencia' => $competenciaAtual],
            ['situacao' => self::SITUACAO_ABERTA, 'valor_total' => '0.00']
        );

        if ($comissaoAtual->situacao !== self::SITUACAO_ABERTA) {
            // A comissão do mês corrente também já está fechada. Não existe
            // onde lançar o ajuste automaticamente; fica registrado para
            // alguém tratar a mão, em vez de silenciosamente não ajustar
            // nada nem quebrar o estorno (que já terminou com sucesso).
            Log::warning('[comissao] Estorno de parcela em competência fechada, mas a competência atual também está fechada: ajuste não lançado.', [
                'receivable_installment_id' => $parcela->id,
                'commission_item_id' => $item->id,
                'competencia_original' => $comissao->competencia,
                'competencia_atual' => $competenciaAtual,
                'usuario_id' => $usuario->id,
            ]);

            return;
        }

        DB::transaction(function () use ($item, $comissaoAtual, $parcela) {
            CommissionItem::create([
                'commission_id' => $comissaoAtual->id,
                'origem_tipo' => CommissionItem::ORIGENS[1],
                'origem_id' => $parcela->id,
                'commission_rule_id' => $item->commission_rule_id,
                'base_valor' => Dinheiro::paraDecimal(-1 * Dinheiro::centavos($item->base_valor)),
                'percentual_ou_fixo' => $item->percentual_ou_fixo,
                'valor' => Dinheiro::paraDecimal(-1 * Dinheiro::centavos($item->valor)),
                'ocorrido_em' => BusinessDate::hoje()->toDateString(),
            ]);

            $novoTotal = $comissaoAtual->items()->get()->sum(fn (CommissionItem $i) => Dinheiro::centavos($i->valor));
            $comissaoAtual->update(['valor_total' => Dinheiro::paraDecimal($novoTotal)]);
        });
    }

    // -----------------------------------------------------------------
    // Base "vendido": orçamentos aprovados no período
    // -----------------------------------------------------------------

    /**
     * @param  array{primeiro_dia: string, ultimo_dia: string, inicio_instante: CarbonImmutable, fim_instante: CarbonImmutable}  $limites
     * @param  array<string, array<int, array<string, mixed>>>  $itensPorPessoa
     */
    private function apurarVendido(array $limites, array &$itensPorPessoa): void
    {
        $orcamentos = Budget::query()
            ->whereIn('status', self::STATUS_ORCAMENTO_VENDIDO)
            ->whereNotNull('user_id')
            ->get();

        foreach ($orcamentos as $orcamento) {
            $instante = $this->instanteDeAprovacao($orcamento);

            if ($instante === null || $instante->lt($limites['inicio_instante']) || $instante->gt($limites['fim_instante'])) {
                continue;
            }

            $dia = BusinessDate::diaDe($instante);
            $regra = $this->regraVigente(self::TIPO_VENDEDOR, self::BASE_VENDIDO, (int) $orcamento->user_id, null, null, $dia);

            if ($regra === null) {
                continue;
            }

            $baseValor = $this->valorDoOrcamento($orcamento);
            $valor = $this->calcularValor($regra, $baseValor);

            $itensPorPessoa[$this->chaveVendedor((int) $orcamento->user_id)][] = [
                'origem_tipo' => CommissionItem::ORIGENS[0],
                'origem_id' => $orcamento->id,
                'commission_rule_id' => $regra->id,
                'base_valor' => $baseValor,
                'percentual_ou_fixo' => (string) $regra->valor,
                'valor' => $valor,
                'ocorrido_em' => $dia,
            ];
        }
    }

    /**
     * Valor vendido do orçamento: soma dos serviços (`budget_services.subtotal`,
     * já congelado no orçamento) menos o desconto. Produtos ficam de fora: a
     * tabela `budget_products` não guarda preço nenhum (só quantidade), então
     * não existe valor confiável para somar.
     */
    private function valorDoOrcamento(Budget $orcamento): string
    {
        $valorServicos = (string) $orcamento->services()->sum('budget_services.subtotal');
        $centavos = Dinheiro::centavos($valorServicos) - Dinheiro::centavos($orcamento->discount ?? '0');

        return Dinheiro::paraDecimal(max($centavos, 0));
    }

    /**
     * Instante em que o orçamento foi aprovado, pelo `AuditLog` que `Budget`
     * já grava (trait `Auditavel`): o registro mais recente em que
     * `valores_depois.status` virou `"approved"`. Sem esse log, cai para
     * `updated_at` do próprio orçamento. Ver o cabeçalho da classe.
     */
    private function instanteDeAprovacao(Budget $orcamento): ?CarbonImmutable
    {
        $log = AuditLog::query()
            ->where('auditable_type', Budget::class)
            ->where('auditable_id', $orcamento->id)
            ->where('acao', 'alterado')
            ->where('valores_depois->status', 'approved')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if ($log !== null && $log->created_at !== null) {
            return CarbonImmutable::instance($log->created_at)->utc();
        }

        return $orcamento->updated_at !== null
            ? CarbonImmutable::instance($orcamento->updated_at)->utc()
            : null;
    }

    // -----------------------------------------------------------------
    // Base "recebido": parcelas baixadas no período
    // -----------------------------------------------------------------

    /**
     * @param  array{primeiro_dia: string, ultimo_dia: string, inicio_instante: CarbonImmutable, fim_instante: CarbonImmutable}  $limites
     * @param  array<string, array<int, array<string, mixed>>>  $itensPorPessoa
     */
    private function apurarRecebido(array $limites, array &$itensPorPessoa): void
    {
        $parcelas = ReceivableInstallment::query()
            ->where('situacao', 'paga')
            ->whereBetween('pago_em', [$limites['primeiro_dia'], $limites['ultimo_dia']])
            ->get();

        foreach ($parcelas as $parcela) {
            $vendedorId = $this->resolverVendedorDoRecebimento($parcela);

            if ($vendedorId === null) {
                continue;
            }

            $dia = BusinessDate::diaDe($parcela->pago_em);
            $regra = $this->regraVigente(self::TIPO_VENDEDOR, self::BASE_RECEBIDO, $vendedorId, null, null, $dia);

            if ($regra === null) {
                continue;
            }

            $valor = $this->calcularValor($regra, $parcela->valor);

            $itensPorPessoa[$this->chaveVendedor($vendedorId)][] = [
                'origem_tipo' => CommissionItem::ORIGENS[1],
                'origem_id' => $parcela->id,
                'commission_rule_id' => $regra->id,
                'base_valor' => $parcela->valor,
                'percentual_ou_fixo' => (string) $regra->valor,
                'valor' => $valor,
                'ocorrido_em' => $dia,
            ];
        }
    }

    /**
     * Vendedor da parcela recebida, pelo melhor proxy disponível hoje: o
     * orçamento aprovado/convertido mais recente do mesmo cliente, na data do
     * recebimento ou antes dela. Ver a limitação nº 1 no cabeçalho da classe.
     */
    private function resolverVendedorDoRecebimento(ReceivableInstallment $parcela): ?int
    {
        $titulo = $parcela->receivable()->first();

        if ($titulo === null || $titulo->client_id === null) {
            return null;
        }

        $dataDoRecebimento = BusinessDate::diaDe($parcela->pago_em);

        $orcamento = Budget::query()
            ->where('client_id', $titulo->client_id)
            ->whereIn('status', self::STATUS_ORCAMENTO_VENDIDO)
            ->whereNotNull('user_id')
            ->where(function ($consulta) use ($dataDoRecebimento) {
                $consulta->whereNull('date')->orWhere('date', '<=', $dataDoRecebimento);
            })
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->first();

        return $orcamento !== null ? (int) $orcamento->user_id : null;
    }

    // -----------------------------------------------------------------
    // Base "executado": ordens de serviço concluídas no período
    // -----------------------------------------------------------------

    /**
     * @param  array{primeiro_dia: string, ultimo_dia: string, inicio_instante: CarbonImmutable, fim_instante: CarbonImmutable}  $limites
     * @param  array<string, array<int, array<string, mixed>>>  $itensPorPessoa
     */
    private function apurarExecutado(array $limites, array &$itensPorPessoa): void
    {
        $ordens = WorkOrder::query()
            ->where('status', 'completed')
            ->whereNotNull('technician_id')
            ->where(function ($consulta) use ($limites) {
                $consulta->whereBetween('end_time', [$limites['inicio_instante'], $limites['fim_instante']])
                    ->orWhere(function ($semFim) use ($limites) {
                        $semFim->whereNull('end_time')
                            ->whereBetween('scheduled_date', [$limites['primeiro_dia'], $limites['ultimo_dia']]);
                    });
            })
            ->get();

        foreach ($ordens as $os) {
            $dia = $os->end_time !== null ? BusinessDate::diaDe($os->end_time) : BusinessDate::diaDe($os->scheduled_date);

            if ($dia === null) {
                continue;
            }

            // Sem tipo de serviço resolvível a partir da OS hoje (ver a
            // limitação nº 3 no cabeçalho da classe): só a regra geral, sem
            // restrição de serviço, pode ser selecionada aqui.
            $regra = $this->regraVigente(self::TIPO_TECNICO, self::BASE_EXECUTADO, null, (int) $os->technician_id, null, $dia);

            if ($regra === null) {
                continue;
            }

            $baseValor = (string) ($os->final_amount ?? $os->total_cost ?? '0.00');
            $valor = $this->calcularValor($regra, $baseValor);

            $itensPorPessoa[$this->chaveTecnico((int) $os->technician_id)][] = [
                'origem_tipo' => CommissionItem::ORIGENS[2],
                'origem_id' => $os->id,
                'commission_rule_id' => $regra->id,
                'base_valor' => $baseValor,
                'percentual_ou_fixo' => (string) $regra->valor,
                'valor' => $valor,
                'ocorrido_em' => $dia,
            ];
        }
    }

    // -----------------------------------------------------------------
    // Regra vigente na data do fato
    // -----------------------------------------------------------------

    /**
     * A regra vigente para a pessoa e a base informadas, na data do fato.
     * Nunca a regra de hoje: `vigencia_inicio`/`vigencia_fim` são comparadas
     * contra `$dia`, a data do fato (venda, recebimento ou execução).
     *
     * Entre candidatas, a mais específica vence: regra da pessoa antes de
     * regra geral, regra com tipo de serviço antes de regra sem restrição;
     * empatada a especificidade, a de vigência mais recente vence.
     */
    private function regraVigente(
        string $tipo,
        string $base,
        ?int $userId,
        ?int $technicianId,
        ?int $serviceTypeId,
        string $dia,
    ): ?CommissionRule {
        $consulta = CommissionRule::query()
            ->where('tipo', $tipo)
            ->where('base', $base)
            ->where('ativa', true)
            ->where('vigencia_inicio', '<=', $dia)
            ->where(function ($q) use ($dia) {
                $q->whereNull('vigencia_fim')->orWhere('vigencia_fim', '>=', $dia);
            });

        if ($tipo === self::TIPO_VENDEDOR) {
            $consulta->where(function ($q) use ($userId) {
                $q->where('user_id', $userId)->orWhereNull('user_id');
            });
        } else {
            $consulta->where(function ($q) use ($technicianId) {
                $q->where('technician_id', $technicianId)->orWhereNull('technician_id');
            });
        }

        $candidatas = $consulta->get()
            ->filter(fn (CommissionRule $regra): bool => $regra->service_type_id === null || $regra->service_type_id === $serviceTypeId);

        if ($candidatas->isEmpty()) {
            return null;
        }

        return $candidatas
            ->sortByDesc(fn (CommissionRule $regra): array => $this->pesoDeEspecificidade($regra, $tipo))
            ->first();
    }

    /**
     * @return array<int, int|string>
     */
    private function pesoDeEspecificidade(CommissionRule $regra, string $tipo): array
    {
        $pessoaEspecifica = $tipo === self::TIPO_VENDEDOR ? $regra->user_id !== null : $regra->technician_id !== null;

        return [
            $pessoaEspecifica ? 1 : 0,
            $regra->service_type_id !== null ? 1 : 0,
            $regra->vigencia_inicio->format('Y-m-d'),
            $regra->id,
        ];
    }

    /**
     * Valor da comissão para a regra e a base informadas. `valor_fixo` é o
     * próprio valor da regra, sem depender do valor-base do fato;
     * `percentual` aplica o percentual (com até 4 casas decimais) sobre o
     * valor-base, em centavos.
     */
    private function calcularValor(CommissionRule $regra, string $baseValor): string
    {
        if ($regra->forma === 'valor_fixo') {
            return Dinheiro::paraDecimal(Dinheiro::centavos($regra->valor));
        }

        $baseEmCentavos = Dinheiro::centavos($baseValor);
        $percentual = (float) $regra->valor;

        $centavosDaComissao = (int) round($baseEmCentavos * $percentual / 100);

        return Dinheiro::paraDecimal($centavosDaComissao);
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function chaveVendedor(int $userId): string
    {
        return $this->chave($userId, null);
    }

    private function chaveTecnico(int $technicianId): string
    {
        return $this->chave(null, $technicianId);
    }

    private function chave(?int $userId, ?int $technicianId): string
    {
        return sprintf('%s:%s', $userId ?? 'null', $technicianId ?? 'null');
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    private function decompoeChave(string $chave): array
    {
        [$userId, $technicianId] = explode(':', $chave);

        return [
            $userId === 'null' ? null : (int) $userId,
            $technicianId === 'null' ? null : (int) $technicianId,
        ];
    }

    /**
     * Limites da competência, em dois formatos: dia puro (`Y-m-d`, para
     * comparar colunas `date` como `pago_em` e `scheduled_date`, sem
     * conversão de fuso) e instante em UTC (para comparar colunas `datetime`
     * como `end_time`, convertidos a partir da meia-noite e do fim do dia no
     * fuso do negócio).
     *
     * @return array{primeiro_dia: string, ultimo_dia: string, inicio_instante: CarbonImmutable, fim_instante: CarbonImmutable}
     */
    private function limitesDaCompetencia(string $competencia): array
    {
        [$ano, $mes] = $this->partesDaCompetencia($competencia);

        $inicioNoFuso = CarbonImmutable::create($ano, $mes, 1, 0, 0, 0, BusinessDate::fuso());
        $fimNoFuso = $inicioNoFuso->endOfMonth()->endOfDay();

        return [
            'primeiro_dia' => $inicioNoFuso->toDateString(),
            'ultimo_dia' => $fimNoFuso->toDateString(),
            'inicio_instante' => $inicioNoFuso->utc(),
            'fim_instante' => $fimNoFuso->utc(),
        ];
    }

    /**
     * Valida e separa `"YYYY-MM"` em ano e mês inteiros.
     *
     * @return array{0: int, 1: int}
     */
    private function partesDaCompetencia(string $competencia): array
    {
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $competencia, $partes) !== 1) {
            throw new InvalidArgumentException(
                "Competência precisa estar no formato YYYY-MM. Valor recebido: \"{$competencia}\"."
            );
        }

        return [(int) $partes[1], (int) $partes[2]];
    }
}
