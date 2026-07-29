<?php

namespace App\Console\Commands;

use App\Models\Receivable;
use App\Models\ReceivableInstallment;
use App\Services\Financial\ConferenciaDeTotais;
use App\Support\BusinessDate;
use App\Support\Dinheiro;
use App\Support\TenantAtual;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Migração do financeiro atual para títulos a receber (Plano 18, Task 18.2).
 *
 * Transforma cada `payment_detail` em parcela de um título
 * (`receivables` + `receivable_installments`), agrupando as parcelas da mesma
 * ordem de serviço em um título só. É a task de maior risco do plano: o
 * critério de sucesso é que **nenhum número que o cliente vê hoje mude**, e
 * quem prova isso é `ConferenciaDeTotais`, não a impressão de quem rodou.
 *
 * O que este comando NÃO faz, e por quê
 * -------------------------------------
 * - **Não apaga nada.** `payment_details` e `financial_entries` continuam
 *   existindo e continuam sendo a fonte dos painéis até a Task 18.7 trocar a
 *   leitura. Desfazer a migração é truncar as tabelas novas (ver
 *   `docs/migracao-financeira.md`).
 * - **Não chama `ReceivableService::gerarDaOs()`.** O dado antigo já tem
 *   parcela com valor, vencimento e data de pagamento próprios, e a geração
 *   nova recalcularia tudo pela regra de divisão em centavos. Migrar é copiar
 *   o que está lá, não recalcular.
 * - **Não chama `SettlementService` nem `IntegracaoComCaixa`.** As duas criam
 *   lançamento de caixa novo, e o recebimento migrado já tem o lançamento
 *   dele em `financial_entries`. A migração **vincula** o lançamento que
 *   existe (`financial_entry_id`); criar um segundo é como o mesmo dinheiro
 *   entra duas vezes no fluxo de caixa.
 * - **Não adivinha.** Parcela sem OS, valor divergente do lançamento, OS que
 *   já tem título do fluxo novo: cada caso vai para o relatório de exceções,
 *   com o motivo escrito, e não é migrado. Quem decide é uma pessoa.
 *
 * Critério de vínculo entre parcela e lançamento de caixa
 * ------------------------------------------------------
 * O vínculo usado é `financial_entries.payment_detail_id`, que existe no banco
 * desde 2025 e é preenchido pelo próprio fluxo atual
 * (`PaymentDetailController::createFinancialEntryFromPayment()`), filtrando
 * `source = work_order` e `status = confirmed` e ficando com o mais recente
 * (parcela reaberta e paga de novo tem mais de um lançamento de entrada; o
 * anterior já foi compensado por uma saída `payment_reopen`).
 *
 * Casar por `work_order_id` + valor + data foi descartado de propósito: duas
 * parcelas iguais na mesma OS, no mesmo dia e pelo mesmo valor existem de
 * verdade (parcelamento em datas iguais), e nesse caso o casamento por
 * semelhança escolheria no escuro. Onde o vínculo direto não existe, a parcela
 * é migrada com `financial_entry_id` nulo e entra na lista de conferência, com
 * os lançamentos parecidos apenas **sugeridos** ao operador, nunca aplicados.
 *
 * Reexecução
 * ----------
 * Parcela que já virou parcela de título (já aparece em
 * `receivable_installments.payment_detail_id`) é ignorada, sem duplicar
 * título. Parcela criada pelo fluxo antigo depois da primeira execução é
 * anexada ao título que a própria migração criou para aquela OS.
 *
 * Empresa
 * -------
 * O comando roda sobre o banco inteiro, dentro de `TenantAtual::semEscopo()`,
 * e grava em `company_id` a empresa **copiada da origem** (da parcela, ou da
 * OS quando a parcela não tiver). Não existe caminho em que a empresa seja
 * inferida do contexto: migração histórica que erra o tenant vaza dado entre
 * empresas, a falha mais grave possível neste sistema.
 */
class MigrarFinanceiroParaTitulos extends Command
{
    protected $signature = 'financeiro:migrar-titulos
                            {--dry-run : Levanta tudo, imprime o relatório e para, sem gravar nada}
                            {--retrato= : Caminho de um retrato de totais já capturado. Sem esta opção, um retrato novo é capturado antes de migrar.}
                            {--force : Dispensa a confirmação interativa. Para janela de manutenção com o relatório de --dry-run já conferido.}';

    protected $description = 'Migra as parcelas e os lançamentos atuais para títulos a receber, conferindo todos os totais antes e depois e desfazendo tudo em qualquer divergência.';

    /**
     * Situação do título (`receivables.situacao`).
     */
    private const TITULO_ABERTO = 'aberto';

    private const TITULO_PARCIAL = 'parcial';

    private const TITULO_QUITADO = 'quitado';

    /**
     * Situação da parcela (`receivable_installments.situacao`).
     */
    private const PARCELA_ABERTA = 'aberta';

    private const PARCELA_PARCIAL = 'parcial';

    private const PARCELA_PAGA = 'paga';

    private const PARCELA_VENCIDA = 'vencida';

    private const PARCELA_CANCELADA = 'cancelada';

    /**
     * Motivo de cada exceção, no texto que vai para o relatório. O operador
     * precisa entender por que o caso é ambíguo sem abrir o código.
     *
     * @var array<string, string>
     */
    private const MOTIVOS = [
        'parcela_sem_os' => 'Parcela sem ordem de serviço válida (OS ausente ou sem cliente). Título a receber existe para cobrar de alguém, e escolher o cliente no lugar do sistema é decisão de pessoa.',
        'parcela_sem_empresa' => 'Parcela sem company_id, e a OS também não tem. Gravar título sem empresa é vazamento entre empresas esperando acontecer.',
        'parcela_sem_valor' => 'Parcela sem valor: final_amount e amount_paid vazios ou zerados. Não dá para dizer quanto ela cobra.',
        'parcela_sem_vencimento' => 'Parcela em aberto sem data de vencimento. Sem vencimento ela não entra em aging nem em previsão de caixa, e inventar a data mudaria a régua de inadimplência.',
        'valor_divergente_do_lancamento' => 'O valor recebido na parcela é diferente do valor do lançamento de caixa vinculado a ela. Um dos dois está errado, e qual deles é conferência de quem conhece o recebimento.',
        'os_com_titulo_do_fluxo_novo' => 'A ordem de serviço já tem título gerado pelo fluxo novo (Task 18.3). Migrar as parcelas antigas por cima cobraria o mesmo serviço duas vezes.',
    ];

    public function __construct(private readonly ConferenciaDeTotais $conferencia)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        // A migração é operação entre empresas por natureza: percorre o banco
        // inteiro e grava a empresa copiada de cada registro de origem.
        return TenantAtual::semEscopo(fn (): int => $this->executar());
    }

    private function executar(): int
    {
        $simulacao = (bool) $this->option('dry-run');

        $this->imprimirCabecalho($simulacao);

        try {
            $retrato = $this->resolverRetrato();
        } catch (Throwable $erro) {
            $this->error($erro->getMessage());

            return Command::FAILURE;
        }

        $levantamento = $this->levantar();

        $this->imprimirLevantamento($levantamento);

        if ($simulacao) {
            $this->newLine();
            $this->info('Simulação concluída. Nada foi gravado.');
            $this->gravarRelatorio($levantamento, true, 'simulação (--dry-run): nada foi gravado', []);

            return Command::SUCCESS;
        }

        if ($levantamento['qtd_migraveis'] === 0) {
            $this->newLine();
            $this->info('Nenhuma parcela nova a migrar. Nada foi gravado.');
            $this->gravarRelatorio($levantamento, false, 'nada a migrar', []);

            return Command::SUCCESS;
        }

        if (! $this->confirmarGravacao($levantamento)) {
            $this->warn('Operação cancelada. Nada foi gravado.');
            $this->gravarRelatorio($levantamento, false, 'cancelada pelo operador: nada foi gravado', []);

            return Command::SUCCESS;
        }

        return $this->migrarConferindo($levantamento, $retrato);
    }

    // -----------------------------------------------------------------
    // Retrato
    // -----------------------------------------------------------------

    /**
     * Retrato dos totais de antes: lido de um arquivo já capturado
     * (`--retrato=`) ou capturado agora.
     *
     * Poder reaproveitar um retrato anterior importa em produção: o operador
     * captura antes da janela, confere os números com o cliente e roda a
     * migração comparando contra aquele arquivo, não contra um retrato tirado
     * depois de o dado já ter mexido.
     *
     * @return array<string, mixed>
     */
    private function resolverRetrato(): array
    {
        $informado = $this->option('retrato');

        if (is_string($informado) && $informado !== '') {
            $retrato = $this->conferencia->ler($informado);

            $this->line("Retrato de totais lido de: {$informado}");
            $this->line('  Capturado em: '.($retrato['gerado_em'] ?? 'desconhecido'));
            $this->newLine();

            return $retrato;
        }

        $captura = $this->conferencia->capturar();

        $this->line('Retrato de totais capturado antes de qualquer gravação.');
        $this->line('  Arquivo: '.$captura['caminho']);
        $this->newLine();

        return $captura['retrato'];
    }

    // -----------------------------------------------------------------
    // Levantamento
    // -----------------------------------------------------------------

    /**
     * Percorre `payment_details` e separa o que dá para migrar do que precisa
     * de decisão humana, sem gravar nada. É o mesmo levantamento usado por
     * `--dry-run` e pela execução real: o relatório que o operador confere é
     * exatamente o que vai ser aplicado.
     *
     * @return array{titulos: array<int, array{ordem: array<string, mixed>, parcelas: list<array<string, mixed>>}>, excecoes: list<array<string, mixed>>, sem_lancamento: list<array<string, mixed>>, lancamentos_orfaos: list<array<string, mixed>>, qtd_ja_migradas: int, qtd_migraveis: int}
     */
    private function levantar(): array
    {
        $jaMigradas = $this->parcelasJaMigradas();
        $lancamentos = $this->lancamentosPorParcela();
        $titulosPorOrdem = $this->titulosPorOrdem();

        $titulos = [];
        $excecoes = [];
        $semLancamento = [];
        $qtdJaMigradas = 0;
        $qtdMigraveis = 0;

        $consulta = DB::table('payment_details')
            ->leftJoin('work_orders', 'work_orders.id', '=', 'payment_details.work_order_id')
            ->select([
                'payment_details.id',
                'payment_details.company_id',
                'payment_details.work_order_id',
                'payment_details.final_amount',
                'payment_details.amount_paid',
                'payment_details.payment_due_date',
                'payment_details.payment_date',
                'payment_details.payment_method',
                'payment_details.created_at',
                'work_orders.id as ordem_id',
                'work_orders.company_id as ordem_company_id',
                'work_orders.client_id',
                'work_orders.contract_id',
                'work_orders.order_number',
                'work_orders.final_amount as ordem_final_amount',
            ])
            ->orderBy('payment_details.work_order_id')
            ->orderBy('payment_details.payment_due_date')
            ->orderBy('payment_details.id');

        foreach ($consulta->cursor() as $linha) {
            $id = (int) $linha->id;

            if (isset($jaMigradas[$id])) {
                $qtdJaMigradas++;

                continue;
            }

            $lancamento = $lancamentos[$id] ?? null;
            $parcela = $this->traduzirParcela($linha, $lancamento);
            $motivo = $this->motivoDeExcecao($linha, $parcela, $titulosPorOrdem, $lancamento);

            if ($motivo !== null) {
                $excecoes[] = [
                    'parcela' => $id,
                    'ordem' => $linha->work_order_id === null ? null : (int) $linha->work_order_id,
                    'valor' => $parcela['valor'],
                    'recebido' => $parcela['valor_pago'],
                    'motivo' => $motivo,
                    'explicacao' => self::MOTIVOS[$motivo],
                ];

                continue;
            }

            $ordemId = (int) $linha->work_order_id;

            $titulos[$ordemId] ??= [
                'ordem' => [
                    'id' => $ordemId,
                    'numero' => (string) ($linha->order_number ?? ''),
                    'client_id' => (int) $linha->client_id,
                    'contract_id' => $linha->contract_id === null ? null : (int) $linha->contract_id,
                    'company_id' => (int) ($linha->company_id ?? $linha->ordem_company_id),
                    'final_amount' => Dinheiro::centavos($linha->ordem_final_amount),
                    'titulo_existente' => $titulosPorOrdem[$ordemId]['id'] ?? null,
                ],
                'parcelas' => [],
            ];

            $titulos[$ordemId]['parcelas'][] = $parcela;
            $qtdMigraveis++;

            if ($parcela['valor_pago'] > 0 && $parcela['financial_entry_id'] === null) {
                $semLancamento[] = [
                    'parcela' => $id,
                    'ordem' => $ordemId,
                    'recebido' => $parcela['valor_pago'],
                    'pago_em' => $parcela['pago_em'],
                    'sugestoes' => $this->lancamentosParecidos($ordemId, $parcela),
                ];
            }
        }

        return [
            'titulos' => $titulos,
            'excecoes' => $excecoes,
            'sem_lancamento' => $semLancamento,
            'lancamentos_orfaos' => $this->lancamentosSemParcela(),
            'qtd_ja_migradas' => $qtdJaMigradas,
            'qtd_migraveis' => $qtdMigraveis,
        ];
    }

    /**
     * Traduz a linha de `payment_details` para os campos da parcela nova,
     * copiando o que existe em vez de recalcular.
     *
     * Regras, todas espelhando o que o sistema já entende hoje:
     *
     * - Recebimento só existe com `payment_date` preenchida **e** `amount_paid`
     *   maior que zero, o mesmo critério de `scopePaid()` e do accessor de
     *   status. Valor sem data de pagamento é dinheiro a receber.
     * - O valor da parcela vive em `final_amount`; registro antigo, anterior à
     *   correção de `PaymentDetailService`, guarda o valor em `amount_paid` com
     *   `final_amount` nulo, e nesse caso o valor é `amount_paid`.
     * - `valor_pago` e `pago_em` são cópias exatas de `amount_paid` e
     *   `payment_date`. Recebimento parcial fica com a data do recebimento
     *   registrado: é a única data que existe no dado antigo, e perdê-la
     *   mudaria o total recebido do mês.
     * - Parcela paga sem vencimento cai para a data de pagamento. Parcela em
     *   aberto sem vencimento não cai para lugar nenhum: vira exceção.
     *
     * @param  array<string, mixed>|null  $lancamento
     * @return array<string, mixed>
     */
    private function traduzirParcela(object $linha, ?array $lancamento): array
    {
        $centavosFinal = Dinheiro::centavos($linha->final_amount);
        $centavosPagos = Dinheiro::centavos($linha->amount_paid);

        $pagoEm = BusinessDate::diaDe($linha->payment_date);
        $recebeu = $pagoEm !== null && $centavosPagos > 0;

        $valor = $centavosFinal > 0 ? $centavosFinal : max($centavosPagos, 0);
        $recebido = $recebeu ? $centavosPagos : 0;

        $vencimento = BusinessDate::diaDe($linha->payment_due_date) ?? ($recebeu ? $pagoEm : null);

        return [
            'payment_detail_id' => (int) $linha->id,
            'valor' => $valor,
            'valor_pago' => $recebido,
            'pago_em' => $recebeu ? $pagoEm : null,
            'vencimento' => $vencimento,
            'situacao' => $this->situacaoDaParcela($valor, $recebido, $recebeu, $vencimento),
            'financial_entry_id' => $recebeu ? ($lancamento['id'] ?? null) : null,
            'criada_em' => $this->diaDoInstante($linha->created_at),
        ];
    }

    /**
     * Dia, no fuso do negócio, de um instante gravado em UTC
     * (`created_at`).
     *
     * A conversão precisa dizer explicitamente que o texto do banco está em
     * UTC: entregar a string crua para `BusinessDate` faria ela ser lida como
     * se já estivesse em Brasília, o que joga a data três horas para frente e
     * troca o dia de tudo que foi gravado depois das 21h.
     */
    private function diaDoInstante(mixed $instante): ?string
    {
        if ($instante === null || $instante === '') {
            return null;
        }

        return BusinessDate::diaDe(
            CarbonImmutable::parse($instante, config('app.timezone', 'UTC'))
        );
    }

    /**
     * Situação da parcela nova, pela mesma leitura que a tela faz hoje:
     * sem recebimento é `aberta` (ou `vencida`, comparando por dia no fuso do
     * negócio); com recebimento é `paga` quando cobre o valor e `parcial`
     * quando não cobre.
     */
    private function situacaoDaParcela(int $valor, int $recebido, bool $recebeu, ?string $vencimento): string
    {
        if (! $recebeu) {
            return BusinessDate::estaVencido($vencimento) ? self::PARCELA_VENCIDA : self::PARCELA_ABERTA;
        }

        return $recebido >= $valor ? self::PARCELA_PAGA : self::PARCELA_PARCIAL;
    }

    /**
     * Motivo pelo qual a parcela não pode ser migrada sozinha, ou `null`
     * quando ela pode.
     *
     * @param  array<int, array{id: int, veio_da_migracao: bool}>  $titulosPorOrdem
     * @param  array<string, mixed>|null  $lancamento
     * @param  array<string, mixed>  $parcela
     */
    private function motivoDeExcecao(object $linha, array $parcela, array $titulosPorOrdem, ?array $lancamento): ?string
    {
        if ($linha->ordem_id === null || $linha->client_id === null) {
            return 'parcela_sem_os';
        }

        if ($linha->company_id === null && $linha->ordem_company_id === null) {
            return 'parcela_sem_empresa';
        }

        $ordemId = (int) $linha->work_order_id;

        if (isset($titulosPorOrdem[$ordemId]) && ! $titulosPorOrdem[$ordemId]['veio_da_migracao']) {
            return 'os_com_titulo_do_fluxo_novo';
        }

        if ($parcela['valor'] <= 0) {
            return 'parcela_sem_valor';
        }

        if ($parcela['vencimento'] === null) {
            return 'parcela_sem_vencimento';
        }

        if ($parcela['valor_pago'] > 0 && $lancamento !== null && $lancamento['valor'] !== $parcela['valor_pago']) {
            return 'valor_divergente_do_lancamento';
        }

        return null;
    }

    // -----------------------------------------------------------------
    // Leituras auxiliares
    // -----------------------------------------------------------------

    /**
     * Parcelas antigas que já viraram parcela de título em alguma execução
     * anterior. É o vínculo que torna o comando reexecutável.
     *
     * @return array<int, true>
     */
    private function parcelasJaMigradas(): array
    {
        $migradas = [];

        $consulta = DB::table('receivable_installments')
            ->whereNotNull('payment_detail_id')
            ->select(['payment_detail_id']);

        foreach ($consulta->cursor() as $parcela) {
            $migradas[(int) $parcela->payment_detail_id] = true;
        }

        return $migradas;
    }

    /**
     * Lançamento de recebimento vigente de cada parcela antiga, pelo vínculo
     * direto `financial_entries.payment_detail_id`.
     *
     * Fica com o mais recente: parcela reaberta e paga de novo tem mais de um
     * lançamento de entrada, e o anterior já foi compensado por uma saída
     * `payment_reopen`.
     *
     * @return array<int, array{id: int, valor: int, dia: ?string, forma: ?string}>
     */
    private function lancamentosPorParcela(): array
    {
        $lancamentos = [];

        $consulta = DB::table('financial_entries')
            ->whereNotNull('payment_detail_id')
            ->where('source', 'work_order')
            ->where('status', 'confirmed')
            ->select(['id', 'payment_detail_id', 'amount', 'entry_date', 'payment_method'])
            ->orderBy('created_at')
            ->orderBy('id');

        foreach ($consulta->cursor() as $lancamento) {
            $lancamentos[(int) $lancamento->payment_detail_id] = [
                'id' => (int) $lancamento->id,
                'valor' => Dinheiro::centavos($lancamento->amount),
                'dia' => BusinessDate::diaDe($lancamento->entry_date),
                'forma' => $lancamento->payment_method,
            ];
        }

        return $lancamentos;
    }

    /**
     * Títulos que já existem por ordem de serviço, separando os que a própria
     * migração criou (têm parcela com `payment_detail_id`) dos que nasceram
     * pelo fluxo novo.
     *
     * @return array<int, array{id: int, veio_da_migracao: bool}>
     */
    private function titulosPorOrdem(): array
    {
        $titulos = [];

        $consulta = DB::table('receivables')
            ->leftJoin('receivable_installments', 'receivable_installments.receivable_id', '=', 'receivables.id')
            ->whereNotNull('receivables.work_order_id')
            ->select([
                'receivables.id',
                'receivables.work_order_id',
                DB::raw('COUNT(receivable_installments.payment_detail_id) as migradas'),
            ])
            ->groupBy('receivables.id', 'receivables.work_order_id');

        foreach ($consulta->get() as $titulo) {
            $titulos[(int) $titulo->work_order_id] = [
                'id' => (int) $titulo->id,
                'veio_da_migracao' => (int) $titulo->migradas > 0,
            ];
        }

        return $titulos;
    }

    /**
     * Lançamentos de recebimento que não apontam para nenhuma parcela
     * existente. Dinheiro que entrou no caixa sem cobrança que o explique:
     * não há título a criar sem inventar o cliente e o valor devido, então o
     * caso é listado para decisão humana.
     *
     * @return list<array{lancamento: int, ordem: ?int, valor: int, dia: ?string, motivo: string}>
     */
    private function lancamentosSemParcela(): array
    {
        $parcelasExistentes = [];

        foreach (DB::table('payment_details')->select(['id'])->cursor() as $parcela) {
            $parcelasExistentes[(int) $parcela->id] = true;
        }

        $orfaos = [];

        $consulta = DB::table('financial_entries')
            ->where('source', 'work_order')
            ->where('status', 'confirmed')
            ->select(['id', 'payment_detail_id', 'work_order_id', 'amount', 'entry_date'])
            ->orderBy('id');

        foreach ($consulta->cursor() as $lancamento) {
            $parcelaId = $lancamento->payment_detail_id === null ? null : (int) $lancamento->payment_detail_id;

            if ($parcelaId !== null && isset($parcelasExistentes[$parcelaId])) {
                continue;
            }

            $orfaos[] = [
                'lancamento' => (int) $lancamento->id,
                'ordem' => $lancamento->work_order_id === null ? null : (int) $lancamento->work_order_id,
                'valor' => Dinheiro::centavos($lancamento->amount),
                'dia' => BusinessDate::diaDe($lancamento->entry_date),
                'motivo' => $parcelaId === null
                    ? 'Lançamento de recebimento sem payment_detail_id: não dá para saber qual cobrança ele quitou.'
                    : "Lançamento aponta para a parcela #{$parcelaId}, que não existe mais.",
            ];
        }

        return $orfaos;
    }

    /**
     * Lançamentos parecidos com um recebimento sem vínculo direto: mesma OS,
     * mesmo valor e mesmo dia.
     *
     * São **sugestão** para o operador conferir, nunca vínculo aplicado. Duas
     * parcelas iguais na mesma OS e no mesmo dia existem de verdade, e
     * escolher uma delas sozinho é exatamente o palpite que este plano proíbe.
     *
     * @param  array<string, mixed>  $parcela
     * @return list<int>
     */
    private function lancamentosParecidos(int $ordemId, array $parcela): array
    {
        return DB::table('financial_entries')
            ->where('source', 'work_order')
            ->where('status', 'confirmed')
            ->where('work_order_id', $ordemId)
            ->where('entry_date', $parcela['pago_em'])
            ->where('amount', Dinheiro::paraDecimal($parcela['valor_pago']))
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    // -----------------------------------------------------------------
    // Gravação
    // -----------------------------------------------------------------

    /**
     * Migra tudo dentro de uma transação e só confirma se a conferência de
     * totais não achar nenhuma divergência.
     *
     * Qualquer diferença, de um centavo que seja, desfaz a migração inteira:
     * migração financeira que "quase bate" gera meses de conferência manual
     * depois, e o banco volta ao estado anterior aqui mesmo.
     *
     * @param  array<string, mixed>  $levantamento
     * @param  array<string, mixed>  $retrato
     */
    private function migrarConferindo(array $levantamento, array $retrato): int
    {
        DB::beginTransaction();

        try {
            $gravados = $this->gravarTitulos($levantamento['titulos']);

            $divergencias = $this->conferencia->comparar($retrato);
            $cobertura = $this->conferirCobertura($levantamento['excecoes']);

            if ($divergencias !== [] || $cobertura !== []) {
                DB::rollBack();

                $this->imprimirDivergencias($divergencias, $cobertura);
                $this->gravarRelatorio(
                    $levantamento,
                    false,
                    'ABORTADA: divergência na conferência de totais. Nada foi gravado.',
                    $divergencias,
                    $cobertura
                );

                return Command::FAILURE;
            }

            DB::commit();
        } catch (Throwable $erro) {
            DB::rollBack();

            report($erro);

            $this->error('Erro durante a migração: '.$erro->getMessage());
            $this->line('A transação foi desfeita: nada foi gravado.');
            $this->gravarRelatorio($levantamento, false, 'ABORTADA por erro: '.$erro->getMessage(), []);

            return Command::FAILURE;
        }

        $this->newLine();
        $this->info(sprintf(
            'Migração concluída: %d título(s) e %d parcela(s) gravados. Todos os totais conferidos, sem divergência.',
            $gravados['titulos'],
            $gravados['parcelas']
        ));

        $this->gravarRelatorio($levantamento, false, 'concluída com sucesso, sem divergência de totais', []);

        return Command::SUCCESS;
    }

    /**
     * Cria (ou reaproveita) o título de cada ordem de serviço e grava as
     * parcelas.
     *
     * O título nasce com `valor_total` igual à **soma das parcelas dele**, e
     * não ao valor final da OS: as duas coisas divergem no dado antigo (OS de
     * 1.000 com uma única parcela de 500 lançada), e um título cujo total não
     * é a soma das parcelas ficaria inconsistente com a baixa e com o aging.
     * A diferença aparece no relatório, para conferência.
     *
     * @param  array<int, array{ordem: array<string, mixed>, parcelas: list<array<string, mixed>>}>  $titulos
     * @return array{titulos: int, parcelas: int}
     */
    private function gravarTitulos(array $titulos): array
    {
        $criados = 0;
        $parcelasGravadas = 0;

        foreach ($titulos as $grupo) {
            $ordem = $grupo['ordem'];
            $parcelas = $grupo['parcelas'];

            // `company_id` não é atributo de atribuição em massa em nenhum
            // model de domínio: quem preenche é a trait `BelongsToCompany`, a
            // partir do tenant corrente. Gravar dentro do tenant da empresa de
            // origem é o que garante que o título nasça na empresa certa, em
            // vez de depender de um `company_id` solto em um array.
            [$titulo, $criouTitulo, $gravadas] = TenantAtual::comTenant(
                $ordem['company_id'],
                fn (): array => $this->gravarTitulo($ordem, $parcelas)
            );

            $criados += $criouTitulo ? 1 : 0;
            $parcelasGravadas += $gravadas;

            $this->atualizarTitulo($titulo);
        }

        return ['titulos' => $criados, 'parcelas' => $parcelasGravadas];
    }

    /**
     * Grava um título e as parcelas dele, já dentro do tenant da empresa de
     * origem.
     *
     * @param  array<string, mixed>  $ordem
     * @param  list<array<string, mixed>>  $parcelas
     * @return array{0: Receivable, 1: bool, 2: int}
     */
    private function gravarTitulo(array $ordem, array $parcelas): array
    {
        $titulo = $ordem['titulo_existente'] === null
            ? null
            : Receivable::query()->find($ordem['titulo_existente']);

        $criou = $titulo === null;

        if ($titulo === null) {
            $titulo = Receivable::create([
                'client_id' => $ordem['client_id'],
                'work_order_id' => $ordem['id'],
                'contract_id' => $ordem['contract_id'],
                'descricao' => $this->descricaoDoTitulo($ordem),
                'valor_total' => Dinheiro::paraDecimal($this->somaDasParcelas($parcelas)),
                'emitido_em' => $this->emissaoDoTitulo($parcelas),
                'situacao' => self::TITULO_ABERTO,
            ]);
        }

        $proximoNumero = (int) ReceivableInstallment::query()
            ->where('receivable_id', $titulo->id)
            ->max('numero');

        $gravadas = 0;

        foreach ($parcelas as $parcela) {
            $proximoNumero++;

            ReceivableInstallment::create([
                'receivable_id' => $titulo->id,
                'numero' => $proximoNumero,
                'valor' => Dinheiro::paraDecimal($parcela['valor']),
                'vencimento' => $parcela['vencimento'],
                'valor_pago' => Dinheiro::paraDecimal($parcela['valor_pago']),
                'pago_em' => $parcela['pago_em'],
                'situacao' => $parcela['situacao'],
                'payment_detail_id' => $parcela['payment_detail_id'],
                'financial_entry_id' => $parcela['financial_entry_id'],
            ]);

            $gravadas++;
        }

        return [$titulo, $criou, $gravadas];
    }

    /**
     * Recalcula `valor_total` e `situacao` do título a partir das parcelas
     * dele, com a mesma regra de `SettlementService`: quitado quando todas
     * estão pagas, parcial quando alguma tem recebimento, aberto no resto.
     */
    private function atualizarTitulo(Receivable $titulo): void
    {
        $parcelas = ReceivableInstallment::query()
            ->where('receivable_id', $titulo->id)
            ->where('situacao', '!=', self::PARCELA_CANCELADA)
            ->get(['valor', 'valor_pago', 'situacao']);

        if ($parcelas->isEmpty()) {
            return;
        }

        $total = 0;

        foreach ($parcelas as $parcela) {
            $total += Dinheiro::centavos($parcela->valor);
        }

        $situacao = match (true) {
            $parcelas->every(fn ($parcela): bool => $parcela->situacao === self::PARCELA_PAGA) => self::TITULO_QUITADO,
            $parcelas->contains(fn ($parcela): bool => Dinheiro::centavos($parcela->valor_pago) > 0) => self::TITULO_PARCIAL,
            default => self::TITULO_ABERTO,
        };

        $titulo->update([
            'valor_total' => Dinheiro::paraDecimal($total),
            'situacao' => $situacao,
        ]);
    }

    /**
     * @param  array<string, mixed>  $ordem
     */
    private function descricaoDoTitulo(array $ordem): string
    {
        $numero = trim((string) $ordem['numero']);

        return $numero === ''
            ? "Ordem de serviço #{$ordem['id']}"
            : "Ordem de serviço {$numero}";
    }

    /**
     * Dia de emissão do título migrado: o dia em que a cobrança mais antiga do
     * grupo foi registrada (`payment_details.created_at`, convertido para o
     * dia no fuso do negócio).
     *
     * É a data que o próprio dado financeiro carrega. Usar "hoje" faria todo
     * título migrado nascer com a data da migração, e a emissão deixaria de
     * dizer qualquer coisa sobre quando a cobrança existiu.
     *
     * @param  list<array<string, mixed>>  $parcelas
     */
    private function emissaoDoTitulo(array $parcelas): string
    {
        $dias = array_filter(array_column($parcelas, 'criada_em'));

        if ($dias !== []) {
            sort($dias);

            return $dias[0];
        }

        $vencimentos = array_filter(array_column($parcelas, 'vencimento'));
        sort($vencimentos);

        return $vencimentos[0] ?? BusinessDate::hoje()->toDateString();
    }

    /**
     * @param  list<array<string, mixed>>  $parcelas
     */
    private function somaDasParcelas(array $parcelas): int
    {
        return array_sum(array_column($parcelas, 'valor'));
    }

    // -----------------------------------------------------------------
    // Conferências
    // -----------------------------------------------------------------

    /**
     * Confere que toda parcela antiga ou virou parcela de título, ou está no
     * relatório de exceções com motivo escrito.
     *
     * Sem esta conferência, uma parcela que o comando pulasse em silêncio
     * (por um `continue` errado, por exemplo) sairia do modelo novo sem
     * ninguém saber, e a conferência de totais não pegaria: o valor dela
     * continuaria contando pelo modelo antigo.
     *
     * @param  list<array<string, mixed>>  $excecoes
     * @return list<string>
     */
    private function conferirCobertura(array $excecoes): array
    {
        $migradas = $this->parcelasJaMigradas();
        $declaradas = array_flip(array_column($excecoes, 'parcela'));

        $semExplicacao = [];

        foreach (DB::table('payment_details')->select(['id'])->orderBy('id')->cursor() as $parcela) {
            $id = (int) $parcela->id;

            if (isset($migradas[$id]) || isset($declaradas[$id])) {
                continue;
            }

            $semExplicacao[] = $id;
        }

        if ($semExplicacao === []) {
            return [];
        }

        return [sprintf(
            '%d parcela(s) não foram migradas e também não estão no relatório de exceções: #%s. '
            .'Toda parcela precisa ter destino explicado.',
            count($semExplicacao),
            implode(', #', array_slice($semExplicacao, 0, 20))
        )];
    }

    // -----------------------------------------------------------------
    // Saída
    // -----------------------------------------------------------------

    private function imprimirCabecalho(bool $simulacao): void
    {
        $this->info('Migração do financeiro atual para títulos a receber (Plano 18, Task 18.2)');
        $this->line('Nada é apagado: payment_details e financial_entries continuam intactos.');
        $this->line($simulacao
            ? 'Modo simulação (--dry-run): nada será gravado.'
            : 'Modo aplicação: tudo acontece em uma transação, e qualquer divergência de totais desfaz a migração inteira.');
        $this->newLine();
    }

    /**
     * @param  array<string, mixed>  $levantamento
     */
    private function imprimirLevantamento(array $levantamento): void
    {
        $titulos = $levantamento['titulos'];

        $this->line('A migrar:');
        $this->table(
            ['OS', 'Número', 'Parcelas', 'Soma das parcelas', 'Valor final da OS', 'Título existente'],
            array_map(fn (array $grupo): array => [
                $grupo['ordem']['id'],
                $grupo['ordem']['numero'],
                count($grupo['parcelas']),
                Dinheiro::formatar($this->somaDasParcelas($grupo['parcelas'])),
                Dinheiro::formatar($grupo['ordem']['final_amount']),
                $grupo['ordem']['titulo_existente'] === null ? 'novo' : '#'.$grupo['ordem']['titulo_existente'],
            ], array_slice($titulos, 0, 50, true))
        );

        if (count($titulos) > 50) {
            $this->line('  ... e mais '.(count($titulos) - 50).' ordem(ns) de serviço. A lista completa está no relatório gravado.');
        }

        $this->newLine();
        $this->line('Relatório de exceções (NÃO migradas, decidir a mão):');

        if ($levantamento['excecoes'] === []) {
            $this->line('  Nenhuma exceção. Todas as parcelas foram resolvidas pelo comando.');
        } else {
            $this->table(
                ['Parcela', 'OS', 'Valor', 'Recebido', 'Motivo'],
                array_map(fn (array $excecao): array => [
                    '#'.$excecao['parcela'],
                    $excecao['ordem'] === null ? 'sem OS' : '#'.$excecao['ordem'],
                    Dinheiro::formatar($excecao['valor']),
                    Dinheiro::formatar($excecao['recebido']),
                    $excecao['motivo'],
                ], array_slice($levantamento['excecoes'], 0, 50))
            );
        }

        if ($levantamento['lancamentos_orfaos'] !== []) {
            $this->newLine();
            $this->warn(sprintf(
                '%d lançamento(s) de recebimento sem parcela correspondente. Não há título a criar sem inventar a cobrança; conferir no relatório.',
                count($levantamento['lancamentos_orfaos'])
            ));
        }

        if ($levantamento['sem_lancamento'] !== []) {
            $this->newLine();
            $this->warn(sprintf(
                '%d recebimento(s) migrado(s) sem lançamento de caixa vinculado. Migram assim mesmo, com financial_entry_id nulo; o relatório traz os lançamentos parecidos como sugestão.',
                count($levantamento['sem_lancamento'])
            ));
        }

        $this->newLine();
        $this->line('Resumo:');
        $this->line('  Parcelas a migrar: '.$levantamento['qtd_migraveis']);
        $this->line('  Títulos envolvidos: '.count($titulos));
        $this->line('  Parcelas já migradas antes (ignoradas): '.$levantamento['qtd_ja_migradas']);
        $this->line('  Exceções (não migradas): '.count($levantamento['excecoes']));
    }

    /**
     * @param  list<array{empresa: string, caminho: string, retrato: int, agora: int, diferenca: int, contagem: bool}>  $divergencias
     * @param  list<string>  $cobertura
     */
    private function imprimirDivergencias(array $divergencias, array $cobertura): void
    {
        $this->newLine();
        $this->error('MIGRAÇÃO ABORTADA: os totais depois não batem com o retrato de antes.');
        $this->line('A transação foi desfeita. Nenhum título e nenhuma parcela ficaram gravados.');
        $this->newLine();

        foreach ($cobertura as $problema) {
            $this->line('  '.$problema);
        }

        foreach ($divergencias as $divergencia) {
            $this->line('  '.$this->conferencia->descreverDivergencia($divergencia));
        }
    }

    /**
     * @param  array<string, mixed>  $levantamento
     */
    private function confirmarGravacao(array $levantamento): bool
    {
        if ((bool) $this->option('force')) {
            return true;
        }

        $this->newLine();

        return $this->confirm(sprintf(
            'Migrar %d parcela(s) em %d título(s)? A conferência de totais roda logo depois e desfaz tudo em qualquer divergência.',
            $levantamento['qtd_migraveis'],
            count($levantamento['titulos'])
        ), false);
    }

    /**
     * Grava o relatório completo em `storage/logs`, em toda saída do comando:
     * simulação, cancelamento, aborto por divergência e sucesso. É o documento
     * que o operador confere linha a linha antes da execução final e guarda
     * depois dela.
     *
     * @param  array<string, mixed>  $levantamento
     * @param  list<array{empresa: string, caminho: string, retrato: int, agora: int, diferenca: int, contagem: bool}>  $divergencias
     * @param  list<string>  $cobertura
     */
    private function gravarRelatorio(array $levantamento, bool $simulacao, string $resultado, array $divergencias, array $cobertura = []): string
    {
        $agora = BusinessDate::agora();

        $linhas = [];
        $linhas[] = 'Migração do financeiro para títulos a receber (financeiro:migrar-titulos)';
        $linhas[] = 'Executado em: '.$agora->format('d/m/Y H:i:s').' ('.BusinessDate::fuso().')';
        $linhas[] = 'Modo: '.($simulacao ? 'simulação (--dry-run)' : 'aplicação');
        $linhas[] = 'Resultado: '.$resultado;
        $linhas[] = '';
        $linhas[] = 'Resumo';
        $linhas[] = '  Parcelas a migrar: '.$levantamento['qtd_migraveis'];
        $linhas[] = '  Títulos envolvidos: '.count($levantamento['titulos']);
        $linhas[] = '  Parcelas já migradas antes (ignoradas): '.$levantamento['qtd_ja_migradas'];
        $linhas[] = '  Exceções (não migradas): '.count($levantamento['excecoes']);
        $linhas[] = '';

        $linhas[] = 'Títulos e parcelas';

        foreach ($levantamento['titulos'] as $grupo) {
            $soma = $this->somaDasParcelas($grupo['parcelas']);
            $linhas[] = sprintf(
                '  OS #%d (%s): %d parcela(s), soma %s, valor final da OS %s%s',
                $grupo['ordem']['id'],
                $grupo['ordem']['numero'],
                count($grupo['parcelas']),
                Dinheiro::formatar($soma),
                Dinheiro::formatar($grupo['ordem']['final_amount']),
                $soma === $grupo['ordem']['final_amount'] ? '' : '  <- soma difere do valor da OS, conferir'
            );

            foreach ($grupo['parcelas'] as $parcela) {
                $linhas[] = sprintf(
                    '    parcela antiga #%d: valor %s, recebido %s, vence %s, pago em %s, situação %s, lançamento %s',
                    $parcela['payment_detail_id'],
                    Dinheiro::formatar($parcela['valor']),
                    Dinheiro::formatar($parcela['valor_pago']),
                    $parcela['vencimento'] ?? '-',
                    $parcela['pago_em'] ?? '-',
                    $parcela['situacao'],
                    $parcela['financial_entry_id'] === null ? 'nenhum' : '#'.$parcela['financial_entry_id']
                );
            }
        }

        $linhas[] = '';
        $linhas[] = 'Exceções (não migradas, decidir a mão)';

        if ($levantamento['excecoes'] === []) {
            $linhas[] = '  Nenhuma.';
        }

        foreach ($levantamento['excecoes'] as $excecao) {
            $linhas[] = sprintf(
                '  parcela antiga #%d (OS %s), valor %s, recebido %s: %s',
                $excecao['parcela'],
                $excecao['ordem'] === null ? 'ausente' : '#'.$excecao['ordem'],
                Dinheiro::formatar($excecao['valor']),
                Dinheiro::formatar($excecao['recebido']),
                $excecao['explicacao']
            );
        }

        $linhas[] = '';
        $linhas[] = 'Lançamentos de recebimento sem parcela correspondente';

        if ($levantamento['lancamentos_orfaos'] === []) {
            $linhas[] = '  Nenhum.';
        }

        foreach ($levantamento['lancamentos_orfaos'] as $orfao) {
            $linhas[] = sprintf(
                '  lançamento #%d (OS %s), %s em %s: %s',
                $orfao['lancamento'],
                $orfao['ordem'] === null ? 'ausente' : '#'.$orfao['ordem'],
                Dinheiro::formatar($orfao['valor']),
                $orfao['dia'] ?? '-',
                $orfao['motivo']
            );
        }

        $linhas[] = '';
        $linhas[] = 'Recebimentos migrados sem lançamento de caixa vinculado (financial_entry_id nulo)';

        if ($levantamento['sem_lancamento'] === []) {
            $linhas[] = '  Nenhum.';
        }

        foreach ($levantamento['sem_lancamento'] as $item) {
            $linhas[] = sprintf(
                '  parcela antiga #%d (OS #%d), %s em %s. Lançamentos parecidos (apenas sugestão): %s',
                $item['parcela'],
                $item['ordem'],
                Dinheiro::formatar($item['recebido']),
                $item['pago_em'] ?? '-',
                $item['sugestoes'] === [] ? 'nenhum' : '#'.implode(', #', $item['sugestoes'])
            );
        }

        if ($cobertura !== [] || $divergencias !== []) {
            $linhas[] = '';
            $linhas[] = 'Divergências que abortaram a migração';

            foreach ($cobertura as $problema) {
                $linhas[] = '  '.$problema;
            }

            foreach ($divergencias as $divergencia) {
                $linhas[] = '  '.$this->conferencia->descreverDivergencia($divergencia);
            }
        }

        $caminho = storage_path('logs/migracao-financeira-'.$agora->format('Y-m-d_His').'.log');

        File::ensureDirectoryExists(dirname($caminho));
        File::put($caminho, implode(PHP_EOL, $linhas).PHP_EOL);

        $this->newLine();
        $this->line("Relatório gravado em: {$caminho}");

        return $caminho;
    }
}
