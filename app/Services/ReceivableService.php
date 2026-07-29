<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Receivable;
use App\Models\ReceivableInstallment;
use App\Models\WorkOrder;
use App\Support\BusinessDate;
use App\Support\Dinheiro;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Geração e cancelamento de título a receber (Plano 18, Task 18.3).
 *
 * Duas origens, os dois métodos públicos principais:
 *
 * - `gerarDaOs()`: título único de uma ordem de serviço, com a condição de
 *   pagamento escolhida na hora do fechamento (à vista ou parcelado).
 *   Idempotente por `work_order_id`: a OS que já tem título não gera outro,
 *   nem quando chamado de novo com condições diferentes.
 * - `gerarDoContrato()`: título da competência (mês) de um contrato
 *   periódico. Idempotente por `[contract_id, competência]`, decisivo para a
 *   rotina mensal poder rodar de novo depois de uma falha sem duplicar
 *   cobrança do cliente final.
 *
 * `receivables` não tem coluna própria de competência (a Task 18.1 não previu
 * uma): a competência de um título de contrato vive em `emitido_em`, sempre o
 * primeiro dia do mês da competência, e a idempotência é conferida por ano e
 * mês desse campo. `emitido_em` é `date`, comparado sem conversão de fuso, e a
 * consulta usa `whereYear`/`whereMonth`, não uma faixa de datas.
 *
 * Arredondamento das parcelas, o ponto mais sensível deste service: a divisão
 * do valor do título é feita inteiramente em centavos (inteiro), nunca em
 * ponto flutuante, e a sobra de centavos (quando o valor não divide exato,
 * como 1000,00 em 3 parcelas) vai inteira para a última parcela. É o que
 * garante a soma das parcelas sempre igual ao valor do título, centavo a
 * centavo; arredondar cada parcela isoladamente (`round(valor/parcelas, 2)`
 * repetido) é o defeito clássico que só aparece na conciliação meses depois.
 */
class ReceivableService
{
    /**
     * Condição de pagamento "à vista": força uma parcela única, mesmo que
     * `condicoes['parcelas']` venha preenchido com outro número.
     */
    public const FORMA_A_VISTA = 'a_vista';

    /**
     * Condição de pagamento parcelada.
     */
    public const FORMA_PARCELADO = 'parcelado';

    private const SITUACAO_TITULO_ABERTO = 'aberto';

    private const SITUACAO_TITULO_CANCELADO = 'cancelado';

    private const SITUACAO_PARCELA_ABERTA = 'aberta';

    private const SITUACAO_PARCELA_PAGA = 'paga';

    private const SITUACAO_PARCELA_CANCELADA = 'cancelada';

    /**
     * Cria o título a receber e as parcelas de uma ordem de serviço, conforme
     * a condição de pagamento escolhida no fechamento.
     *
     * Idempotente por `work_order_id`: OS que já tem título devolve o título
     * existente, sem criar parcela nenhuma nova. Chamar de novo com condições
     * diferentes não altera o título já gerado; para isso existe o
     * cancelamento (`cancelar()`), nunca uma segunda geração por cima.
     *
     * Formato esperado de `$condicoes`:
     * - `forma` (opcional): `self::FORMA_A_VISTA` força uma parcela única,
     *   ignorando `parcelas`.
     * - `parcelas` (opcional, padrão 1): quantidade de parcelas.
     * - `primeiro_vencimento` (opcional): data (`Y-m-d`) da primeira parcela.
     *   Sem esta chave, cai para `payment_due_date` da própria OS; sem os
     *   dois, lança exceção.
     * - `intervalo_dias` xor `intervalo_meses`: obrigatório quando
     *   `parcelas` > 1, decide o espaçamento entre uma parcela e a próxima.
     * - `valor` (opcional): sobrescreve o valor do título. Sem esta chave, o
     *   valor vem de `final_amount`, com `total_cost` como reserva.
     * - `descricao` (opcional): sobrescreve a descrição padrão do título.
     * - `chart_of_account_id` (opcional): categoria do plano de contas.
     *
     * @param  array<string, mixed>  $condicoes
     */
    public function gerarDaOs(WorkOrder $os, array $condicoes): Receivable
    {
        $existente = Receivable::query()->where('work_order_id', $os->id)->first();

        if ($existente !== null) {
            return $existente;
        }

        $valorTotal = $this->valorDaOs($os, $condicoes);
        $numeroDeParcelas = $this->numeroDeParcelas($condicoes);
        $primeiroVencimento = $this->primeiroVencimentoDaOs($os, $condicoes);

        return DB::transaction(function () use ($os, $condicoes, $valorTotal, $numeroDeParcelas, $primeiroVencimento) {
            $titulo = Receivable::create([
                'client_id' => $os->client_id,
                'work_order_id' => $os->id,
                'contract_id' => $os->contract_id,
                'chart_of_account_id' => $condicoes['chart_of_account_id'] ?? null,
                'descricao' => $condicoes['descricao'] ?? "Ordem de serviço {$os->order_number}",
                'valor_total' => $valorTotal,
                'emitido_em' => BusinessDate::hoje()->toDateString(),
                'situacao' => self::SITUACAO_TITULO_ABERTO,
            ]);

            $this->criarParcelas($titulo, $valorTotal, $numeroDeParcelas, $primeiroVencimento, $condicoes);

            return $titulo;
        });
    }

    /**
     * Cria o título a receber da competência de um contrato periódico.
     *
     * Idempotente pela chave `[contract_id, competência]`: rodar de novo para
     * a mesma competência devolve o título já existente, sem criar outro. É a
     * garantia que permite a rotina mensal (`financeiro:gerar-titulos-de-contrato`)
     * rodar de novo depois de uma falha sem gerar cobrança duplicada para o
     * cliente final.
     *
     * O vencimento da parcela única é o dia do mês em que o contrato começou
     * (`start_date`), preso ao último dia do mês quando o mês da competência
     * for mais curto (ex.: contrato iniciado dia 31, competência de fevereiro
     * vence dia 28). Contrato sem `start_date` vence no dia 1.
     *
     * @param  string  $competencia  Referência no formato `YYYY-MM`.
     */
    public function gerarDoContrato(Contract $contrato, string $competencia): Receivable
    {
        [$ano, $mes] = $this->partesDaCompetencia($competencia);

        $existente = Receivable::query()
            ->where('contract_id', $contrato->id)
            ->whereYear('emitido_em', $ano)
            ->whereMonth('emitido_em', $mes)
            ->first();

        if ($existente !== null) {
            return $existente;
        }

        $contrato->loadMissing('address');
        $clienteId = $contrato->address?->client_id;

        if ($clienteId === null) {
            throw new InvalidArgumentException(
                "Contrato #{$contrato->id} sem endereço/cliente resolvido: não é possível gerar o título da competência {$competencia}."
            );
        }

        $valorTotal = (string) $contrato->service_value;
        $vencimento = $this->vencimentoDoContrato($contrato, $ano, $mes);
        $emitidoEm = sprintf('%04d-%02d-01', $ano, $mes);

        return DB::transaction(function () use ($contrato, $competencia, $clienteId, $valorTotal, $vencimento, $emitidoEm) {
            $titulo = Receivable::create([
                'client_id' => $clienteId,
                'contract_id' => $contrato->id,
                'descricao' => "Mensalidade do contrato {$contrato->contract_number} - competência {$competencia}",
                'valor_total' => $valorTotal,
                'emitido_em' => $emitidoEm,
                'situacao' => self::SITUACAO_TITULO_ABERTO,
            ]);

            ReceivableInstallment::create([
                'receivable_id' => $titulo->id,
                'numero' => 1,
                'valor' => $valorTotal,
                'vencimento' => $vencimento,
                'situacao' => self::SITUACAO_PARCELA_ABERTA,
            ]);

            return $titulo;
        });
    }

    /**
     * Cria um título a receber avulso, informado a mão pela tela de contas a
     * receber (Plano 18, Task 18.7).
     *
     * Terceira porta de entrada do título, ao lado de `gerarDaOs()` e
     * `gerarDoContrato()`, e a única sem origem automática: é por ela que
     * entra a cobrança que não nasce de uma OS nem de um contrato (uma venda
     * avulsa, um acordo de dívida antiga, um reembolso a cobrar do cliente).
     *
     * Divide o valor em parcelas pela **mesma** aritmética em centavos de
     * `gerarDaOs()` (ver `valoresDasParcelas()`): reimplementar a divisão aqui
     * é como as duas portas passariam a fechar com um centavo de diferença.
     *
     * Sem idempotência de propósito, ao contrário das outras duas: não existe
     * chave natural em um título avulso (dois lançamentos iguais no mesmo dia
     * para o mesmo cliente são cobranças diferentes de verdade), e recusar o
     * segundo esconderia uma cobrança real. Quem duplica por engano cancela
     * (`cancelar()`), que é a operação que deixa rastro.
     *
     * Formato esperado de `$dados`:
     * - `client_id` (obrigatório): cliente cobrado. Vem já escopado pela
     *   empresa corrente por quem chama; este Service não consulta `Auth`.
     * - `descricao` (obrigatório).
     * - `valor` (obrigatório): valor total do título, maior que zero.
     * - `primeiro_vencimento` (obrigatório): data (`Y-m-d`) da primeira parcela.
     * - `parcelas` (opcional, padrão 1) e `forma` (opcional): mesma semântica
     *   de `gerarDaOs()`.
     * - `intervalo_dias` xor `intervalo_meses`: obrigatório quando `parcelas` > 1.
     * - `emitido_em` (opcional): dia de emissão. Sem esta chave, hoje no fuso
     *   do negócio.
     * - `chart_of_account_id`, `work_order_id`, `contract_id` (opcionais).
     *
     * @param  array<string, mixed>  $dados
     *
     * @throws InvalidArgumentException Dado obrigatório ausente ou inválido.
     */
    public function criar(array $dados): Receivable
    {
        $clienteId = $dados['client_id'] ?? null;

        if ($clienteId === null || $clienteId === '') {
            throw new InvalidArgumentException('Título a receber precisa de um cliente para cobrar.');
        }

        $descricao = trim((string) ($dados['descricao'] ?? ''));

        if ($descricao === '') {
            throw new InvalidArgumentException('Informe a descrição do título a receber.');
        }

        $valorTotal = $this->valorObrigatorio($dados);
        $numeroDeParcelas = $this->numeroDeParcelas($dados);
        $primeiroVencimento = $dados['primeiro_vencimento'] ?? null;

        if (! is_string($primeiroVencimento) || $primeiroVencimento === '') {
            throw new InvalidArgumentException('Informe o vencimento da primeira parcela.');
        }

        $emitidoEm = BusinessDate::diaDe($dados['emitido_em'] ?? null) ?? BusinessDate::hoje()->toDateString();

        return DB::transaction(function () use ($dados, $clienteId, $descricao, $valorTotal, $numeroDeParcelas, $primeiroVencimento, $emitidoEm) {
            $titulo = Receivable::create([
                'client_id' => $clienteId,
                'work_order_id' => $dados['work_order_id'] ?? null,
                'contract_id' => $dados['contract_id'] ?? null,
                'chart_of_account_id' => $dados['chart_of_account_id'] ?? null,
                'descricao' => $descricao,
                'valor_total' => $valorTotal,
                'emitido_em' => $emitidoEm,
                'situacao' => self::SITUACAO_TITULO_ABERTO,
            ]);

            $this->criarParcelas($titulo, $valorTotal, $numeroDeParcelas, $primeiroVencimento, $dados);

            return $titulo->fresh(['installments']);
        });
    }

    /**
     * Cancela o título: parcela paga é mantida como está (o dinheiro entrou e
     * o registro precisa continuar), e toda parcela que não está paga
     * (aberta, parcial, vencida ou já cancelada) vira/permanece cancelada.
     *
     * Não mexe em `valor_pago` nem em `pago_em` de nenhuma parcela: o
     * cancelamento muda a situação, nunca apaga o que já foi recebido.
     */
    public function cancelar(Receivable $titulo): Receivable
    {
        return DB::transaction(function () use ($titulo) {
            $titulo->installments()
                ->where('situacao', '!=', self::SITUACAO_PARCELA_PAGA)
                ->update(['situacao' => self::SITUACAO_PARCELA_CANCELADA]);

            $titulo->update(['situacao' => self::SITUACAO_TITULO_CANCELADO]);

            return $titulo->fresh(['installments']);
        });
    }

    /**
     * Valor do título: `condicoes['valor']` manda quando informado; sem ele,
     * `final_amount` da OS, com `total_cost` como reserva.
     *
     * @param  array<string, mixed>  $condicoes
     */
    private function valorDaOs(WorkOrder $os, array $condicoes): string
    {
        if (array_key_exists('valor', $condicoes) && $condicoes['valor'] !== null && $condicoes['valor'] !== '') {
            return (string) $condicoes['valor'];
        }

        $valor = $os->final_amount ?? $os->total_cost;

        if ($valor === null) {
            throw new InvalidArgumentException(
                "Ordem de serviço #{$os->id} sem valor definido (final_amount/total_cost) para gerar título a receber."
            );
        }

        return (string) $valor;
    }

    /**
     * Valor total informado para um título avulso (`criar()`), como texto
     * decimal. Diferente de `valorDaOs()`, que tem a OS como reserva: aqui não
     * existe origem de onde tirar o valor, então ele é obrigatório.
     *
     * @param  array<string, mixed>  $dados
     */
    private function valorObrigatorio(array $dados): string
    {
        $valor = $dados['valor'] ?? null;

        if ($valor === null || $valor === '' || ! is_numeric($valor)) {
            throw new InvalidArgumentException('Informe o valor do título a receber.');
        }

        if (Dinheiro::centavos($valor) <= 0) {
            throw new InvalidArgumentException('O valor do título a receber precisa ser maior que zero.');
        }

        return (string) $valor;
    }

    /**
     * Quantidade de parcelas a gerar. `forma = a_vista` sempre força 1,
     * mesmo que `parcelas` tenha vindo preenchido com outro número: a forma
     * de pagamento é quem manda sobre a quantidade, não o contrário.
     *
     * @param  array<string, mixed>  $condicoes
     */
    private function numeroDeParcelas(array $condicoes): int
    {
        if (($condicoes['forma'] ?? null) === self::FORMA_A_VISTA) {
            return 1;
        }

        $parcelas = (int) ($condicoes['parcelas'] ?? 1);

        if ($parcelas < 1) {
            throw new InvalidArgumentException('O número de parcelas precisa ser um inteiro maior ou igual a 1.');
        }

        return $parcelas;
    }

    /**
     * Data (`Y-m-d`) da primeira parcela: `condicoes['primeiro_vencimento']`
     * manda quando informado; sem ele, cai para `payment_due_date` da OS.
     *
     * @param  array<string, mixed>  $condicoes
     */
    private function primeiroVencimentoDaOs(WorkOrder $os, array $condicoes): string
    {
        $informado = $condicoes['primeiro_vencimento'] ?? null;

        if (is_string($informado) && $informado !== '') {
            return $informado;
        }

        $daOs = $os->payment_due_date?->toDateString();

        if ($daOs !== null) {
            return $daOs;
        }

        throw new InvalidArgumentException(
            "Ordem de serviço #{$os->id} sem data de vencimento: informe 'primeiro_vencimento' nas condições ou preencha payment_due_date."
        );
    }

    /**
     * Monta e grava as parcelas do título, com o vencimento de cada uma e o
     * valor já dividido em centavos (ver `valoresDasParcelas()`).
     *
     * @param  array<string, mixed>  $condicoes
     */
    private function criarParcelas(
        Receivable $titulo,
        string $valorTotal,
        int $numeroDeParcelas,
        string $primeiroVencimento,
        array $condicoes,
    ): void {
        $vencimentos = $this->vencimentosDasParcelas($primeiroVencimento, $numeroDeParcelas, $condicoes);
        $valores = $this->valoresDasParcelas($valorTotal, $numeroDeParcelas);

        foreach ($valores as $indice => $valor) {
            ReceivableInstallment::create([
                'receivable_id' => $titulo->id,
                'numero' => $indice + 1,
                'valor' => $valor,
                'vencimento' => $vencimentos[$indice],
                'situacao' => self::SITUACAO_PARCELA_ABERTA,
            ]);
        }
    }

    /**
     * Vencimento de cada parcela: a primeira na data escolhida, as demais
     * somando o intervalo em dias ou em meses, sempre a partir da parcela
     * anterior.
     *
     * Vencimento em fim de semana não é movido de propósito: banco recebe
     * boleto em qualquer dia da semana, e adiantar ou atrasar a data
     * confundiria a conferência do título com o extrato bancário. Por isso
     * este método nunca ajusta o resultado de `addDays()`/`addMonthsNoOverflow()`
     * para o próximo dia útil.
     *
     * @param  array<string, mixed>  $condicoes
     * @return array<int, string>
     */
    private function vencimentosDasParcelas(string $primeiroVencimento, int $numeroDeParcelas, array $condicoes): array
    {
        $primeira = BusinessDate::paraFusoNegocio($primeiroVencimento);

        if ($primeira === null) {
            throw new InvalidArgumentException("Data de primeiro vencimento inválida: \"{$primeiroVencimento}\".");
        }

        $vencimentos = [$primeira->toDateString()];

        if ($numeroDeParcelas === 1) {
            return $vencimentos;
        }

        $intervaloDias = $condicoes['intervalo_dias'] ?? null;
        $intervaloMeses = $condicoes['intervalo_meses'] ?? null;

        if ($intervaloDias === null && $intervaloMeses === null) {
            throw new InvalidArgumentException(
                'Parcelamento com mais de uma parcela exige "intervalo_dias" ou "intervalo_meses" nas condições.'
            );
        }

        $atual = $primeira;

        for ($i = 1; $i < $numeroDeParcelas; $i++) {
            $atual = $intervaloMeses !== null
                ? $atual->addMonthsNoOverflow((int) $intervaloMeses)
                : $atual->addDays((int) $intervaloDias);

            $vencimentos[] = $atual->toDateString();
        }

        return $vencimentos;
    }

    /**
     * Divide o valor do título em `$numeroDeParcelas`, em centavos (inteiro),
     * e devolve o valor de cada parcela já formatado como decimal (`"333.33"`).
     *
     * A divisão nunca passa por ponto flutuante: o valor total vira centavos
     * como número inteiro, a divisão inteira dá o valor-base de cada parcela,
     * e a sobra da divisão (sempre menor que o número de parcelas) vai
     * inteira para a última. É essa sobra que faz 1000,00 em 3 parcelas virar
     * 333,33 + 333,33 + 333,34, com soma exata de volta a 1000,00 - o mesmo
     * cálculo repetido parcela a parcela (`round(1000/3, 2)` três vezes)
     * fecharia em 999,99 ou 1000,01, a depender do arredondamento.
     *
     * @return array<int, string>
     */
    private function valoresDasParcelas(string $valorTotal, int $numeroDeParcelas): array
    {
        $totalEmCentavos = $this->centavosDe($valorTotal);
        $parcelaBase = intdiv($totalEmCentavos, $numeroDeParcelas);
        $sobra = $totalEmCentavos - ($parcelaBase * $numeroDeParcelas);

        $centavosPorParcela = array_fill(0, $numeroDeParcelas, $parcelaBase);
        $centavosPorParcela[$numeroDeParcelas - 1] += $sobra;

        return array_map(
            fn (int $centavos): string => $this->valorApartirDeCentavos($centavos),
            $centavosPorParcela
        );
    }

    /**
     * Converte um valor monetário (decimal com até duas casas) para centavos,
     * como número inteiro, sem passar por multiplicação em ponto flutuante.
     *
     * A conversão vive em `App\Support\Dinheiro` desde a Task 18.4, para que a
     * divisão do título em parcelas (aqui) e a soma das baixas
     * (`SettlementService`) façam a mesma conta. Duas implementações da mesma
     * aritmética é como uma delas passa a divergir da outra em um centavo.
     */
    private function centavosDe(string $valor): int
    {
        return Dinheiro::centavos($valor);
    }

    /**
     * Converte centavos (inteiro) de volta para o texto decimal gravado em
     * `valor`, sem nenhuma divisão em ponto flutuante.
     */
    private function valorApartirDeCentavos(int $centavos): string
    {
        return Dinheiro::paraDecimal($centavos);
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

    /**
     * Vencimento da parcela única do título de contrato: o dia do mês em que
     * o contrato começou (`start_date`), preso ao último dia do mês da
     * competência quando ele for mais curto. Contrato sem `start_date` (o
     * campo é opcional no schema) vence no dia 1.
     */
    private function vencimentoDoContrato(Contract $contrato, int $ano, int $mes): string
    {
        $diaBase = $contrato->start_date?->day ?? 1;
        $ultimoDiaDoMes = CarbonImmutable::create($ano, $mes, 1)->endOfMonth()->day;

        return CarbonImmutable::create($ano, $mes, min($diaBase, $ultimoDiaDoMes))->toDateString();
    }
}
