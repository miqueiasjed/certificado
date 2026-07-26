<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\TenantUsage;
use App\Services\TenantUsageService;
use App\Support\BusinessDate;
use Illuminate\Console\Command;
use Throwable;

/**
 * Rotina diária (Task 5.6) que apura o uso de cada tenant: usuários ativos,
 * clientes, ordens de serviço e certificados criados no mês corrente, e
 * armazenamento ocupado pelas fotos de OS. Alimenta o painel de uso do super
 * admin (Plano 5) e, a partir do Plano 6, a comparação com os limites do
 * plano contratado.
 *
 * Diária, e não mensal: cada rodada sobrescreve a linha do mês corrente
 * (`TenantUsageService::apurar` grava por `updateOrCreate` na chave
 * `[company_id, referencia]`), então o painel sempre mostra o número
 * atualizado do mês em andamento, e não só o fechamento do mês anterior.
 *
 * O registro em `scheduled_task_runs` é automático para toda execução
 * disparada pelo `$schedule` do Laravel (gancho `RegistraExecucaoAgendada` em
 * `bootstrap/app.php`, aplicado a partir de `RotinasAgendadas::DIARIAS`).
 * Rodar este comando manualmente no terminal não passa por esse gancho, e não
 * deveria: o registro é para auditar a rotina agendada, não toda invocação
 * manual de reprocessamento.
 */
class ApurarUsoDosTenants extends Command
{
    protected $signature = 'plataforma:apurar-uso
                            {--referencia= : Mês a apurar, formato YYYY-MM (padrão: mês corrente no fuso do negócio)}
                            {--company= : Id da empresa a apurar. Sem esta opção, apura todas as empresas cadastradas.}';

    protected $description = 'Apura o uso mensal de cada tenant (usuários, clientes, OS, certificados e armazenamento de fotos).';

    public function __construct(private readonly TenantUsageService $servico)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $referencia = $this->resolverReferencia();

        if ($referencia === null) {
            return Command::INVALID;
        }

        $empresaId = $this->option('company');

        $itens = $empresaId !== null
            ? $this->apurarUmaEmpresa($empresaId, $referencia)
            : $this->apurarTodas($referencia);

        if ($itens === null) {
            return Command::INVALID;
        }

        $this->imprimirResultado($itens);

        $houveErro = collect($itens)->contains(fn (array $item): bool => $item['erro'] !== null);

        return $houveErro ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Lê `--referencia`, com o padrão vindo do mês corrente no fuso do
     * negócio (`BusinessDate`, nunca `now()` cru: às 03:00 UTC do dia 1 ainda
     * pode ser o mês anterior em fusos negativos, e o padrão do projeto é
     * sempre calcular "mês corrente" pelo fuso do negócio).
     */
    private function resolverReferencia(): ?string
    {
        $referencia = $this->option('referencia');

        if ($referencia === null) {
            return BusinessDate::agora()->format('Y-m');
        }

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $referencia) !== 1) {
            $this->error("--referencia precisa estar no formato YYYY-MM. Valor recebido: \"{$referencia}\".");

            return null;
        }

        return $referencia;
    }

    /**
     * @return array<int, array{empresa: Company, uso: ?TenantUsage, erro: ?string}>|null
     */
    private function apurarUmaEmpresa(string $empresaId, string $referencia): ?array
    {
        if (! ctype_digit($empresaId) || (int) $empresaId < 1) {
            $this->error("--company precisa ser o id inteiro positivo de uma empresa. Valor recebido: \"{$empresaId}\".");

            return null;
        }

        $empresa = Company::query()->find((int) $empresaId);

        if ($empresa === null) {
            $this->error("Empresa #{$empresaId} não existe. Nada foi apurado.");

            return null;
        }

        $this->info("Apurando uso da empresa #{$empresa->id} para {$referencia}...");

        try {
            return [[
                'empresa' => $empresa,
                'uso' => $this->servico->apurar($empresa, $referencia),
                'erro' => null,
            ]];
        } catch (Throwable $erro) {
            report($erro);

            return [[
                'empresa' => $empresa,
                'uso' => null,
                'erro' => $erro->getMessage(),
            ]];
        }
    }

    /**
     * @return array<int, array{empresa: Company, uso: ?TenantUsage, erro: ?string}>
     */
    private function apurarTodas(string $referencia): array
    {
        $this->info("Apurando uso de todos os tenants para {$referencia}...");

        return $this->servico->apurarTodos($referencia);
    }

    /**
     * Imprime a tabela padrão do Artisan com uma linha por empresa e uma
     * linha de total ao final. Empresa com erro entra com as contagens em
     * branco e a mensagem na coluna "Erro", sem interromper a impressão das
     * demais.
     *
     * @param  array<int, array{empresa: Company, uso: ?TenantUsage, erro: ?string}>  $itens
     */
    private function imprimirResultado(array $itens): void
    {
        $linhas = [];
        $totais = ['usuarios' => 0, 'clientes' => 0, 'ordens_servico' => 0, 'certificados' => 0, 'armazenamento_mb' => 0];

        foreach ($itens as $item) {
            $empresa = $item['empresa'];
            $uso = $item['uso'];

            $linhas[] = [
                "#{$empresa->id} {$empresa->name}",
                $uso?->usuarios ?? '-',
                $uso?->clientes ?? '-',
                $uso?->ordens_servico ?? '-',
                $uso?->certificados ?? '-',
                $uso?->armazenamento_mb ?? '-',
                $item['erro'] ?? '',
            ];

            if ($uso !== null) {
                $totais['usuarios'] += $uso->usuarios;
                $totais['clientes'] += $uso->clientes;
                $totais['ordens_servico'] += $uso->ordens_servico;
                $totais['certificados'] += $uso->certificados;
                $totais['armazenamento_mb'] += $uso->armazenamento_mb;
            }
        }

        $linhas[] = [
            'Total',
            $totais['usuarios'],
            $totais['clientes'],
            $totais['ordens_servico'],
            $totais['certificados'],
            $totais['armazenamento_mb'],
            '',
        ];

        $this->newLine();
        $this->table(
            ['Empresa', 'Usuários', 'Clientes', 'OS no mês', 'Certificados no mês', 'Armazenamento (MB)', 'Erro'],
            $linhas
        );

        $comErro = collect($itens)->filter(fn (array $item): bool => $item['erro'] !== null);

        if ($comErro->isNotEmpty()) {
            $this->newLine();
            $this->warn("{$comErro->count()} empresa(s) falharam na apuração. O log da aplicação tem o erro completo de cada uma.");
        }
    }
}
