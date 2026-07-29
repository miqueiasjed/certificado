<?php

namespace App\Services\Financial;

use App\Support\BusinessDate;
use App\Support\Dinheiro;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Retrato e conferência dos totais financeiros (Plano 18, Task 18.2).
 *
 * Existe por um motivo só: provar, com número, que a migração do financeiro
 * antigo (`payment_details` + `financial_entries`) para títulos
 * (`receivables` + `receivable_installments`) não mexeu em nenhum valor que o
 * cliente enxerga hoje. A impressão de quem testou não serve; o que serve é
 * capturar todos os totais antes, recalcular os mesmos totais depois e exigir
 * igualdade centavo a centavo.
 *
 * Toda soma acontece em centavos (inteiro), via `App\Support\Dinheiro`. Somar
 * `decimal(10,2)` como float é exatamente o defeito que faz uma conferência
 * "quase bater" e gerar meses de trabalho manual depois.
 *
 * Dois grupos de total, com regras diferentes
 * -------------------------------------------
 * 1. **`intocavel`**: o que sai de `payment_details`, `financial_entries`,
 *    `daily_cash_balances` e `work_orders`. A migração não escreve em nenhuma
 *    dessas tabelas, então esses números precisam ser **idênticos** depois.
 *    É essa metade que pega, por exemplo, uma migração que criasse lançamento
 *    novo no caixa (o mesmo dinheiro entrando duas vezes) ou que alterasse
 *    parcela antiga.
 * 2. **`espelhado`**: o que o modelo novo passa a responder (a receber,
 *    recebido por mês, recebido por forma). Depois da migração, ele é
 *    recalculado como
 *    `parcelas migradas (modelo novo) + parcelas que ficaram de fora (modelo
 *    antigo)`. Caso ambíguo que virou exceção e não foi migrado continua
 *    contando pelo modelo antigo, e por isso o total geral não pode mudar:
 *    o que não pode acontecer é dinheiro sumir no caminho.
 *
 * Parcela do modelo novo **sem** `payment_detail_id` fica fora da conferência
 * de propósito: título nascido pelo caminho novo (OS fechada depois do deploy,
 * mensalidade de contrato gerada pela rotina da Task 18.3) não existia no
 * retrato e não tem par no modelo antigo.
 *
 * Todas as consultas usam `DB::table()` direto, sem Eloquent: a conferência
 * precisa enxergar o banco inteiro, agrupando por empresa, sem depender de
 * qual tenant está resolvido no processo (comando artisan não tem usuário
 * autenticado). É a mesma disciplina de `BackfillCompanyId`.
 *
 * Datas: `payment_date`, `pago_em`, `entry_date` e `balance_date` são dia sem
 * hora relevante. O mês de competência é lido dos 7 primeiros caracteres do
 * dia (`YYYY-MM`), nunca convertendo fuso, que é o que produziria o erro de um
 * dia na virada do mês.
 */
final class ConferenciaDeTotais
{
    /**
     * Versão do formato do retrato. Comparar um retrato antigo com um formato
     * novo daria divergência falsa, então a leitura recusa versão diferente.
     */
    public const VERSAO = 1;

    /**
     * Diretório do retrato dentro de `storage/app`.
     */
    public const DIRETORIO = 'financeiro/migracao';

    /**
     * Origens que o caixa atual conta como entrada de dinheiro.
     *
     * @var array<int, string>
     */
    private const ORIGENS_DE_ENTRADA = ['work_order', 'manual'];

    /**
     * Origens que o caixa atual conta como saída de dinheiro.
     *
     * @var array<int, string>
     */
    private const ORIGENS_DE_SAIDA = ['payment_reopen', 'manual_withdrawal'];

    /**
     * Chave usada quando o recebimento não tem lançamento de caixa vinculado.
     * Ela é comparada dos dois lados, e é o que prova que o vínculo
     * `financial_entry_id` sobreviveu à migração exatamente como estava.
     */
    private const SEM_LANCAMENTO = 'sem_lancamento';

    /**
     * Chave usada quando o lançamento existe mas não tem forma de pagamento.
     */
    private const SEM_FORMA = 'nao_informado';

    /**
     * Chave usada para recebimento sem data (não deveria existir; se existir,
     * aparece na conferência em vez de sumir dentro de outro mês).
     */
    private const SEM_DATA = 'sem_data';

    /**
     * Chave usada para registro sem `company_id`.
     */
    private const SEM_EMPRESA = 'sem_empresa';

    /**
     * Memória do mapa `parcela antiga => forma do lançamento vinculado`
     * durante um único cálculo. `payment_details` é percorrida mais de uma vez
     * (totais intocáveis e totais espelhados), e refazer a consulta dos
     * lançamentos a cada passada seria trabalho repetido sem ganho: dentro do
     * mesmo cálculo o dado não muda, porque a migração não escreve em
     * `financial_entries`. A memória é limpa a cada cálculo novo.
     *
     * @var array<int, string>|null
     */
    private ?array $formaPorParcela = null;

    /**
     * Captura o retrato de agora e grava em `storage/app/financeiro/migracao`,
     * com data e hora no nome do arquivo.
     *
     * Roda **antes** da migração. O arquivo é o que o operador guarda: se algo
     * der errado depois, ele é a prova de quais eram os números.
     *
     * @return array{retrato: array<string, mixed>, caminho: string}
     */
    public function capturar(): array
    {
        $retrato = $this->retratoAtual();

        return [
            'retrato' => $retrato,
            'caminho' => $this->gravar($retrato),
        ];
    }

    /**
     * Todos os totais de agora, por empresa, sem gravar nada.
     *
     * @return array<string, mixed>
     */
    public function retratoAtual(): array
    {
        return [
            'versao' => self::VERSAO,
            'gerado_em' => BusinessDate::agora()->format('Y-m-d H:i:s'),
            'fuso' => BusinessDate::fuso(),
            'empresas' => $this->totaisPorEmpresa(true),
        ];
    }

    /**
     * Compara o retrato capturado antes com os totais de agora.
     *
     * O grupo `intocavel` é recalculado da mesma fonte (o dado antigo, que a
     * migração não toca). O grupo `espelhado` é recalculado pelo modelo novo,
     * somando de volta o que ficou de fora da migração.
     *
     * Devolve a lista de divergências, uma por total. Lista vazia significa
     * que tudo bate centavo a centavo, e é a única saída que autoriza a
     * migração a ser confirmada.
     *
     * @param  array<string, mixed>  $retrato
     * @return list<array{empresa: string, caminho: string, retrato: int, agora: int, diferenca: int, contagem: bool}>
     */
    public function comparar(array $retrato): array
    {
        $antes = $this->achatar($this->empresasDoRetrato($retrato));
        $agora = $this->achatar($this->totaisPorEmpresa(false));

        $caminhos = array_keys($antes + $agora);
        sort($caminhos);

        $divergencias = [];

        foreach ($caminhos as $caminho) {
            $valorAntes = (int) ($antes[$caminho] ?? 0);
            $valorAgora = (int) ($agora[$caminho] ?? 0);

            if ($valorAntes === $valorAgora) {
                continue;
            }

            $divergencias[] = [
                'empresa' => $this->empresaDoCaminho($caminho),
                'caminho' => $caminho,
                'retrato' => $valorAntes,
                'agora' => $valorAgora,
                'diferenca' => $valorAgora - $valorAntes,
                'contagem' => str_contains($caminho, 'qtd_'),
            ];
        }

        return $divergencias;
    }

    /**
     * Descreve uma divergência em uma linha, pronta para o terminal e para o
     * relatório. Valor de dinheiro sai formatado, contagem sai como número.
     *
     * @param  array{empresa: string, caminho: string, retrato: int, agora: int, diferenca: int, contagem: bool}  $divergencia
     */
    public function descreverDivergencia(array $divergencia): string
    {
        $formatar = $divergencia['contagem']
            ? static fn (int $valor): string => (string) $valor
            : static fn (int $valor): string => Dinheiro::formatar($valor);

        return sprintf(
            '%s: retrato = %s, agora = %s, diferença = %s',
            $divergencia['caminho'],
            $formatar($divergencia['retrato']),
            $formatar($divergencia['agora']),
            $formatar($divergencia['diferenca'])
        );
    }

    /**
     * Grava o retrato em JSON e devolve o caminho absoluto.
     */
    public function gravar(array $retrato): string
    {
        $diretorio = storage_path('app/'.self::DIRETORIO);

        File::ensureDirectoryExists($diretorio);

        $caminho = $diretorio.'/retrato-totais-'.BusinessDate::agora()->format('Y-m-d_His').'.json';

        File::put($caminho, json_encode(
            $retrato,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ).PHP_EOL);

        return $caminho;
    }

    /**
     * Lê um retrato já gravado. Usado para conferir a migração contra o
     * retrato tirado antes da janela de manutenção, e não contra um retrato
     * novo capturado depois de o dado já ter mudado.
     *
     * @return array<string, mixed>
     */
    public function ler(string $caminho): array
    {
        if (! File::exists($caminho)) {
            throw new RuntimeException("Retrato de totais não encontrado em \"{$caminho}\".");
        }

        $conteudo = json_decode(File::get($caminho), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($conteudo) || ! isset($conteudo['empresas']) || ! is_array($conteudo['empresas'])) {
            throw new RuntimeException("Arquivo de retrato inválido: \"{$caminho}\".");
        }

        if (($conteudo['versao'] ?? null) !== self::VERSAO) {
            throw new RuntimeException(sprintf(
                'Retrato "%s" está na versão %s e esta conferência lê a versão %d. '
                .'Capture um retrato novo em vez de comparar formatos diferentes.',
                $caminho,
                var_export($conteudo['versao'] ?? null, true),
                self::VERSAO
            ));
        }

        return $conteudo;
    }

    // -----------------------------------------------------------------
    // Cálculo dos totais
    // -----------------------------------------------------------------

    /**
     * Monta os dois grupos de total, por empresa.
     *
     * @param  bool  $espelhadoPeloModeloAntigo  `true` no retrato (antes da
     *                                           migração, tudo vem do modelo
     *                                           antigo); `false` na
     *                                           conferência (o que foi migrado
     *                                           vem do modelo novo, o que
     *                                           ficou de fora continua vindo do
     *                                           antigo).
     * @return array<string, array<string, mixed>>
     */
    private function totaisPorEmpresa(bool $espelhadoPeloModeloAntigo): array
    {
        $this->formaPorParcela = null;

        $intocavel = $this->totaisIntocaveis();
        $espelhado = $espelhadoPeloModeloAntigo
            ? $this->espelhadoPeloModeloAntigo()
            : $this->espelhadoPeloModeloNovo();

        $empresas = [];

        foreach (array_unique(array_merge(array_keys($intocavel), array_keys($espelhado))) as $empresa) {
            $empresas[$empresa] = [
                'intocavel' => $intocavel[$empresa] ?? [],
                'espelhado' => $espelhado[$empresa] ?? [],
            ];
        }

        ksort($empresas);

        return $empresas;
    }

    /**
     * Totais que a migração não pode alterar, porque ela não escreve em
     * nenhuma das tabelas que os produzem.
     *
     * @return array<string, array<string, mixed>>
     */
    private function totaisIntocaveis(): array
    {
        $empresas = [];

        $this->somarParcelasAntigas($empresas);
        $this->somarLancamentos($empresas);
        $this->somarSaldosDiarios($empresas);
        $this->somarAReceberPorOs($empresas);

        foreach ($empresas as $empresa => $totais) {
            $empresas[$empresa] = $this->ordenarProfundamente($totais);
        }

        return $empresas;
    }

    /**
     * Conferência de `payment_details`: contagem, soma do valor das parcelas,
     * soma do que foi recebido e contagem por status gravado.
     *
     * @param  array<string, array<string, mixed>>  $empresas
     */
    private function somarParcelasAntigas(array &$empresas): void
    {
        foreach ($this->parcelasAntigas() as $parcela) {
            $empresa = $parcela['empresa'];

            $this->acumular($empresas, [$empresa, 'payment_details', 'qtd_registros'], 1);
            $this->acumular($empresas, [$empresa, 'payment_details', 'soma_valor_das_parcelas'], $parcela['valor']);
            $this->acumular($empresas, [$empresa, 'payment_details', 'soma_recebido'], $parcela['recebido']);
            $this->acumular($empresas, [$empresa, 'payment_details', 'qtd_por_status', $parcela['status']], 1);
            $this->acumular($empresas, [$empresa, 'payment_details', 'soma_recebido_por_forma_da_parcela', $parcela['forma_da_parcela']], $parcela['recebido']);
        }
    }

    /**
     * Conferência de `financial_entries` e dos painéis que leem essa tabela:
     * o resumo, o gráfico por tipo, o gráfico por forma de pagamento e a
     * evolução mensal de `FinancialDashboardController`, mais o fluxo de caixa.
     *
     * @param  array<string, array<string, mixed>>  $empresas
     */
    private function somarLancamentos(array &$empresas): void
    {
        $consulta = DB::table('financial_entries')
            ->select(['company_id', 'source', 'status', 'amount', 'entry_date', 'payment_method'])
            ->orderBy('id');

        foreach ($consulta->cursor() as $lancamento) {
            $empresa = $this->chaveDaEmpresa($lancamento->company_id);
            $valor = Dinheiro::centavos($lancamento->amount);
            $origem = (string) ($lancamento->source ?? 'sem_origem');
            $situacao = (string) ($lancamento->status ?? 'sem_situacao');

            $this->acumular($empresas, [$empresa, 'financial_entries', 'qtd_registros'], 1);
            $this->acumular($empresas, [$empresa, 'financial_entries', 'qtd_por_situacao', $situacao], 1);

            if ($situacao !== 'confirmed') {
                continue;
            }

            $this->acumular($empresas, [$empresa, 'financial_entries', 'soma_confirmada_por_origem', $origem], $valor);

            $ehEntrada = in_array($origem, self::ORIGENS_DE_ENTRADA, true);
            $ehSaida = in_array($origem, self::ORIGENS_DE_SAIDA, true);

            if ($ehEntrada) {
                $chave = $origem === 'work_order' ? 'entradas_de_os' : 'entradas_manuais';

                $this->acumular($empresas, [$empresa, 'painel_resumo', $chave], $valor);
                $this->acumular($empresas, [$empresa, 'painel_resumo', 'qtd_lancamentos_de_entrada'], 1);
                $this->acumular($empresas, [$empresa, 'painel_resumo', 'liquido'], $valor);
                $this->acumular($empresas, [$empresa, 'painel_por_tipo', $origem], $valor);

                if ($lancamento->payment_method !== null && $lancamento->payment_method !== '') {
                    $this->acumular($empresas, [$empresa, 'painel_por_forma', (string) $lancamento->payment_method], $valor);
                }
            }

            if ($ehSaida) {
                $this->acumular($empresas, [$empresa, 'painel_resumo', 'saidas'], $valor);
                $this->acumular($empresas, [$empresa, 'painel_resumo', 'liquido'], -$valor);
            }

            if ($ehEntrada || $ehSaida) {
                $this->acumular(
                    $empresas,
                    [$empresa, 'painel_por_mes', $this->mesDe($lancamento->entry_date)],
                    $ehEntrada ? $valor : -$valor
                );
            }
        }
    }

    /**
     * Saldo por dia, exatamente como `daily_cash_balances` guarda hoje: é o
     * número que aparece no fechamento de caixa e no painel de saldo diário.
     *
     * @param  array<string, array<string, mixed>>  $empresas
     */
    private function somarSaldosDiarios(array &$empresas): void
    {
        $consulta = DB::table('daily_cash_balances')
            ->select(['company_id', 'balance_date', 'opening_balance', 'total_entries', 'total_withdrawals', 'closing_balance', 'entries_count', 'withdrawals_count'])
            ->orderBy('balance_date');

        foreach ($consulta->cursor() as $saldo) {
            $empresa = $this->chaveDaEmpresa($saldo->company_id);
            $dia = $this->diaDe($saldo->balance_date);

            $this->acumular($empresas, [$empresa, 'saldo_por_dia', $dia, 'abertura'], Dinheiro::centavos($saldo->opening_balance));
            $this->acumular($empresas, [$empresa, 'saldo_por_dia', $dia, 'entradas'], Dinheiro::centavos($saldo->total_entries));
            $this->acumular($empresas, [$empresa, 'saldo_por_dia', $dia, 'saidas'], Dinheiro::centavos($saldo->total_withdrawals));
            $this->acumular($empresas, [$empresa, 'saldo_por_dia', $dia, 'fechamento'], Dinheiro::centavos($saldo->closing_balance));
            $this->acumular($empresas, [$empresa, 'saldo_por_dia', $dia, 'qtd_entradas'], (int) $saldo->entries_count);
            $this->acumular($empresas, [$empresa, 'saldo_por_dia', $dia, 'qtd_saidas'], (int) $saldo->withdrawals_count);
        }
    }

    /**
     * "A receber" como a ordem de serviço mostra hoje: valor final da OS menos
     * o que já entrou nela (`WorkOrder::remaining_amount`).
     *
     * Depende de `work_orders` e de `payment_details`, duas tabelas que a
     * migração não toca, e por isso vive no grupo intocável.
     *
     * @param  array<string, array<string, mixed>>  $empresas
     */
    private function somarAReceberPorOs(array &$empresas): void
    {
        $recebidoPorOs = [];

        foreach ($this->parcelasAntigas() as $parcela) {
            if ($parcela['ordem'] === null) {
                continue;
            }

            $recebidoPorOs[$parcela['ordem']] = ($recebidoPorOs[$parcela['ordem']] ?? 0) + $parcela['recebido'];
        }

        $consulta = DB::table('work_orders')
            ->select(['id', 'company_id', 'final_amount'])
            ->orderBy('id');

        foreach ($consulta->cursor() as $ordem) {
            $empresa = $this->chaveDaEmpresa($ordem->company_id);
            $saldo = Dinheiro::centavos($ordem->final_amount) - ($recebidoPorOs[(int) $ordem->id] ?? 0);

            $this->acumular($empresas, [$empresa, 'a_receber_por_os'], max($saldo, 0));
        }
    }

    /**
     * Totais espelhados calculados inteiramente pelo modelo antigo. É o que
     * entra no retrato, capturado antes de qualquer gravação.
     *
     * @return array<string, array<string, mixed>>
     */
    private function espelhadoPeloModeloAntigo(): array
    {
        $empresas = [];

        foreach ($this->parcelasAntigas() as $parcela) {
            $this->acumularEspelhado($empresas, $parcela);
        }

        return $this->ordenarEmpresas($empresas);
    }

    /**
     * Totais espelhados depois da migração: o que virou parcela de título vem
     * do modelo novo; o que ficou de fora (caso ambíguo que virou exceção)
     * continua vindo do modelo antigo.
     *
     * A soma dos dois precisa ser idêntica ao retrato. Parcela que sumisse no
     * caminho, ou que fosse migrada duas vezes, aparece aqui como divergência.
     *
     * @return array<string, array<string, mixed>>
     */
    private function espelhadoPeloModeloNovo(): array
    {
        $empresas = [];
        $migradas = [];

        $consulta = DB::table('receivable_installments')
            ->leftJoin('financial_entries', 'financial_entries.id', '=', 'receivable_installments.financial_entry_id')
            ->whereNotNull('receivable_installments.payment_detail_id')
            ->select([
                'receivable_installments.company_id',
                'receivable_installments.payment_detail_id',
                'receivable_installments.valor',
                'receivable_installments.valor_pago',
                'receivable_installments.pago_em',
                'receivable_installments.financial_entry_id',
                'financial_entries.payment_method',
            ])
            ->orderBy('receivable_installments.id');

        foreach ($consulta->cursor() as $parcela) {
            $migradas[(int) $parcela->payment_detail_id] = true;

            $valor = Dinheiro::centavos($parcela->valor);
            $recebido = Dinheiro::centavos($parcela->valor_pago);

            $this->acumularEspelhado($empresas, [
                'empresa' => $this->chaveDaEmpresa($parcela->company_id),
                'valor' => $valor,
                'recebido' => max($recebido, 0),
                'a_receber' => $recebido > 0 ? max($valor - $recebido, 0) : $valor,
                'mes_do_recebimento' => $recebido > 0 ? $this->mesDe($parcela->pago_em) : null,
                'forma_do_lancamento' => $parcela->financial_entry_id === null
                    ? self::SEM_LANCAMENTO
                    : $this->formaOuPadrao($parcela->payment_method),
            ]);
        }

        foreach ($this->parcelasAntigas() as $parcela) {
            if (isset($migradas[$parcela['id']])) {
                continue;
            }

            $this->acumularEspelhado($empresas, $parcela);
        }

        return $this->ordenarEmpresas($empresas);
    }

    /**
     * Soma uma parcela (de qualquer um dos dois modelos) nos totais
     * espelhados.
     *
     * @param  array<string, array<string, mixed>>  $empresas
     * @param  array{empresa: string, valor: int, recebido: int, a_receber: int, mes_do_recebimento: ?string, forma_do_lancamento: string}  $parcela
     */
    private function acumularEspelhado(array &$empresas, array $parcela): void
    {
        $empresa = $parcela['empresa'];

        $this->acumular($empresas, [$empresa, 'qtd_parcelas'], 1);
        $this->acumular($empresas, [$empresa, 'a_receber'], $parcela['a_receber']);
        $this->acumular($empresas, [$empresa, 'recebido_total'], $parcela['recebido']);

        if ($parcela['recebido'] > 0) {
            $this->acumular($empresas, [$empresa, 'qtd_parcelas_com_recebimento'], 1);
            $this->acumular($empresas, [$empresa, 'recebido_por_mes', $parcela['mes_do_recebimento'] ?? self::SEM_DATA], $parcela['recebido']);
            $this->acumular($empresas, [$empresa, 'recebido_por_forma', $parcela['forma_do_lancamento']], $parcela['recebido']);

            return;
        }

        $this->acumular($empresas, [$empresa, 'qtd_parcelas_em_aberto'], 1);
    }

    // -----------------------------------------------------------------
    // Leitura do modelo antigo
    // -----------------------------------------------------------------

    /**
     * Lê `payment_details` já traduzido para os números que interessam, com a
     * mesma semântica que o sistema usa hoje:
     *
     * - Recebimento só existe com `payment_date` preenchida **e**
     *   `amount_paid` maior que zero. É o critério de `scopePaid()`, do
     *   accessor de status e de `PaymentDetailService::totalRecebidoDaOrdem()`:
     *   valor sem data de pagamento é dinheiro a receber, nunca dinheiro que
     *   entrou.
     * - O valor da parcela vive em `final_amount`. Registro antigo, anterior à
     *   correção de `PaymentDetailService`, tem `final_amount` nulo e o valor
     *   em `amount_paid`; nesse caso o valor da parcela é `amount_paid`, como
     *   `parcelaEmAbertoComValor()` também considera.
     * - A forma de pagamento comparada é a do **lançamento de caixa vinculado**
     *   (`financial_entries.payment_detail_id`), não a da parcela: é ela que o
     *   modelo novo consegue responder, pelo `financial_entry_id` da parcela.
     *   Recebimento sem lançamento entra em `sem_lancamento`, dos dois lados.
     *
     * @return \Generator<int, array{id: int, empresa: string, ordem: ?int, valor: int, recebido: int, a_receber: int, mes_do_recebimento: ?string, forma_do_lancamento: string, forma_da_parcela: string, status: string}>
     */
    private function parcelasAntigas(): \Generator
    {
        $formaPorParcela = $this->formaPorParcela ??= $this->formaDoLancamentoPorParcela();

        $consulta = DB::table('payment_details')
            ->select(['id', 'company_id', 'work_order_id', 'final_amount', 'amount_paid', 'payment_date', 'payment_method', 'payment_status'])
            ->orderBy('id');

        foreach ($consulta->cursor() as $parcela) {
            $centavosFinal = Dinheiro::centavos($parcela->final_amount);
            $centavosPagos = Dinheiro::centavos($parcela->amount_paid);
            $recebeu = $parcela->payment_date !== null && $centavosPagos > 0;

            $recebido = $recebeu ? $centavosPagos : 0;
            $valor = $centavosFinal > 0 ? $centavosFinal : max($centavosPagos, 0);

            yield [
                'id' => (int) $parcela->id,
                'empresa' => $this->chaveDaEmpresa($parcela->company_id),
                'ordem' => $parcela->work_order_id === null ? null : (int) $parcela->work_order_id,
                'valor' => $valor,
                'recebido' => $recebido,
                'a_receber' => $recebeu ? max($valor - $recebido, 0) : $valor,
                'mes_do_recebimento' => $recebeu ? $this->mesDe($parcela->payment_date) : null,
                'forma_do_lancamento' => $recebeu
                    ? ($formaPorParcela[(int) $parcela->id] ?? self::SEM_LANCAMENTO)
                    : self::SEM_LANCAMENTO,
                'forma_da_parcela' => $this->formaOuPadrao($parcela->payment_method),
                'status' => (string) ($parcela->payment_status ?? 'sem_status'),
            ];
        }
    }

    /**
     * Forma de pagamento do lançamento de recebimento vinculado a cada
     * parcela antiga.
     *
     * Quando a parcela foi reaberta e paga de novo existe mais de um
     * lançamento de entrada com o mesmo `payment_detail_id`; vale o mais
     * recente, que é o recebimento vigente (o anterior já foi compensado por
     * uma saída `payment_reopen`).
     *
     * @return array<int, string>
     */
    private function formaDoLancamentoPorParcela(): array
    {
        $formas = [];

        $consulta = DB::table('financial_entries')
            ->whereNotNull('payment_detail_id')
            ->where('source', 'work_order')
            ->where('status', 'confirmed')
            ->select(['payment_detail_id', 'payment_method'])
            ->orderBy('created_at')
            ->orderBy('id');

        foreach ($consulta->cursor() as $lancamento) {
            $formas[(int) $lancamento->payment_detail_id] = $this->formaOuPadrao($lancamento->payment_method);
        }

        return $formas;
    }

    // -----------------------------------------------------------------
    // Utilitários
    // -----------------------------------------------------------------

    /**
     * Soma um valor na posição indicada de um array aninhado, criando o
     * caminho quando ele ainda não existe.
     *
     * @param  array<string, mixed>  $destino
     * @param  array<int, string>  $caminho
     */
    private function acumular(array &$destino, array $caminho, int $valor): void
    {
        $atual = &$destino;

        foreach ($caminho as $chave) {
            if (! isset($atual[$chave]) || ! is_array($atual[$chave])) {
                $atual[$chave] = $atual[$chave] ?? [];
            }

            $atual = &$atual[$chave];
        }

        $atual = (is_array($atual) ? 0 : $atual) + $valor;
    }

    /**
     * @param  array<string, array<string, mixed>>  $empresas
     * @return array<string, array<string, mixed>>
     */
    private function ordenarEmpresas(array $empresas): array
    {
        foreach ($empresas as $empresa => $totais) {
            $empresas[$empresa] = $this->ordenarProfundamente($totais);
        }

        ksort($empresas);

        return $empresas;
    }

    /**
     * Ordena as chaves recursivamente, para o JSON gravado ficar estável entre
     * duas capturas e ser comparável a olho.
     *
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    private function ordenarProfundamente(array $dados): array
    {
        ksort($dados);

        foreach ($dados as $chave => $valor) {
            if (is_array($valor)) {
                $dados[$chave] = $this->ordenarProfundamente($valor);
            }
        }

        return $dados;
    }

    /**
     * Achata o array aninhado em um mapa `caminho.pontuado => valor`, para a
     * comparação enxergar total por total, inclusive os que existem em um lado
     * só.
     *
     * @param  array<array-key, mixed>  $dados
     * @return array<string, int>
     */
    private function achatar(array $dados, string $prefixo = ''): array
    {
        $plano = [];

        foreach ($dados as $chave => $valor) {
            $caminho = $prefixo === '' ? (string) $chave : $prefixo.'.'.$chave;

            if (is_array($valor)) {
                $plano = array_merge($plano, $this->achatar($valor, $caminho));

                continue;
            }

            $plano[$caminho] = (int) $valor;
        }

        return $plano;
    }

    /**
     * @param  array<string, mixed>  $retrato
     * @return array<array-key, mixed>
     */
    private function empresasDoRetrato(array $retrato): array
    {
        $empresas = $retrato['empresas'] ?? null;

        if (! is_array($empresas)) {
            throw new RuntimeException('Retrato de totais sem a chave "empresas": não há o que comparar.');
        }

        return $empresas;
    }

    private function empresaDoCaminho(string $caminho): string
    {
        return explode('.', $caminho)[0];
    }

    private function chaveDaEmpresa(mixed $companyId): string
    {
        return $companyId === null ? self::SEM_EMPRESA : (string) $companyId;
    }

    private function formaOuPadrao(mixed $forma): string
    {
        return $forma === null || $forma === '' ? self::SEM_FORMA : (string) $forma;
    }

    /**
     * Dia (`Y-m-d`) de um campo `date`, sem conversão de fuso.
     */
    private function diaDe(mixed $valor): string
    {
        return BusinessDate::diaDe($valor) ?? self::SEM_DATA;
    }

    /**
     * Competência (`Y-m`) de um campo `date`. Lida do próprio dia, nunca
     * convertendo fuso: converter é o que joga o recebimento do dia 1 para o
     * mês anterior.
     */
    private function mesDe(mixed $valor): string
    {
        $dia = BusinessDate::diaDe($valor);

        return $dia === null ? self::SEM_DATA : substr($dia, 0, 7);
    }
}
