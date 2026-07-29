<?php

namespace App\Services;

use App\Models\Payable;
use App\Models\PayableInstallment;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Financial\IntegracaoComCaixa;
use App\Support\BusinessDate;
use App\Support\Dinheiro;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Título a pagar, com baixa, estorno, cancelamento e recorrência
 * (Plano 18, Task 18.5).
 *
 * Espelha `SettlementService` e `ReceivableService` do lado de pagar, trocando
 * cliente por fornecedor e caixa de entrada por caixa de saída. A baixa e o
 * estorno são praticamente a mesma disciplina do lado de receber: transação
 * única, `lockForUpdate()` na parcela, aritmética em centavos via
 * `App\Support\Dinheiro`, baixa acima do saldo devedor recusada com o valor na
 * mensagem, estorno como lançamento novo (nunca exclusão), e a mesma
 * `IntegracaoComCaixa` como ponto único de criação de `FinancialEntry`, agora
 * pelos métodos `registrarPagamento()`/`estornarPagamento()` (natureza saída
 * e entrada, respectivamente).
 *
 * O que é próprio deste lado é a recorrência: aluguel, assinatura e qualquer
 * despesa que se repete todo mês (ou trimestre, ou ano) sem que alguém precise
 * lançar título novo manualmente.
 *
 * Como a recorrência funciona
 * ---------------------------
 * Um título com `recorrencia` diferente de `nenhuma` é a raiz de uma série.
 * Cada competência seguinte é um título novo (`payables` inteiro, não uma
 * segunda parcela do mesmo título), com `payable_origem_id` apontando de volta
 * para a raiz. É o mesmo desenho de `ReceivableService::gerarDoContrato()`
 * (um título por competência), só que aqui existe idempotência por
 * `[payable_origem_id, competência]` em vez de `[contract_id, competência]`,
 * porque a série nasce de um título avulso, não de um contrato.
 *
 * A regra de negócio não negociável é a **janela de 3 competências**: a série
 * nunca tem mais que as 3 competências correntes/futuras geradas ao mesmo
 * tempo (`self::JANELA_DE_COMPETENCIAS`), nunca tudo até `recorrencia_ate` de
 * uma vez. Um aluguel de contrato de 5 anos não pode virar 60 títulos que
 * ninguém revisa, e mudar o valor não pode significar editar 60 registros.
 * `criar()` garante a janela na hora da criação, e a rotina
 * `financeiro:gerar-pagamentos-recorrentes` garante a mesma janela todo mês,
 * empurrando-a para frente conforme o tempo passa.
 *
 * `manterJanelaDeCompetencias()` é idempotente: rodar duas vezes na mesma
 * competência não duplica título, porque cada geração confere primeiro se a
 * competência já existe na série antes de criar uma nova.
 *
 * Alterar valor de recorrente
 * ----------------------------
 * `alterarValor()` pergunta o alcance ("apenas este" ou "este e os futuros")
 * e nunca toca em parcela já paga: o dinheiro que já entrou no caixa não muda
 * de valor por uma edição posterior.
 *
 * Cancelamento interrompe a série
 * --------------------------------
 * Cancelar qualquer título de uma série recorrente (a raiz ou uma ocorrência
 * gerada) interrompe a geração das competências seguintes daquela série: a
 * regra é fail-safe, então basta um título cancelado em qualquer ponto da
 * série para a geração parar, em vez de continuar cobrando algo que o usuário
 * já sinalizou como encerrado.
 *
 * Entrada de estoque (Plano 17)
 * ------------------------------
 * `gerarDeEntradaDeEstoque()` é o único ponto de entrada que o Plano 17 pode
 * chamar para transformar uma compra de produto em título a pagar do
 * fornecedor. O caminho contrário não existe: baixar ou estornar um pagamento
 * aqui nunca mexe em estoque. Esta task não altera o fluxo de estoque em si,
 * só disponibiliza o método a ser chamado por ele no futuro.
 */
class PayableService
{
    /**
     * Mesma permissão do lado de receber (`SettlementService::PERMISSAO_ESTORNO`):
     * "financeiro-estornar" é uma permissão só, para os dois lados do caixa.
     * Reaproveitar a constante em vez de redeclarar o texto evita as duas
     * pontas divergirem se o nome mudar um dia.
     */
    public const PERMISSAO_ESTORNO = SettlementService::PERMISSAO_ESTORNO;

    /**
     * Tamanho mínimo do motivo do estorno, mesmo critério do lado de receber.
     */
    private const MOTIVO_MINIMO = 10;

    /**
     * Quantidade de competências (correntes ou futuras) que a série
     * recorrente mantém sempre geradas. Nunca mais que isso de uma vez: é o
     * número que impede o aluguel de 5 anos de virar 60 títulos.
     */
    private const JANELA_DE_COMPETENCIAS = 3;

    /**
     * Trava de segurança do laço de geração: nada relacionado a negócio, só o
     * limite de iterações para um bug na condição de parada nunca virar um
     * laço infinito.
     */
    private const TENTATIVAS_MAXIMAS_POR_JANELA = 60;

    public const RECORRENCIA_NENHUMA = 'nenhuma';

    /**
     * Alcance "apenas este" de `alterarValor()`: só o título informado.
     */
    public const ALCANCE_APENAS_ESTE = 'apenas_este';

    /**
     * Alcance "este e os futuros" de `alterarValor()`: o título informado e
     * toda ocorrência da mesma série com competência igual ou posterior.
     */
    public const ALCANCE_ESTE_E_FUTUROS = 'este_e_futuros';

    private const SITUACAO_PARCELA_ABERTA = 'aberta';

    private const SITUACAO_PARCELA_PARCIAL = 'parcial';

    private const SITUACAO_PARCELA_PAGA = 'paga';

    private const SITUACAO_PARCELA_VENCIDA = 'vencida';

    private const SITUACAO_PARCELA_CANCELADA = 'cancelada';

    private const SITUACAO_TITULO_ABERTO = 'aberto';

    private const SITUACAO_TITULO_PARCIAL = 'parcial';

    private const SITUACAO_TITULO_QUITADO = 'quitado';

    private const SITUACAO_TITULO_CANCELADO = 'cancelado';

    /**
     * Meses de intervalo de cada recorrência aceita.
     *
     * @var array<string, int>
     */
    private const MESES_POR_RECORRENCIA = [
        'mensal' => 1,
        'trimestral' => 3,
        'anual' => 12,
    ];

    public function __construct(private readonly IntegracaoComCaixa $caixa) {}

    // -----------------------------------------------------------------
    // Criação
    // -----------------------------------------------------------------

    /**
     * Cria o título a pagar e a parcela única dele. Título com `recorrencia`
     * diferente de `nenhuma` já sai com a janela de competências garantida
     * (ver `manterJanelaDeCompetencias()`).
     *
     * Formato esperado de `$dados`:
     * - `supplier_id` (obrigatório): fornecedor cobrando o título.
     * - `descricao` (obrigatório).
     * - `valor` (obrigatório): valor do título e da parcela única, maior que
     *   zero.
     * - `vencimento` (obrigatório): vencimento da parcela (`Y-m-d`).
     * - `emitido_em` (opcional): dia de emissão (`Y-m-d`). Sem esta chave,
     *   hoje no fuso do negócio. É este campo que carrega a competência do
     *   título, no mesmo critério de `Receivable::emitido_em`.
     * - `chart_of_account_id`, `work_order_id`, `contract_id` (opcionais).
     * - `recorrencia` (opcional, padrão `nenhuma`): `mensal`, `trimestral` ou
     *   `anual`.
     * - `recorrencia_ate` (opcional): data (`Y-m-d`) até quando a série se
     *   repete. Sem esta chave, a série não tem fim planejado (a janela de 3
     *   competências continua sendo mantida indefinidamente pela rotina).
     *
     * @param  array<string, mixed>  $dados
     *
     * @throws ValidationException Dado obrigatório ausente ou inválido.
     */
    public function criar(array $dados): Payable
    {
        $fornecedorId = $this->fornecedorInformado($dados);
        $valorEmCentavos = $this->valorInformado($dados, 'valor', 'Informe o valor do título.');
        $vencimento = $this->dataObrigatoria($dados, 'vencimento', 'Informe o vencimento da parcela.');
        $emitidoEm = $this->dataOpcionalOuHoje($dados, 'emitido_em');
        $descricao = $this->textoObrigatorio($dados, 'descricao', 'Informe a descrição do título.');
        $recorrencia = $this->recorrenciaInformada($dados);
        $recorrenciaAte = $recorrencia === self::RECORRENCIA_NENHUMA
            ? null
            : $this->dataOpcional($dados, 'recorrencia_ate');

        return DB::transaction(function () use ($dados, $fornecedorId, $valorEmCentavos, $vencimento, $emitidoEm, $descricao, $recorrencia, $recorrenciaAte) {
            $valorDecimal = Dinheiro::paraDecimal($valorEmCentavos);

            $titulo = Payable::create([
                'supplier_id' => $fornecedorId,
                'work_order_id' => $dados['work_order_id'] ?? null,
                'contract_id' => $dados['contract_id'] ?? null,
                'chart_of_account_id' => $dados['chart_of_account_id'] ?? null,
                'descricao' => $descricao,
                'valor_total' => $valorDecimal,
                'emitido_em' => $emitidoEm,
                'situacao' => self::SITUACAO_TITULO_ABERTO,
                'recorrencia' => $recorrencia,
                'recorrencia_ate' => $recorrenciaAte,
                'payable_origem_id' => null,
            ]);

            PayableInstallment::create([
                'payable_id' => $titulo->id,
                'numero' => 1,
                'valor' => $valorDecimal,
                'vencimento' => $vencimento,
                'situacao' => self::SITUACAO_PARCELA_ABERTA,
            ]);

            if ($recorrencia !== self::RECORRENCIA_NENHUMA) {
                $this->manterJanelaDeCompetencias($titulo);
            }

            return $titulo->fresh(['installments']);
        });
    }

    /**
     * Gera o título a pagar do fornecedor a partir de uma entrada de estoque
     * (Plano 17). É o único ponto por onde a compra de produto pode virar
     * título financeiro; o caminho contrário não existe, e esta task não
     * altera o fluxo de estoque para chamar este método automaticamente - ele
     * só fica disponível para quando isso for ligado.
     *
     * Sempre sem recorrência: uma nota de compra é um título avulso, nunca uma
     * despesa que se repete sozinha.
     */
    public function gerarDeEntradaDeEstoque(
        Supplier $fornecedor,
        string $descricao,
        string $valor,
        string $vencimento,
        ?int $chartOfAccountId = null,
        ?string $emitidoEm = null,
    ): Payable {
        return $this->criar([
            'supplier_id' => $fornecedor->id,
            'descricao' => $descricao,
            'valor' => $valor,
            'vencimento' => $vencimento,
            'chart_of_account_id' => $chartOfAccountId,
            'emitido_em' => $emitidoEm,
            'recorrencia' => self::RECORRENCIA_NENHUMA,
        ]);
    }

    // -----------------------------------------------------------------
    // Baixa e estorno
    // -----------------------------------------------------------------

    /**
     * Registra o pagamento de uma parcela, total ou parcial. Mesma disciplina
     * de `SettlementService::baixar()`: transação única, parcela travada com
     * `lockForUpdate()`, soma em centavos, e `pago_em` só preenchido quando a
     * parcela termina de ser paga.
     *
     * Formato esperado de `$dados`: `valor`, `data` (obrigatórios),
     * `forma_pagamento`, `observacao` (opcionais), `usuario` (obrigatório).
     *
     * @param  array<string, mixed>  $dados
     *
     * @throws ValidationException Valor acima do saldo devedor, parcela já
     *                             quitada ou cancelada, dados faltando.
     */
    public function baixar(PayableInstallment $parcela, array $dados): PayableInstallment
    {
        $usuario = $this->usuarioInformado($dados);
        $valorEmCentavos = $this->valorInformado($dados, 'valor', 'Informe o valor pago.');
        $dia = $this->diaInformado($dados);
        $formaPagamento = $this->textoOpcional($dados, 'forma_pagamento');
        $observacao = $this->textoOpcional($dados, 'observacao');

        return DB::transaction(function () use ($parcela, $usuario, $valorEmCentavos, $dia, $formaPagamento, $observacao) {
            $parcela = $this->travar($parcela);

            $saldoEmCentavos = $this->saldoDevedorEmCentavos($parcela);

            $this->garantirParcelaBaixavel($parcela, $saldoEmCentavos);
            $this->garantirValorDentroDoSaldo($valorEmCentavos, $saldoEmCentavos);

            $pagoEmCentavos = Dinheiro::centavos($parcela->valor_pago) + $valorEmCentavos;
            $quitada = $pagoEmCentavos >= Dinheiro::centavos($parcela->valor);

            $lancamento = $this->caixa->registrarPagamento(
                parcela: $parcela,
                valor: Dinheiro::paraDecimal($valorEmCentavos),
                dia: $dia,
                usuario: $usuario,
                formaPagamento: $formaPagamento,
                observacao: $observacao,
            );

            $parcela->update([
                'valor_pago' => Dinheiro::paraDecimal($pagoEmCentavos),
                'situacao' => $quitada ? self::SITUACAO_PARCELA_PAGA : self::SITUACAO_PARCELA_PARCIAL,
                'pago_em' => $quitada ? $dia : $parcela->pago_em,
                'financial_entry_id' => $lancamento->id,
            ]);

            $this->atualizarSituacaoDoTitulo($parcela);

            return $parcela->refresh();
        });
    }

    /**
     * Estorna o pagamento de uma parcela, criando o lançamento de reversão no
     * caixa (entrada, já que o dinheiro volta). Mesma disciplina de
     * `SettlementService::estornar()`: motivo obrigatório, permissão exigida,
     * lançamento original nunca apagado, dia do estorno sempre hoje.
     *
     * @throws AuthorizationException Usuário sem a permissão `financeiro-estornar` (403).
     * @throws ValidationException Motivo ausente ou curto demais, parcela sem
     *                             pagamento para estornar.
     */
    public function estornar(PayableInstallment $parcela, string $motivo, User $usuario): PayableInstallment
    {
        $this->garantirPermissaoDeEstorno($usuario);

        $motivo = $this->motivoValido($motivo);
        $dia = BusinessDate::hoje()->toDateString();

        return DB::transaction(function () use ($parcela, $motivo, $usuario, $dia) {
            $parcela = $this->travar($parcela);

            $pagoEmCentavos = Dinheiro::centavos($parcela->valor_pago);

            if ($pagoEmCentavos <= 0) {
                throw ValidationException::withMessages([
                    'parcela' => 'Esta parcela não tem pagamento lançado, então não há o que estornar.',
                ]);
            }

            $lancamento = $this->caixa->estornarPagamento(
                parcela: $parcela,
                valor: Dinheiro::paraDecimal($pagoEmCentavos),
                dia: $dia,
                motivo: $motivo,
                usuario: $usuario,
                formaPagamento: $parcela->financialEntry()->first()?->payment_method,
            );

            $parcela->update([
                'valor_pago' => Dinheiro::paraDecimal(0),
                'pago_em' => null,
                'situacao' => BusinessDate::estaVencido($parcela->vencimento)
                    ? self::SITUACAO_PARCELA_VENCIDA
                    : self::SITUACAO_PARCELA_ABERTA,
                'financial_entry_id' => $lancamento->id,
            ]);

            $this->atualizarSituacaoDoTitulo($parcela);

            return $parcela->refresh();
        });
    }

    /**
     * Quanto ainda falta pagar na parcela, em centavos. Nunca negativo, mesmo
     * critério de `SettlementService::saldoDevedorEmCentavos()`.
     */
    public function saldoDevedorEmCentavos(PayableInstallment $parcela): int
    {
        $saldo = Dinheiro::centavos($parcela->valor) - Dinheiro::centavos($parcela->valor_pago);

        return max($saldo, 0);
    }

    // -----------------------------------------------------------------
    // Cancelamento
    // -----------------------------------------------------------------

    /**
     * Cancela o título: parcela paga é mantida como está, e toda parcela que
     * não está paga vira/permanece cancelada. Mesmo critério de
     * `ReceivableService::cancelar()`.
     *
     * Quando o título pertence a uma série recorrente (raiz ou ocorrência
     * gerada), cancelar interrompe a geração das competências seguintes
     * daquela série: `manterJanelaDeCompetencias()` para de gerar assim que
     * encontra qualquer título cancelado na série.
     */
    public function cancelar(Payable $titulo): Payable
    {
        return DB::transaction(function () use ($titulo) {
            $titulo->installments()
                ->where('situacao', '!=', self::SITUACAO_PARCELA_PAGA)
                ->update(['situacao' => self::SITUACAO_PARCELA_CANCELADA]);

            $titulo->update(['situacao' => self::SITUACAO_TITULO_CANCELADO]);

            return $titulo->fresh(['installments']);
        });
    }

    // -----------------------------------------------------------------
    // Alterar valor de recorrente
    // -----------------------------------------------------------------

    /**
     * Altera o valor de um título, com o alcance escolhido por quem chama:
     * `ALCANCE_APENAS_ESTE` (só o título informado) ou
     * `ALCANCE_ESTE_E_FUTUROS` (o título informado e as ocorrências da mesma
     * série com competência igual ou posterior).
     *
     * Título com parcela já paga nunca muda de valor: o dinheiro que já
     * entrou no caixa não é reescrito por uma edição posterior. Cada título
     * desta série tem uma parcela só (a competência), então "não tocar no que
     * já foi pago" e "não tocar no título" são a mesma coisa aqui.
     *
     * @return array<int, Payable> Os títulos efetivamente alterados, na ordem
     *                             da competência. Título com a parcela já paga
     *                             é silenciosamente ignorado, não é erro.
     *
     * @throws ValidationException Novo valor inválido.
     */
    public function alterarValor(Payable $titulo, string $novoValor, string $alcance): array
    {
        $novoValorEmCentavos = $this->valorInformado(['valor' => $novoValor], 'valor', 'Informe o novo valor do título.');
        $novoValorDecimal = Dinheiro::paraDecimal($novoValorEmCentavos);

        if (! in_array($alcance, [self::ALCANCE_APENAS_ESTE, self::ALCANCE_ESTE_E_FUTUROS], true)) {
            throw new InvalidArgumentException(
                "Alcance inválido: \"{$alcance}\". Use PayableService::ALCANCE_APENAS_ESTE ou ALCANCE_ESTE_E_FUTUROS."
            );
        }

        return DB::transaction(function () use ($titulo, $novoValorDecimal, $alcance) {
            $titulos = $alcance === self::ALCANCE_ESTE_E_FUTUROS
                ? $this->tituloEFuturosDaSerie($titulo)
                : Payable::query()->whereKey($titulo->id)->lockForUpdate()->get();

            $atualizados = [];

            foreach ($titulos as $item) {
                $parcela = $item->installments()->orderBy('numero')->first();

                // Sem parcela (não deveria acontecer) ou já paga: nunca muda
                // de valor.
                if ($parcela === null || $parcela->situacao === self::SITUACAO_PARCELA_PAGA) {
                    continue;
                }

                $parcela->update(['valor' => $novoValorDecimal]);
                $item->update(['valor_total' => $novoValorDecimal]);

                $atualizados[] = $item->fresh(['installments']);
            }

            return $atualizados;
        });
    }

    // -----------------------------------------------------------------
    // Recorrência
    // -----------------------------------------------------------------

    /**
     * Garante que a série do título informado tem sempre
     * `self::JANELA_DE_COMPETENCIAS` competências correntes ou futuras
     * geradas, empurrando a janela para frente conforme o tempo passa.
     * Chamado por `criar()` na hora de criar um título recorrente, e pela
     * rotina `financeiro:gerar-pagamentos-recorrentes` todo mês.
     *
     * Idempotente: rodar de novo no mesmo mês não gera competência duplicada,
     * porque `gerarProximaCompetencia()` confere antes de criar.
     *
     * Para de gerar, sem erro, em dois casos: a série tem algum título
     * cancelado (ver `cancelar()`), ou a próxima competência ultrapassaria
     * `recorrencia_ate`.
     *
     * @return int Quantidade de competências novas geradas nesta chamada.
     */
    public function manterJanelaDeCompetencias(Payable $titulo): int
    {
        if ($titulo->recorrencia === self::RECORRENCIA_NENHUMA) {
            return 0;
        }

        return DB::transaction(function () use ($titulo) {
            $origemId = $this->origemId($titulo);

            // Trava a raiz: duas passadas concorrentes da rotina não podem
            // gerar a mesma competência duas vezes por lerem o "último gerado"
            // ao mesmo tempo, antes de qualquer uma das duas ter gravado nada.
            $origem = Payable::query()->whereKey($origemId)->lockForUpdate()->firstOrFail();

            if ($this->serieFoiCancelada($origemId)) {
                return 0;
            }

            $mesAtual = BusinessDate::hoje()->startOfMonth();
            $antes = $this->contarSerie($origemId);
            $tentativas = 0;

            while ($this->contarCompetenciasAPartirDe($origemId, $mesAtual) < self::JANELA_DE_COMPETENCIAS) {
                if (++$tentativas > self::TENTATIVAS_MAXIMAS_POR_JANELA) {
                    break;
                }

                if ($this->gerarProximaCompetencia($origem, $origemId) === null) {
                    break;
                }
            }

            return $this->contarSerie($origemId) - $antes;
        });
    }

    /**
     * Gera a próxima competência da série, a partir da última já existente.
     * Devolve `null`, sem gravar nada, quando a série já está cancelada ou
     * quando a próxima competência ultrapassaria `recorrencia_ate`.
     *
     * Idempotente: se a competência seguinte já existe na série (a mesma
     * rotina rodou antes, ou `criar()` já tinha gerado), devolve o título
     * existente em vez de duplicar.
     *
     * Copia o valor da última ocorrência (e não da raiz): é o que faz uma
     * mudança de valor "este e os futuros" (`alterarValor()`) propagar para as
     * competências que ainda serão geradas depois dela.
     */
    private function gerarProximaCompetencia(Payable $origem, int $origemId): ?Payable
    {
        $ultima = $this->ultimaOcorrencia($origemId);
        $meses = self::MESES_POR_RECORRENCIA[$origem->recorrencia] ?? null;

        if ($meses === null) {
            throw new InvalidArgumentException("Recorrência sem intervalo definido: \"{$origem->recorrencia}\".");
        }

        $proximaEmitidoEm = CarbonImmutable::parse($ultima->emitido_em)->addMonthsNoOverflow($meses);

        if ($origem->recorrencia_ate !== null
            && $proximaEmitidoEm->startOfDay()->gt(CarbonImmutable::parse($origem->recorrencia_ate)->startOfDay())
        ) {
            return null;
        }

        $existente = $this->competenciaExistente($origemId, $proximaEmitidoEm);

        if ($existente !== null) {
            return $existente;
        }

        $ultimaParcela = $ultima->installments()->orderByDesc('numero')->firstOrFail();
        $proximoVencimento = CarbonImmutable::parse($ultimaParcela->vencimento)->addMonthsNoOverflow($meses);

        $novo = Payable::create([
            'supplier_id' => $ultima->supplier_id,
            'work_order_id' => $ultima->work_order_id,
            'contract_id' => $ultima->contract_id,
            'chart_of_account_id' => $ultima->chart_of_account_id,
            'descricao' => $ultima->descricao,
            'valor_total' => $ultima->valor_total,
            'emitido_em' => $proximaEmitidoEm->toDateString(),
            'situacao' => self::SITUACAO_TITULO_ABERTO,
            'recorrencia' => $origem->recorrencia,
            'recorrencia_ate' => $origem->recorrencia_ate,
            'payable_origem_id' => $origemId,
        ]);

        PayableInstallment::create([
            'payable_id' => $novo->id,
            'numero' => 1,
            'valor' => $ultima->valor_total,
            'vencimento' => $proximoVencimento->toDateString(),
            'situacao' => self::SITUACAO_PARCELA_ABERTA,
        ]);

        return $novo;
    }

    /**
     * Id do título raiz da série: o próprio título quando ele já é a raiz
     * (`payable_origem_id` nulo), ou o valor de `payable_origem_id` quando é
     * uma ocorrência gerada.
     */
    private function origemId(Payable $titulo): int
    {
        return $titulo->payable_origem_id ?? $titulo->id;
    }

    /**
     * Toda a série (raiz e ocorrências geradas) tem algum título cancelado?
     * Regra fail-safe: um cancelamento em qualquer ponto da série já é
     * suficiente para parar a geração das competências seguintes.
     */
    private function serieFoiCancelada(int $origemId): bool
    {
        return $this->consultaDaSerie($origemId)
            ->where('situacao', self::SITUACAO_TITULO_CANCELADO)
            ->exists();
    }

    /**
     * Título mais recente da série (raiz ou ocorrência), pela competência
     * (`emitido_em`). É a partir dele que a próxima competência é calculada.
     */
    private function ultimaOcorrencia(int $origemId): Payable
    {
        return $this->consultaDaSerie($origemId)
            ->orderByDesc('emitido_em')
            ->orderByDesc('id')
            ->firstOrFail();
    }

    /**
     * Título já existente na série para a competência informada (mesmo ano e
     * mês de `emitido_em`), ou `null` quando ainda não foi gerado. É esta
     * consulta que torna `gerarProximaCompetencia()` idempotente.
     */
    private function competenciaExistente(int $origemId, CarbonImmutable $competencia): ?Payable
    {
        return $this->consultaDaSerie($origemId)
            ->whereYear('emitido_em', $competencia->year)
            ->whereMonth('emitido_em', $competencia->month)
            ->first();
    }

    /**
     * Quantidade de títulos da série com competência (`emitido_em`) igual ou
     * posterior ao mês informado. É a contagem que decide se a janela de 3
     * competências à frente já está satisfeita.
     */
    private function contarCompetenciasAPartirDe(int $origemId, CarbonImmutable $mes): int
    {
        return $this->consultaDaSerie($origemId)
            ->where('emitido_em', '>=', $mes->toDateString())
            ->count();
    }

    /**
     * Quantidade total de títulos da série, sem filtro de competência. Usado
     * só para calcular quantos títulos `manterJanelaDeCompetencias()` gerou
     * nesta chamada (diferença entre antes e depois).
     */
    private function contarSerie(int $origemId): int
    {
        return $this->consultaDaSerie($origemId)->count();
    }

    /**
     * Todos os títulos da série informada, incluindo a raiz. Ponto único
     * desta condição (`id = raiz OR payable_origem_id = raiz`) para as
     * consultas de recorrência nunca divergirem entre si.
     */
    private function consultaDaSerie(int $origemId): Builder
    {
        return Payable::query()
            ->where(fn ($consulta) => $consulta->where('id', $origemId)->orWhere('payable_origem_id', $origemId));
    }

    /**
     * O título informado e as ocorrências da mesma série com competência
     * igual ou posterior à dele, em ordem de competência. Usado pelo alcance
     * `ALCANCE_ESTE_E_FUTUROS` de `alterarValor()`.
     *
     * @return Collection<int, Payable>
     */
    private function tituloEFuturosDaSerie(Payable $titulo): Collection
    {
        $origemId = $this->origemId($titulo);

        return $this->consultaDaSerie($origemId)
            ->where('emitido_em', '>=', $titulo->emitido_em)
            ->orderBy('emitido_em')
            ->lockForUpdate()
            ->get();
    }

    // -----------------------------------------------------------------
    // Conferências
    // -----------------------------------------------------------------

    /**
     * Relê a parcela dentro da transação, com trava de linha. A consulta
     * passa pelo escopo global por empresa: parcela de outra empresa não é
     * encontrada e vira 404.
     */
    private function travar(PayableInstallment $parcela): PayableInstallment
    {
        return PayableInstallment::query()
            ->whereKey($parcela->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function garantirParcelaBaixavel(PayableInstallment $parcela, int $saldoEmCentavos): void
    {
        if ($parcela->situacao === self::SITUACAO_PARCELA_CANCELADA) {
            throw ValidationException::withMessages([
                'parcela' => 'Esta parcela está cancelada e não recebe baixa.',
            ]);
        }

        if ($saldoEmCentavos <= 0) {
            throw ValidationException::withMessages([
                'parcela' => 'Esta parcela já está quitada: não há saldo devedor para baixar.',
            ]);
        }
    }

    /**
     * Recusa a baixa acima do saldo devedor, com o saldo na mensagem. Mesmo
     * critério de `SettlementService::garantirValorDentroDoSaldo()`.
     */
    private function garantirValorDentroDoSaldo(int $valorEmCentavos, int $saldoEmCentavos): void
    {
        if ($valorEmCentavos <= $saldoEmCentavos) {
            return;
        }

        throw ValidationException::withMessages([
            'valor' => sprintf(
                'O valor de %s é maior que o saldo devedor desta parcela, que é de %s. '
                .'Baixe até esse valor; o que sobrar é baixa da próxima parcela.',
                Dinheiro::formatar($valorEmCentavos),
                Dinheiro::formatar($saldoEmCentavos),
            ),
        ]);
    }

    private function garantirPermissaoDeEstorno(User $usuario): void
    {
        if (! $usuario->can(self::PERMISSAO_ESTORNO)) {
            throw new AuthorizationException(
                'Você não tem permissão para estornar um pagamento já lançado no caixa.'
            );
        }
    }

    private function motivoValido(string $motivo): string
    {
        $motivo = trim($motivo);

        if (mb_strlen($motivo) < self::MOTIVO_MINIMO) {
            throw ValidationException::withMessages([
                'motivo' => sprintf(
                    'O motivo do estorno precisa ter pelo menos %d caracteres, para explicar de verdade '
                    .'por que o pagamento foi desfeito a quem for auditar depois.',
                    self::MOTIVO_MINIMO
                ),
            ]);
        }

        return $motivo;
    }

    // -----------------------------------------------------------------
    // Situação do título
    // -----------------------------------------------------------------

    /**
     * Recalcula a situação do título a partir das parcelas dele. Mesmo
     * critério de `SettlementService::atualizarSituacaoDoTitulo()`: título
     * cancelado nunca é reaberto por baixa nenhuma.
     */
    private function atualizarSituacaoDoTitulo(PayableInstallment $parcela): void
    {
        $titulo = $parcela->payable()->first();

        if (! $titulo instanceof Payable || $titulo->situacao === self::SITUACAO_TITULO_CANCELADO) {
            return;
        }

        $parcelas = $titulo->installments()
            ->get()
            ->reject(fn (PayableInstallment $item): bool => $item->situacao === self::SITUACAO_PARCELA_CANCELADA);

        if ($parcelas->isEmpty()) {
            return;
        }

        $situacao = match (true) {
            $parcelas->every(fn (PayableInstallment $item): bool => $item->situacao === self::SITUACAO_PARCELA_PAGA) => self::SITUACAO_TITULO_QUITADO,
            $parcelas->contains(fn (PayableInstallment $item): bool => Dinheiro::centavos($item->valor_pago) > 0) => self::SITUACAO_TITULO_PARCIAL,
            default => self::SITUACAO_TITULO_ABERTO,
        };

        if ($titulo->situacao !== $situacao) {
            $titulo->update(['situacao' => $situacao]);
        }
    }

    // -----------------------------------------------------------------
    // Leitura dos dados de entrada
    // -----------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $dados
     */
    private function usuarioInformado(array $dados): User
    {
        $usuario = $dados['usuario'] ?? null;

        if (! $usuario instanceof User) {
            throw ValidationException::withMessages([
                'usuario' => 'A baixa precisa saber quem está lançando: informe o usuário em "usuario".',
            ]);
        }

        return $usuario;
    }

    /**
     * @param  array<string, mixed>  $dados
     */
    private function fornecedorInformado(array $dados): int
    {
        $id = $dados['supplier_id'] ?? null;

        if ($id === null || $id === '') {
            throw ValidationException::withMessages([
                'supplier_id' => 'Informe o fornecedor do título.',
            ]);
        }

        $fornecedor = Supplier::query()->find($id);

        if ($fornecedor === null) {
            throw ValidationException::withMessages([
                'supplier_id' => 'Fornecedor não encontrado.',
            ]);
        }

        return (int) $fornecedor->id;
    }

    /**
     * Valor em centavos, maior que zero.
     *
     * @param  array<string, mixed>  $dados
     */
    private function valorInformado(array $dados, string $campo, string $mensagemAusente): int
    {
        $valor = $dados[$campo] ?? null;

        if ($valor === null || $valor === '' || ! is_numeric($valor)) {
            throw ValidationException::withMessages([
                $campo => $mensagemAusente,
            ]);
        }

        $centavos = Dinheiro::centavos($valor);

        if ($centavos <= 0) {
            throw ValidationException::withMessages([
                $campo => 'O valor precisa ser maior que zero.',
            ]);
        }

        return $centavos;
    }

    /**
     * @param  array<string, mixed>  $dados
     */
    private function dataObrigatoria(array $dados, string $campo, string $mensagemAusente): string
    {
        $data = $dados[$campo] ?? null;

        if ($data === null || $data === '') {
            throw ValidationException::withMessages([
                $campo => $mensagemAusente,
            ]);
        }

        $dia = BusinessDate::diaDe($data);

        if ($dia === null) {
            throw ValidationException::withMessages([
                $campo => 'Data inválida.',
            ]);
        }

        return $dia;
    }

    /**
     * @param  array<string, mixed>  $dados
     */
    private function dataOpcional(array $dados, string $campo): ?string
    {
        $data = $dados[$campo] ?? null;

        if ($data === null || $data === '') {
            return null;
        }

        $dia = BusinessDate::diaDe($data);

        if ($dia === null) {
            throw ValidationException::withMessages([
                $campo => 'Data inválida.',
            ]);
        }

        return $dia;
    }

    /**
     * Dia do campo informado, ou hoje no fuso do negócio quando ausente.
     *
     * @param  array<string, mixed>  $dados
     */
    private function dataOpcionalOuHoje(array $dados, string $campo): string
    {
        return $this->dataOpcional($dados, $campo) ?? BusinessDate::hoje()->toDateString();
    }

    /**
     * Dia do recebimento/pagamento (`Y-m-d`), no fuso do negócio. Obrigatório
     * de propósito, sem cair para "hoje": mesmo critério de
     * `SettlementService::diaInformado()`.
     *
     * @param  array<string, mixed>  $dados
     */
    private function diaInformado(array $dados): string
    {
        return $this->dataObrigatoria($dados, 'data', 'Informe a data do pagamento, que é o dia em que o dinheiro saiu.');
    }

    /**
     * @param  array<string, mixed>  $dados
     */
    private function textoObrigatorio(array $dados, string $campo, string $mensagemAusente): string
    {
        $valor = $dados[$campo] ?? null;

        if (! is_string($valor) || trim($valor) === '') {
            throw ValidationException::withMessages([
                $campo => $mensagemAusente,
            ]);
        }

        return trim($valor);
    }

    /**
     * @param  array<string, mixed>  $dados
     */
    private function textoOpcional(array $dados, string $campo): ?string
    {
        $valor = $dados[$campo] ?? null;

        if (! is_string($valor)) {
            return null;
        }

        $valor = trim($valor);

        return $valor === '' ? null : $valor;
    }

    /**
     * Recorrência informada, validada contra `Payable::RECORRENCIAS`. Padrão
     * `nenhuma` quando a chave não vem.
     *
     * @param  array<string, mixed>  $dados
     */
    private function recorrenciaInformada(array $dados): string
    {
        $recorrencia = $dados['recorrencia'] ?? self::RECORRENCIA_NENHUMA;

        if (! in_array($recorrencia, Payable::RECORRENCIAS, true)) {
            throw ValidationException::withMessages([
                'recorrencia' => sprintf(
                    'Recorrência inválida: "%s". Use uma destas: %s.',
                    (string) $recorrencia,
                    implode(', ', Payable::RECORRENCIAS)
                ),
            ]);
        }

        return $recorrencia;
    }
}
