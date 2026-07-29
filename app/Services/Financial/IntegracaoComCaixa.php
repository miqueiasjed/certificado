<?php

namespace App\Services\Financial;

use App\Models\FinancialEntry;
use App\Models\PayableInstallment;
use App\Models\ReceivableInstallment;
use App\Models\User;
use App\Support\BusinessDate;
use App\Support\Dinheiro;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Ponto ÚNICO de criação de lançamento de caixa (`FinancialEntry`) a partir de
 * um título financeiro (Plano 18, Task 18.4).
 *
 * Por que existir uma classe só para isso
 * ---------------------------------------
 * Dois caminhos criando lançamento a partir de título é exatamente como o
 * mesmo dinheiro entra duas vezes no fluxo de caixa: a baixa cria a entrada,
 * a tela de conferência "acerta" criando outra, e o extrato do banco passa a
 * divergir do sistema sem que ninguém saiba qual dos dois está certo. Nenhum
 * outro Service deste plano chama `FinancialEntry::create()`: quem precisa de
 * lançamento chama esta classe.
 *
 * Os dois lados, entrada e saída
 * ------------------------------
 * A API é a mesma para as quatro operações do módulo financeiro, e o que muda
 * entre elas é só a natureza do dinheiro:
 *
 * | operação                              | natureza | quem usa            |
 * |---------------------------------------|----------|---------------------|
 * | recebimento de parcela a receber      | entrada  | Task 18.4 (aqui)    |
 * | estorno de recebimento                | saída    | Task 18.4 (aqui)    |
 * | pagamento de parcela a pagar          | saída    | Task 18.5           |
 * | estorno de pagamento                  | entrada  | Task 18.5           |
 *
 * As duas primeiras já têm método próprio nesta classe
 * (`registrarRecebimento()` e `estornarRecebimento()`). As duas do lado de
 * pagar são dois métodos irmãos que a Task 18.5 acrescenta aqui mesmo,
 * chamando `lancar()` com `NATUREZA_SAIDA` e `NATUREZA_ENTRADA`; o motor,
 * validação, mapeamento de origem e gravação já estão prontos e não precisam
 * ser reescritos lá.
 *
 * Mapeamento com o caixa que já existe
 * ------------------------------------
 * O caixa atual (`DailyCashBalance`, painel financeiro, fluxo de caixa,
 * dashboard) classifica lançamento pela coluna `source`: `work_order` e
 * `manual` contam como entrada, `payment_reopen` e `manual_withdrawal` contam
 * como saída. Esta classe grava dentro desse vocabulário, de propósito:
 *
 * - Lançamento nascido de título aparece no painel e no saldo diário do dia
 *   certo sem nenhuma alteração nas telas que o cliente usa hoje. Inventar um
 *   `source` novo agora deixaria o dinheiro fora do saldo diário até a Task
 *   18.7 trocar a leitura dos painéis, e caixa que não bate é pior que
 *   vocabulário provisório.
 * - `work_order` e `payment_reopen` são as duas origens que
 *   `FinancialEntryController` bloqueia para edição e exclusão manual. O
 *   lançamento de uma baixa não pode ser editado por fora, ou a parcela e o
 *   caixa passam a discordar sobre quanto entrou.
 *
 * Quando a Task 18.7 introduzir origens próprias de título, a mudança
 * acontece em `SOURCE_POR_NATUREZA`, em um lugar só.
 *
 * Data
 * ----
 * `entry_date` é sempre o dia informado por quem chama, no fuso do negócio, e
 * nunca `now()`: o recebimento é lançado no sistema dias depois do depósito, e
 * a competência do caixa é o dia em que o dinheiro entrou.
 */
final class IntegracaoComCaixa
{
    /**
     * Dinheiro entrando no caixa: recebimento de cliente e estorno de um
     * pagamento a fornecedor.
     */
    public const NATUREZA_ENTRADA = 'entrada';

    /**
     * Dinheiro saindo do caixa: pagamento a fornecedor e estorno de um
     * recebimento.
     */
    public const NATUREZA_SAIDA = 'saida';

    /**
     * Origem gravada em `financial_entries.source` para cada natureza. Ver o
     * cabeçalho da classe: são os valores que o caixa atual já classifica, e
     * que o painel de lançamentos protege contra edição manual.
     *
     * @var array<string, string>
     */
    private const SOURCE_POR_NATUREZA = [
        self::NATUREZA_ENTRADA => 'work_order',
        self::NATUREZA_SAIDA => 'payment_reopen',
    ];

    /**
     * Lançamento nascido de título já é dinheiro conferido: nasce confirmado,
     * porque é ele que move o saldo diário.
     */
    private const STATUS_CONFIRMADO = 'confirmed';

    /**
     * `financial_entries.description` é `string` (255). O texto do título
     * entra truncado para o limite total nunca estourar a coluna.
     */
    private const LIMITE_DA_DESCRICAO_DO_TITULO = 150;

    /**
     * Cria a entrada de caixa do recebimento de uma parcela a receber.
     *
     * @param  string  $valor  Valor recebido, em decimal ("250.00").
     * @param  string  $dia  Data do recebimento (`Y-m-d`), informada pelo
     *                       usuário, nunca "hoje".
     */
    public function registrarRecebimento(
        ReceivableInstallment $parcela,
        string $valor,
        string $dia,
        User $usuario,
        ?string $formaPagamento = null,
        ?string $observacao = null,
    ): FinancialEntry {
        $titulo = $parcela->receivable()->first();

        return $this->lancar(
            natureza: self::NATUREZA_ENTRADA,
            valor: $valor,
            dia: $dia,
            descricao: $this->descrever('Recebimento', $parcela, $titulo?->descricao),
            usuario: $usuario,
            formaPagamento: $formaPagamento,
            referencia: $this->referencia('RECEB', $parcela),
            observacao: $observacao,
            ordemDeServicoId: $titulo?->work_order_id,
        );
    }

    /**
     * Cria a saída de caixa que reverte um recebimento já lançado.
     *
     * O lançamento original nunca é apagado nem alterado: o estorno é um
     * lançamento novo, em sentido contrário. É o que mantém o caixa de um dia
     * já fechado exatamente como estava quando foi fechado.
     *
     * @param  string  $valor  Valor a estornar, em decimal ("250.00").
     * @param  string  $dia  Dia do estorno (`Y-m-d`), que é o dia em que o
     *                       estorno acontece, e não o dia do recebimento
     *                       original.
     */
    public function estornarRecebimento(
        ReceivableInstallment $parcela,
        string $valor,
        string $dia,
        string $motivo,
        User $usuario,
        ?string $formaPagamento = null,
    ): FinancialEntry {
        $titulo = $parcela->receivable()->first();

        return $this->lancar(
            natureza: self::NATUREZA_SAIDA,
            valor: $valor,
            dia: $dia,
            descricao: $this->descrever('Estorno de recebimento', $parcela, $titulo?->descricao),
            usuario: $usuario,
            formaPagamento: $formaPagamento,
            referencia: $this->referencia('ESTORNO', $parcela),
            observacao: 'Motivo do estorno: '.$motivo,
            ordemDeServicoId: $titulo?->work_order_id,
        );
    }

    /**
     * Cria a saída de caixa do pagamento de uma parcela a pagar (Task 18.5).
     * Irmã de `registrarRecebimento()`, do lado de saída: mesmo motor
     * (`lancar()`), natureza oposta.
     *
     * @param  string  $valor  Valor pago, em decimal ("250.00").
     * @param  string  $dia  Data do pagamento (`Y-m-d`), informada pelo
     *                       usuário, nunca "hoje": o boleto pode ser lançado no
     *                       sistema dias depois de pago.
     */
    public function registrarPagamento(
        PayableInstallment $parcela,
        string $valor,
        string $dia,
        User $usuario,
        ?string $formaPagamento = null,
        ?string $observacao = null,
    ): FinancialEntry {
        $titulo = $parcela->payable()->first();

        return $this->lancar(
            natureza: self::NATUREZA_SAIDA,
            valor: $valor,
            dia: $dia,
            descricao: $this->descrever('Pagamento', $parcela, $titulo?->descricao),
            usuario: $usuario,
            formaPagamento: $formaPagamento,
            referencia: $this->referencia('PAGTO', $parcela),
            observacao: $observacao,
            ordemDeServicoId: $titulo?->work_order_id,
        );
    }

    /**
     * Cria a entrada de caixa que reverte um pagamento já lançado. Irmã de
     * `estornarRecebimento()`, do lado de saída: o pagamento original nunca é
     * apagado nem alterado, e o estorno acontece no dia de hoje, nunca no dia
     * do pagamento original, pelo mesmo motivo documentado no cabeçalho da
     * classe.
     *
     * @param  string  $valor  Valor a estornar, em decimal ("250.00").
     * @param  string  $dia  Dia do estorno (`Y-m-d`), que é hoje.
     */
    public function estornarPagamento(
        PayableInstallment $parcela,
        string $valor,
        string $dia,
        string $motivo,
        User $usuario,
        ?string $formaPagamento = null,
    ): FinancialEntry {
        $titulo = $parcela->payable()->first();

        return $this->lancar(
            natureza: self::NATUREZA_ENTRADA,
            valor: $valor,
            dia: $dia,
            descricao: $this->descrever('Estorno de pagamento', $parcela, $titulo?->descricao),
            usuario: $usuario,
            formaPagamento: $formaPagamento,
            referencia: $this->referencia('ESTORNO-PAGTO', $parcela),
            observacao: 'Motivo do estorno: '.$motivo,
            ordemDeServicoId: $titulo?->work_order_id,
        );
    }

    /**
     * Grava o lançamento de caixa. Motor único das quatro operações da
     * tabela no cabeçalho da classe.
     *
     * Sem transação própria de propósito: quem chama já está dentro da
     * transação que atualiza a parcela, e o lançamento precisa nascer ou
     * falhar junto com ela. Abrir uma transação aqui criaria uma transação
     * aninhada que confirma sozinha em alguns bancos, que é justamente o
     * cenário de caixa e parcela dessincronizados.
     *
     * @param  string  $natureza  `NATUREZA_ENTRADA` ou `NATUREZA_SAIDA`.
     * @param  string  $valor  Valor em decimal, sempre positivo: o sentido do
     *                         dinheiro vem da natureza, não do sinal.
     * @param  string  $dia  Competência do lançamento (`Y-m-d`).
     */
    public function lancar(
        string $natureza,
        string $valor,
        string $dia,
        string $descricao,
        User $usuario,
        ?string $formaPagamento = null,
        ?string $referencia = null,
        ?string $observacao = null,
        ?int $ordemDeServicoId = null,
    ): FinancialEntry {
        $source = self::SOURCE_POR_NATUREZA[$natureza] ?? null;

        if ($source === null) {
            throw new InvalidArgumentException(
                "Natureza de lançamento inválida: \"{$natureza}\". Use IntegracaoComCaixa::NATUREZA_ENTRADA ou NATUREZA_SAIDA."
            );
        }

        $centavos = Dinheiro::centavos($valor);

        if ($centavos <= 0) {
            throw new InvalidArgumentException(
                'Lançamento de caixa precisa de valor maior que zero. Valor recebido: '.$valor.'.'
            );
        }

        $competencia = BusinessDate::diaDe($dia);

        if ($competencia === null) {
            throw new InvalidArgumentException("Data de lançamento inválida: \"{$dia}\".");
        }

        return FinancialEntry::create([
            'source' => $source,
            'amount' => Dinheiro::paraDecimal($centavos),
            'description' => $descricao,
            'entry_date' => $competencia,
            'payment_method' => $formaPagamento,
            'reference_number' => $referencia,
            'notes' => $observacao,
            'status' => self::STATUS_CONFIRMADO,
            'work_order_id' => $ordemDeServicoId,
            // `payment_detail_id` fica de fora de propósito: a Task 18.2 amarra
            // a parcela ao registro antigo (`payment_details`), e preencher
            // esta coluna aqui faria a reabertura de pagamento do fluxo atual
            // (`PaymentDetailController`) enxergar o lançamento do título como
            // se fosse dela, criando compensação em cima de dinheiro que ela
            // não lançou.
            'payment_detail_id' => null,
            'created_by' => $usuario->id,
        ]);
    }

    /**
     * Descrição do lançamento: o que aconteceu, em qual parcela, de qual
     * título. É o texto que aparece no fluxo de caixa.
     *
     * Aceita a parcela dos dois lados (`numero` existe nos dois models), para
     * o recebimento e o pagamento compartilharem a mesma formatação sem
     * duplicar o método.
     */
    private function descrever(string $acao, ReceivableInstallment|PayableInstallment $parcela, ?string $descricaoDoTitulo): string
    {
        $titulo = Str::limit((string) $descricaoDoTitulo, self::LIMITE_DA_DESCRICAO_DO_TITULO);

        return trim("{$acao} da parcela {$parcela->numero} - {$titulo}", ' -');
    }

    /**
     * Número de referência do lançamento, com prefixo e id da parcela: é por
     * ele que a conferência liga o lançamento de volta à parcela, inclusive
     * no estorno, em que a parcela deixa de apontar para o lançamento
     * original.
     */
    private function referencia(string $prefixo, ReceivableInstallment|PayableInstallment $parcela): string
    {
        return $prefixo.'-'.$parcela->id.'-'.BusinessDate::agora()->format('YmdHis');
    }
}
