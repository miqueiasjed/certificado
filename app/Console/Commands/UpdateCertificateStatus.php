<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\OperaPorTenant;
use App\Models\Certificate;
use App\Support\BusinessDate;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class UpdateCertificateStatus extends Command
{
    use OperaPorTenant;

    protected $signature = 'certificates:update-status
                            {--dry-run : Simula a execução e mostra as transições, sem gravar nada}';

    protected $description = 'Atualiza o status dos certificados comparando a garantia com o dia de hoje no fuso do negócio (America/Sao_Paulo). Garantia que vence hoje continua ativa.';

    public function handle(): int
    {
        $simulacao = (bool) $this->option('dry-run');
        $hoje = BusinessDate::hoje()->toDateString();

        $this->info('Atualizando status dos certificados...');
        $this->line('Dia de referência (' . BusinessDate::fuso() . '): ' . $hoje);
        $this->line($simulacao
            ? 'Modo simulação (--dry-run): nada será gravado.'
            : 'Modo aplicação: os status serão gravados.');

        return $this->paraCadaTenant(fn (): int => $this->processarEmpresa($hoje, $simulacao));
    }

    /**
     * Uma empresa, já dentro do tenant dela.
     *
     * Todos os builders nascem e são executados aqui dentro: o escopo global
     * por empresa é avaliado na execução da consulta, então montar o builder
     * fora deste método o faria rodar com o tenant da volta errada do laço.
     *
     * @return int quantidade de certificados avaliados
     */
    private function processarEmpresa(string $hoje, bool $simulacao): int
    {
        $resumo = $this->levantarTransicoes($hoje);

        if ($this->getOutput()->isVerbose()) {
            $this->detalharPorCertificado($hoje);
        }

        if (! $simulacao) {
            // Reescreve o status de todos os certificados não cancelados, como
            // antes. A única mudança é a fronteira de data.
            $this->consultaVencidos($hoje)->update(['status' => 'expired']);
            $this->consultaAtivos($hoje)->update(['status' => 'active']);
        }

        $this->imprimirResumo($resumo, $simulacao);

        return $resumo['total'];
    }

    /**
     * Garantia estritamente anterior a hoje. Vencer hoje não é estar vencido.
     */
    private function consultaVencidos(string $hoje): Builder
    {
        return Certificate::query()
            ->where('status', '!=', 'cancelled')
            ->whereNotNull('warranty')
            ->where('warranty', '<', $hoje);
    }

    /**
     * Sem garantia ou garantia de hoje em diante.
     */
    private function consultaAtivos(string $hoje): Builder
    {
        return Certificate::query()
            ->where('status', '!=', 'cancelled')
            ->where(function (Builder $consulta) use ($hoje) {
                $consulta->whereNull('warranty')
                    ->orWhere('warranty', '>=', $hoje);
            });
    }

    /**
     * Conta, por status de origem, quantos certificados cairiam em cada
     * destino. É a mesma contagem nos dois modos, para que a simulação
     * corresponda ao que a aplicação faz em seguida.
     *
     * @return array{transicoes: array<string, int>, semAlteracao: int, total: int}
     */
    private function levantarTransicoes(string $hoje): array
    {
        $resumo = ['transicoes' => [], 'semAlteracao' => 0, 'total' => 0];

        foreach ($this->consultasPorDestino($hoje) as $destino => $consulta) {
            $porOrigem = $consulta()
                ->groupBy('status')
                ->selectRaw('status, count(*) as total')
                ->pluck('total', 'status');

            foreach ($porOrigem as $origem => $total) {
                $total = (int) $total;
                $resumo['total'] += $total;

                if ($origem === $destino) {
                    $resumo['semAlteracao'] += $total;

                    continue;
                }

                $chave = ($origem ?: 'sem status') . ' -> ' . $destino;
                $resumo['transicoes'][$chave] = ($resumo['transicoes'][$chave] ?? 0) + $total;
            }
        }

        return $resumo;
    }

    private function detalharPorCertificado(string $hoje): void
    {
        $this->newLine();
        $this->line('Detalhe por certificado:');

        foreach ($this->consultasPorDestino($hoje) as $destino => $consulta) {
            $consulta()
                ->where('status', '!=', $destino)
                ->select(['id', 'certificate_number', 'status', 'warranty'])
                ->chunkById(200, function ($certificados) use ($destino) {
                    foreach ($certificados as $certificado) {
                        $this->line(sprintf(
                            '  Certificado #%d (%s), garantia %s: %s -> %s',
                            $certificado->id,
                            $certificado->certificate_number ?: 'sem número',
                            BusinessDate::diaDe($certificado->warranty) ?? 'sem garantia',
                            $certificado->status,
                            $destino
                        ));
                    }
                });
        }
    }

    /**
     * Cada destino com a fábrica da sua consulta. Fábrica, e não a consulta
     * pronta, porque o builder é mutável e é reaproveitado em cada modo.
     *
     * @return array<string, callable(): Builder>
     */
    private function consultasPorDestino(string $hoje): array
    {
        return [
            'expired' => fn (): Builder => $this->consultaVencidos($hoje),
            'active' => fn (): Builder => $this->consultaAtivos($hoje),
        ];
    }

    /**
     * @param  array{transicoes: array<string, int>, semAlteracao: int, total: int}  $resumo
     */
    private function imprimirResumo(array $resumo, bool $simulacao): void
    {
        $this->newLine();
        $this->line($simulacao ? 'Transições que seriam aplicadas:' : 'Transições aplicadas:');

        if ($resumo['transicoes'] === []) {
            $this->line('  nenhuma');
        }

        foreach ($resumo['transicoes'] as $rotulo => $total) {
            $this->line("  {$rotulo}: {$total}");
        }

        $this->line("Sem alteração: {$resumo['semAlteracao']}");
        $this->line("Total avaliado: {$resumo['total']}");

        $this->info($simulacao
            ? 'Simulação concluída. Nenhum certificado foi gravado.'
            : 'Status dos certificados atualizado com sucesso!');
    }
}
