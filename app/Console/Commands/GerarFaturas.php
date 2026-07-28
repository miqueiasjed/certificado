<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Services\InvoiceService;
use Illuminate\Console\Command;

/**
 * Rotina diária (Task 7.5) que emite a fatura de cada assinatura ativa cujo
 * ciclo já venceu, e pede a cobrança ao provedor de pagamento.
 *
 * Diária, e não mensal: cada tenant assina no dia em que assina, então cada um
 * tem o próprio dia de vencimento. Uma rotina mensal cobraria todo mundo no
 * mesmo dia, o que não é o contrato de ninguém.
 *
 * Rodar duas vezes no mesmo dia não duplica nada. A fatura é criada por
 * `firstOrCreate` na chave `[subscription_id, referencia]`, que é a mesma
 * unique composta do banco, e a cobrança só é pedida ao provedor quando a
 * fatura ainda não tem uma (ver `InvoiceService`).
 *
 * O registro em `scheduled_task_runs` é automático para toda execução
 * disparada pelo `$schedule` do Laravel (gancho `RegistraExecucaoAgendada` em
 * `bootstrap/app.php`, aplicado a partir de `RotinasAgendadas::DIARIAS`).
 * Rodar este comando manualmente no terminal não passa por esse gancho, e não
 * deveria: o registro é para auditar a rotina agendada, não toda invocação
 * manual de reprocessamento.
 */
class GerarFaturas extends Command
{
    protected $signature = 'plataforma:gerar-faturas
                            {--referencia= : Período YYYY-MM a gravar em todas as faturas desta passada. Sem esta opção, cada assinatura usa o próprio período, tirado de proxima_cobranca_em.}
                            {--company= : Id da empresa a faturar. Sem esta opção, percorre todas as assinaturas ativas com o ciclo vencido.}';

    protected $description = 'Gera a fatura do período de cada assinatura ativa com cobrança vencida e emite a cobrança no gateway.';

    public function __construct(private readonly InvoiceService $servico)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $referencia = $this->resolverReferencia();

        if ($referencia === false) {
            return Command::INVALID;
        }

        $empresa = $this->resolverEmpresa();

        if ($empresa === false) {
            return Command::INVALID;
        }

        $this->info($empresa === null
            ? 'Gerando faturas de todas as assinaturas com cobrança vencida...'
            : "Gerando faturas da empresa #{$empresa->id} {$empresa->name}...");

        $itens = $this->servico->gerarDoCiclo($referencia, $empresa);

        $this->imprimirResultado($itens);

        $houveErro = collect($itens)->contains(fn (array $item): bool => $item['erro'] !== null);

        return $houveErro ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Lê `--referencia`. Devolve `null` quando a opção não veio (cada
     * assinatura usa o próprio período) e `false` quando o valor é inválido.
     *
     * O padrão **não** é o mês corrente, ao contrário de `plataforma:apurar-uso`:
     * o período de uma fatura é o ciclo da assinatura, e é o Service que o
     * conhece. Ver `InvoiceService` para o motivo de a referência não sair de
     * "hoje" (fatura em aberto na virada do mês viraria cobrança duplicada).
     */
    private function resolverReferencia(): string|false|null
    {
        $referencia = $this->option('referencia');

        if ($referencia === null) {
            return null;
        }

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $referencia) !== 1) {
            $this->error("--referencia precisa estar no formato YYYY-MM. Valor recebido: \"{$referencia}\".");

            return false;
        }

        return $referencia;
    }

    /**
     * Lê `--company`. Devolve `null` quando a opção não veio e `false` quando o
     * valor não aponta para uma empresa existente.
     */
    private function resolverEmpresa(): Company|false|null
    {
        $empresaId = $this->option('company');

        if ($empresaId === null) {
            return null;
        }

        if (! ctype_digit($empresaId) || (int) $empresaId < 1) {
            $this->error("--company precisa ser o id inteiro positivo de uma empresa. Valor recebido: \"{$empresaId}\".");

            return false;
        }

        $empresa = Company::query()->find((int) $empresaId);

        if ($empresa === null) {
            $this->error("Empresa #{$empresaId} não existe. Nenhuma fatura foi gerada.");

            return false;
        }

        return $empresa;
    }

    /**
     * Uma linha por tenant percorrido, com o total ao final.
     *
     * A coluna "Situação" separa os três desfechos que importam na operação:
     * fatura nova, fatura que já existia (a idempotência funcionando) e tenant
     * que não gerou nada, com o motivo ao lado. Assinatura com erro entra com as
     * colunas em branco e a mensagem em "Observação", sem interromper a
     * impressão das demais.
     *
     * "Cobrança" diz se o provedor emitiu instrução de pagamento. Um "não" aí
     * não é falha da rodada: fatura no cartão nunca tem cobrança avulsa, e
     * fatura que o provedor não conseguiu emitir é tentada de novo na próxima
     * passada, sem virar fatura nova.
     *
     * @param  array<int, array{assinatura: Subscription, empresa: ?Company, fatura: ?Invoice, nova: bool, motivo: ?string, erro: ?string}>  $itens
     */
    private function imprimirResultado(array $itens): void
    {
        if ($itens === []) {
            $this->newLine();
            $this->line('Nenhuma assinatura ativa com cobrança vencida hoje. Nada a faturar.');

            return;
        }

        $linhas = [];
        $novas = 0;
        $valorNovo = 0.0;

        foreach ($itens as $item) {
            $empresa = $item['empresa'];
            $fatura = $item['fatura'];

            $linhas[] = [
                $empresa === null
                    ? "assinatura #{$item['assinatura']->id}"
                    : "#{$empresa->id} {$empresa->name}",
                $fatura?->referencia ?? '-',
                $fatura === null ? '-' : $this->diaEmBr($fatura),
                $fatura === null ? '-' : number_format((float) $fatura->valor, 2, ',', '.'),
                $this->situacaoDaLinha($item),
                $fatura === null ? '-' : (filled($fatura->gateway_invoice_id) ? 'sim' : 'não'),
                $item['erro'] ?? $item['motivo'] ?? '',
            ];

            if ($item['nova'] && $fatura !== null) {
                $novas++;
                $valorNovo += (float) $fatura->valor;
            }
        }

        $this->newLine();
        $this->table(
            ['Empresa', 'Referência', 'Vencimento', 'Valor (R$)', 'Situação', 'Cobrança', 'Observação'],
            $linhas
        );

        $this->line(sprintf(
            '%d assinatura(s) percorrida(s), %d fatura(s) nova(s), R$ %s emitidos.',
            count($itens),
            $novas,
            number_format($valorNovo, 2, ',', '.')
        ));

        $comErro = collect($itens)->filter(fn (array $item): bool => $item['erro'] !== null);

        if ($comErro->isNotEmpty()) {
            $this->newLine();
            $this->warn(
                "{$comErro->count()} assinatura(s) falharam na geração. O log da aplicação tem o erro completo de cada uma. "
                .'As demais foram faturadas normalmente.'
            );
        }
    }

    /**
     * @param  array{assinatura: Subscription, empresa: ?Company, fatura: ?Invoice, nova: bool, motivo: ?string, erro: ?string}  $item
     */
    private function situacaoDaLinha(array $item): string
    {
        if ($item['erro'] !== null) {
            return 'erro';
        }

        if ($item['fatura'] === null) {
            return 'ignorada';
        }

        return $item['nova'] ? 'gerada' : 'já existia';
    }

    /**
     * Vencimento no formato brasileiro. Formatado a partir do dia gravado, sem
     * conversão de fuso: `vencimento` é campo `date`, e converter campo de dia
     * é o que produz o erro de um dia (skill `datas-timezone`).
     */
    private function diaEmBr(Invoice $fatura): string
    {
        return $fatura->vencimento->format('d/m/Y');
    }
}
