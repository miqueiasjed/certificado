<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\OperaPorTenant;
use App\Models\Company;
use App\Models\CompanyBillingSetting;
use App\Models\Contract;
use App\Models\ReceivableInstallment;
use App\Services\ChargeService;
use App\Support\BusinessDate;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * Rotina diária (Plano 19, Task 19.5) que emite sozinha a cobrança (boleto ou
 * Pix) da mensalidade de contrato: toda parcela que vence dentro da
 * antecedência configurada pelo tenant e ainda não tem cobrança ativa recebe
 * uma cobrança nova, pelo mesmo `ChargeService` da emissão manual (Task
 * 19.3).
 *
 * "De contrato" é `receivable.contract_id` não nulo: título avulso (gerado
 * de uma OS ou lançado à mão) não é tocado por esta rotina, porque não tem
 * uma competência recorrente para justificar cobrança automática.
 *
 * ## Antecedência por tenant
 *
 * `CompanyBillingSetting::atual()->antecedenciaEmissaoDias()` decide até
 * quantos dias à frente uma parcela é elegível (10 dias por padrão). Emitir
 * cedo demais é o erro documentado na especificação da task: um boleto
 * emitido com 60 dias de antecedência é esquecido pelo cliente e vence sem
 * ser pago.
 *
 * ## Idempotente
 *
 * Rodar esta rotina duas vezes no mesmo dia não duplica cobrança: a consulta
 * já exclui parcela com cobrança ativa (`pendente`, `emitida` ou `vencida`),
 * e `ChargeService::emitir()` repete a mesma checagem dentro de uma
 * transação com a parcela travada, para duas emissões simultâneas da mesma
 * parcela não passarem as duas.
 *
 * ## Falha isolada por tenant e por parcela
 *
 * Percorre todas as empresas com `OperaPorTenant`: uma empresa com erro não
 * impede a emissão das demais. Dentro da mesma empresa, uma parcela com dado
 * incompleto (endereço sem CEP, cliente sem documento válido) também não
 * pode travar as parcelas seguintes: o erro é reportado e a rotina segue,
 * mesmo critério de `emitirEmLote()`.
 *
 * O registro em `scheduled_task_runs` é automático para toda execução
 * disparada pelo `$schedule` do Laravel (gancho `RegistraExecucaoAgendada`
 * em `bootstrap/app.php`, aplicado a partir de `RotinasAgendadas::DIARIAS`).
 */
class EmitirCobrancasRecorrentes extends Command
{
    use OperaPorTenant;

    protected $signature = 'cobrancas:emitir-recorrentes';

    protected $description = 'Emite a cobrança das parcelas de contrato que vencem dentro da antecedência configurada e ainda não têm cobrança ativa.';

    /**
     * Situações de `ReceivableInstallment` que ainda podem gerar cobrança.
     * Cópia deliberada do critério de `ChargeService::garantirParcelaCobravel()`
     * (privado lá): a régua e a emissão recorrente só podem considerar
     * elegível o que a emissão manual também aceitaria.
     *
     * @var array<int, string>
     */
    private const SITUACOES_COBRAVEIS = ['aberta', 'parcial'];

    /**
     * Situações de `Charge` que contam como cobrança ativa. Mesmo critério
     * de `App\Services\Payments\ReguaDeCobrancaService::SITUACOES_DE_COBRANCA_ATIVA`.
     *
     * @var array<int, string>
     */
    private const SITUACOES_DE_COBRANCA_ATIVA = ['pendente', 'emitida', 'vencida'];

    /**
     * Alguma parcela, em alguma empresa desta execução, terminou com erro.
     * `paraCadaTenant()` só marca `Command::FAILURE` quando o callback
     * lança; erro de parcela é capturado dentro de `emitirDaEmpresa()` para
     * não travar as demais, então o código de saída final combina os dois
     * sinais, mesmo critério de `GerarTitulosDeContrato::$algumContratoFalhou`.
     */
    private bool $algumaParcelaFalhou = false;

    public function __construct(private readonly ChargeService $chargeService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Emitindo cobranças recorrentes de parcelas de contrato...');

        $codigo = $this->paraCadaTenant(fn (Company $empresa): int => $this->emitirDaEmpresa());

        return $codigo === Command::SUCCESS && $this->algumaParcelaFalhou ? Command::FAILURE : $codigo;
    }

    /**
     * Uma empresa, já dentro do tenant dela.
     *
     * @return int quantidade de cobranças emitidas com sucesso nesta empresa
     */
    private function emitirDaEmpresa(): int
    {
        $antecedencia = CompanyBillingSetting::atual()->antecedenciaEmissaoDias();
        $vencimentoLimite = BusinessDate::hoje()->addDays($antecedencia)->toDateString();

        $parcelas = $this->parcelasElegiveis($vencimentoLimite);

        $this->line(
            "  Antecedência configurada: {$antecedencia} dia(s). "
            ."Parcelas de contrato elegíveis até {$vencimentoLimite}: {$parcelas->count()}"
        );

        $emitidas = 0;
        $comErro = 0;

        foreach ($parcelas as $parcela) {
            try {
                $this->chargeService->emitir($parcela, $this->tipoDeCobranca($parcela));
                $emitidas++;
            } catch (Throwable $erro) {
                report($erro);

                $comErro++;
                $this->warn("  Parcela #{$parcela->id}: erro ao emitir cobrança - {$erro->getMessage()}");
            }
        }

        $this->line("  Cobranças emitidas: {$emitidas}. Parcelas com erro: {$comErro}.");

        if ($comErro > 0) {
            $this->algumaParcelaFalhou = true;
        }

        return $emitidas;
    }

    /**
     * Parcelas de contrato dentro da antecedência, ainda cobráveis e sem
     * cobrança ativa: exatamente o critério de "vence dentro da antecedência
     * e ainda não tem cobrança ativa" da especificação da task.
     *
     * O piso é hoje, não o passado: parcela já vencida é assunto da régua de
     * cobrança (`ReguaDeCobrancaService`), não desta emissão, que existe
     * para cobrar com antecedência, nunca para tentar emitir boleto atrasado
     * em silêncio.
     *
     * @return Collection<int, ReceivableInstallment>
     */
    private function parcelasElegiveis(string $vencimentoLimite): Collection
    {
        $hoje = BusinessDate::hoje()->toDateString();

        return ReceivableInstallment::query()
            ->whereIn('situacao', self::SITUACOES_COBRAVEIS)
            ->whereDate('vencimento', '>=', $hoje)
            ->whereDate('vencimento', '<=', $vencimentoLimite)
            ->whereHas('receivable', fn ($consulta) => $consulta->whereNotNull('contract_id'))
            ->whereDoesntHave('charges', fn ($consulta) => $consulta->whereIn('situacao', self::SITUACOES_DE_COBRANCA_ATIVA))
            ->with('receivable.contract')
            ->orderBy('vencimento')
            ->orderBy('id')
            ->get();
    }

    /**
     * Tipo de cobrança a emitir: `pix` quando a forma de pagamento anotada
     * no contrato menciona Pix, `boleto` em qualquer outro caso (inclusive
     * contrato sem forma de pagamento anotada).
     *
     * `Contract.payment_method` é texto livre preenchido pelo usuário (não
     * um enum fechado), então este é um critério textual, não uma
     * correspondência exata. Boleto como padrão é a escolha mais segura para
     * uma rotina sem interação humana: o link de pagamento continua válido
     * até o vencimento, ao contrário do Pix, cujo QR code tende a ter
     * validade mais curta no gateway.
     */
    private function tipoDeCobranca(ReceivableInstallment $parcela): string
    {
        $contrato = $parcela->receivable?->contract;

        if ($contrato instanceof Contract && Str::contains(Str::lower((string) $contrato->payment_method), 'pix')) {
            return 'pix';
        }

        return 'boleto';
    }
}
