<?php

namespace App\Services;

use App\Models\Receivable;
use App\Models\ReceivableInstallment;
use App\Models\User;
use App\Services\Financial\IntegracaoComCaixa;
use App\Support\BusinessDate;
use App\Support\Dinheiro;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Baixa e estorno de parcela a receber (Plano 18, Task 18.4).
 *
 * É o único caminho que transforma "o cliente pagou" em dinheiro no caixa. O
 * lançamento em si é criado por `IntegracaoComCaixa`, o ponto único de
 * integração; aqui ficam as regras de quanto pode ser baixado, quando a
 * parcela vira `paga` e o que acontece com o título.
 *
 * Três decisões concentram o risco desta classe
 * ---------------------------------------------
 * 1. **Uma transação só.** A soma em `valor_pago`, a mudança de situação, o
 *    vínculo com o lançamento e a criação do lançamento no caixa acontecem
 *    dentro da mesma transação. Se qualquer uma falhar, nenhuma fica gravada:
 *    parcela quitada sem dinheiro no caixa, ou dinheiro no caixa sem parcela
 *    quitada, são os dois estados que ninguém consegue conciliar depois.
 *    A parcela é relida com `lockForUpdate()` dentro da transação, para duas
 *    baixas simultâneas não passarem as duas pela conferência do saldo e
 *    receberem duas vezes o valor que só cabia uma.
 *
 * 2. **Dinheiro em centavos.** Toda soma e toda comparação de valor acontece
 *    em centavos (inteiro), pela mesma `App\Support\Dinheiro` que
 *    `ReceivableService` usa para dividir o título em parcelas. Comparar
 *    `float` seria o caminho para uma parcela de 333,33 + 333,33 + 333,34
 *    ficar eternamente "faltando um centavo" ou aceitar um centavo a mais.
 *
 * 3. **Estorno é lançamento novo, nunca exclusão.** O lançamento original do
 *    recebimento continua exatamente onde está, e o estorno entra como saída
 *    no dia em que foi feito. O caixa de um dia já fechado não muda depois de
 *    fechado, e a auditoria enxerga que houve recebimento e que houve estorno,
 *    em vez de um dia que simplesmente ficou diferente do que era.
 *
 * Data
 * ----
 * A data do recebimento é sempre a informada por quem lança, nunca "hoje": o
 * depósito de sexta é lançado na terça, e a competência no caixa precisa ser a
 * sexta. Já o estorno usa o dia de hoje no fuso do negócio, pelo motivo do
 * item 3 acima.
 */
class SettlementService
{
    /**
     * Permissão exigida para estornar um recebimento já lançado.
     *
     * O arquivo da Task 18.4 pedia "financeiro.estornar", mas o catálogo de
     * permissões deste projeto usa "recurso-acao" com hífen (a família "os.*"
     * das Tasks 13.3/13.4 é a única exceção, com motivo documentado em
     * `SyncPermissions`), então vale o nome abaixo. Mesma correção já aplicada
     * às Tasks 14.6, 16.4 e 17.7. Por começar com "financeiro-", a permissão
     * entra automaticamente no papel `financeiro` pelo filtro por prefixo do
     * `RolesAndPermissionsSeeder`, e no `administrador`, que recebe o catálogo
     * inteiro.
     */
    public const PERMISSAO_ESTORNO = 'financeiro-estornar';

    /**
     * Tamanho mínimo do motivo do estorno. Estorno é a única operação que
     * desfaz dinheiro já lançado: "erro" não explica nada a quem for auditar
     * seis meses depois.
     */
    private const MOTIVO_MINIMO = 10;

    private const SITUACAO_PARCELA_ABERTA = 'aberta';

    private const SITUACAO_PARCELA_PARCIAL = 'parcial';

    private const SITUACAO_PARCELA_PAGA = 'paga';

    private const SITUACAO_PARCELA_VENCIDA = 'vencida';

    private const SITUACAO_PARCELA_CANCELADA = 'cancelada';

    private const SITUACAO_TITULO_ABERTO = 'aberto';

    private const SITUACAO_TITULO_PARCIAL = 'parcial';

    private const SITUACAO_TITULO_QUITADO = 'quitado';

    private const SITUACAO_TITULO_CANCELADO = 'cancelado';

    public function __construct(
        private readonly IntegracaoComCaixa $caixa,
        private readonly ServiceInvoiceService $notasFiscais,
    ) {}

    /**
     * Registra o recebimento de uma parcela, total ou parcial.
     *
     * Baixa parcial mantém a parcela aberta pela diferença, com situação
     * `parcial`; várias baixas parciais somam em `valor_pago`, e a que
     * completa o valor da parcela marca `paga`. `pago_em` só é preenchido
     * quando a parcela é quitada: é a data em que ela terminou de ser paga,
     * não a data do último pedaço recebido.
     *
     * Formato esperado de `$dados`:
     * - `valor` (obrigatório): valor recebido agora, maior que zero.
     * - `data` (obrigatório): dia do recebimento (`Y-m-d`), o dia em que o
     *   dinheiro entrou, nunca a data de hoje por padrão.
     * - `usuario` (obrigatório): `User` que está lançando. O Service não lê
     *   `Auth::user()` por conta própria, e `financial_entries.created_by` é
     *   obrigatório.
     * - `forma_pagamento` (opcional): pix, dinheiro, boleto, etc.
     * - `observacao` (opcional): texto livre, gravado no lançamento de caixa.
     *
     * @param  array<string, mixed>  $dados
     *
     * @throws ValidationException Valor acima do saldo devedor, parcela já
     *                             quitada ou cancelada, dados faltando.
     */
    public function baixar(ReceivableInstallment $parcela, array $dados): ReceivableInstallment
    {
        $usuario = $this->usuarioInformado($dados);
        $valorEmCentavos = $this->valorInformado($dados);
        $dia = $this->diaInformado($dados);
        $formaPagamento = $this->textoOpcional($dados, 'forma_pagamento');
        $observacao = $this->textoOpcional($dados, 'observacao');

        $baixada = DB::transaction(function () use ($parcela, $usuario, $valorEmCentavos, $dia, $formaPagamento, $observacao) {
            $parcela = $this->travar($parcela);

            $saldoEmCentavos = $this->saldoDevedorEmCentavos($parcela);

            $this->garantirParcelaBaixavel($parcela, $saldoEmCentavos);
            $this->garantirValorDentroDoSaldo($valorEmCentavos, $saldoEmCentavos);

            $pagoEmCentavos = Dinheiro::centavos($parcela->valor_pago) + $valorEmCentavos;
            $quitada = $pagoEmCentavos >= Dinheiro::centavos($parcela->valor);

            $lancamento = $this->caixa->registrarRecebimento(
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

        $this->emitirNotaFiscalDoTituloQuitado($baixada);

        return $baixada;
    }

    /**
     * Estorna o recebimento de uma parcela, criando o lançamento de reversão
     * no caixa. O lançamento original nunca é apagado nem cancelado.
     *
     * Reverte tudo o que foi recebido na parcela (`valor_pago` inteiro),
     * inclusive quando o valor veio de várias baixas parciais: estornar
     * "a última baixa" deixaria a parcela em um estado que ninguém consegue
     * explicar olhando o extrato. A parcela volta para `aberta`, ou para
     * `vencida` quando o vencimento já passou, comparando por dia no fuso do
     * negócio.
     *
     * `financial_entry_id` passa a apontar para o lançamento de estorno: a
     * coluna guarda o último movimento de caixa da parcela, e uma parcela sem
     * recebimento nenhum apontando para uma entrada de caixa seria leitura
     * errada. O lançamento original continua alcançável pelo
     * `reference_number` e pela auditoria.
     *
     * O dia do estorno é hoje, no fuso do negócio, e não o dia do recebimento
     * original: o caixa de um dia já fechado não muda depois.
     *
     * @throws AuthorizationException Usuário sem a permissão `financeiro-estornar` (403).
     * @throws ValidationException Motivo ausente ou curto demais, parcela sem
     *                             recebimento para estornar.
     */
    public function estornar(ReceivableInstallment $parcela, string $motivo, User $usuario): ReceivableInstallment
    {
        $this->garantirPermissaoDeEstorno($usuario);

        $motivo = $this->motivoValido($motivo);
        $dia = BusinessDate::hoje()->toDateString();

        return DB::transaction(function () use ($parcela, $motivo, $usuario, $dia) {
            $parcela = $this->travar($parcela);

            $recebidoEmCentavos = Dinheiro::centavos($parcela->valor_pago);

            if ($recebidoEmCentavos <= 0) {
                throw ValidationException::withMessages([
                    'parcela' => 'Esta parcela não tem recebimento lançado, então não há o que estornar.',
                ]);
            }

            $lancamento = $this->caixa->estornarRecebimento(
                parcela: $parcela,
                valor: Dinheiro::paraDecimal($recebidoEmCentavos),
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
     * Quanto ainda falta receber na parcela, em centavos. Nunca negativo: a
     * baixa acima do saldo é recusada antes de gravar, então `valor_pago`
     * maior que `valor` só existiria em dado migrado.
     */
    public function saldoDevedorEmCentavos(ReceivableInstallment $parcela): int
    {
        $saldo = Dinheiro::centavos($parcela->valor) - Dinheiro::centavos($parcela->valor_pago);

        return max($saldo, 0);
    }

    // -----------------------------------------------------------------
    // Conferências
    // -----------------------------------------------------------------

    /**
     * Relê a parcela dentro da transação, com trava de linha.
     *
     * A consulta passa pelo escopo global por empresa: parcela de outra
     * empresa não é encontrada e vira 404, em vez de ser baixada por quem não
     * é dono dela.
     */
    private function travar(ReceivableInstallment $parcela): ReceivableInstallment
    {
        return ReceivableInstallment::query()
            ->whereKey($parcela->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function garantirParcelaBaixavel(ReceivableInstallment $parcela, int $saldoEmCentavos): void
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
     * Recusa a baixa acima do saldo devedor, com o saldo no texto.
     *
     * Aceitar o valor a maior geraria crédito invisível: dinheiro que entrou
     * no caixa sem título nenhum explicando de onde veio. Recebimento a maior
     * é baixa da parcela seguinte, decisão que o usuário toma na tela, e por
     * isso a mensagem precisa dizer o número exato que cabia aqui.
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
                'Você não tem permissão para estornar um recebimento já lançado no caixa.'
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
                    .'por que o recebimento foi desfeito a quem for auditar depois.',
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
     * Recalcula a situação do título a partir das parcelas dele.
     *
     * Roda dentro da mesma transação da baixa e do estorno: título dizendo
     * "aberto" com todas as parcelas pagas é a divergência que aparece na
     * primeira listagem de inadimplência.
     *
     * Título cancelado não é reaberto por baixa nenhuma: cancelar é decisão
     * de `ReceivableService::cancelar()`, e desfazer isso aqui em silêncio
     * apagaria essa decisão.
     */
    private function atualizarSituacaoDoTitulo(ReceivableInstallment $parcela): void
    {
        $titulo = $parcela->receivable()->first();

        if (! $titulo instanceof Receivable || $titulo->situacao === self::SITUACAO_TITULO_CANCELADO) {
            return;
        }

        $parcelas = $titulo->installments()
            ->get()
            ->reject(fn (ReceivableInstallment $item): bool => $item->situacao === self::SITUACAO_PARCELA_CANCELADA);

        if ($parcelas->isEmpty()) {
            return;
        }

        $situacao = match (true) {
            $parcelas->every(fn (ReceivableInstallment $item): bool => $item->situacao === self::SITUACAO_PARCELA_PAGA) => self::SITUACAO_TITULO_QUITADO,
            $parcelas->contains(fn (ReceivableInstallment $item): bool => Dinheiro::centavos($item->valor_pago) > 0) => self::SITUACAO_TITULO_PARCIAL,
            default => self::SITUACAO_TITULO_ABERTO,
        };

        if ($titulo->situacao !== $situacao) {
            $titulo->update(['situacao' => $situacao]);
        }
    }

    private function emitirNotaFiscalDoTituloQuitado(ReceivableInstallment $parcela): void
    {
        $titulo = $parcela->receivable()->first();

        if (! $titulo instanceof Receivable || $titulo->situacao !== self::SITUACAO_TITULO_QUITADO) {
            return;
        }

        try {
            $this->notasFiscais->emitirAutomaticamenteDoTitulo($titulo);
        } catch (Throwable $falha) {
            Log::error('[fiscal] Emissão automática do título falhou após a quitação.', [
                'receivable_id' => $titulo->id,
                'erro' => $falha->getMessage(),
            ]);
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
     * Valor recebido, em centavos.
     *
     * @param  array<string, mixed>  $dados
     */
    private function valorInformado(array $dados): int
    {
        $valor = $dados['valor'] ?? null;

        if ($valor === null || $valor === '' || ! is_numeric($valor)) {
            throw ValidationException::withMessages([
                'valor' => 'Informe o valor recebido.',
            ]);
        }

        $centavos = Dinheiro::centavos($valor);

        if ($centavos <= 0) {
            throw ValidationException::withMessages([
                'valor' => 'O valor recebido precisa ser maior que zero.',
            ]);
        }

        return $centavos;
    }

    /**
     * Dia do recebimento (`Y-m-d`), no fuso do negócio.
     *
     * Obrigatório de propósito, sem cair para "hoje": o usuário lança dias
     * depois do depósito, e assumir hoje jogaria o dinheiro na competência
     * errada do caixa sem ninguém perceber.
     *
     * @param  array<string, mixed>  $dados
     */
    private function diaInformado(array $dados): string
    {
        $data = $dados['data'] ?? null;

        if ($data === null || $data === '') {
            throw ValidationException::withMessages([
                'data' => 'Informe a data do recebimento, que é o dia em que o dinheiro entrou.',
            ]);
        }

        $dia = BusinessDate::diaDe($data);

        if ($dia === null) {
            throw ValidationException::withMessages([
                'data' => 'A data do recebimento é inválida.',
            ]);
        }

        return $dia;
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
}
