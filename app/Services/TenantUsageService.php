<?php

namespace App\Services;

use App\Models\Certificate;
use App\Models\Client;
use App\Models\Company;
use App\Models\TenantUsage;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderPhoto;
use App\Support\BusinessDate;
use App\Support\TenantAtual;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

/**
 * Apuração de uso por tenant (Plano 5, painel do super admin; Plano 6,
 * limites de plano contratado).
 *
 * Conta, por empresa: usuários ativos, clientes, ordens de serviço criadas no
 * mês da referência, certificados criados no mês da referência, e o
 * armazenamento em MB ocupado pelas fotos de OS do tenant. Toda contagem roda
 * dentro de `TenantAtual::comTenant()`: nunca uma consulta sem escopo seguida
 * de filtro em memória, sempre uma consulta por tenant já filtrada pelo
 * escopo global de `BelongsToCompany`.
 *
 * `armazenamento_mb` é o cálculo mais caro daqui, porque `WorkOrderPhoto` não
 * grava o tamanho do arquivo no banco: cada apuração faz um `stat` de disco
 * por foto (`Storage::disk('public')->size()`). Gravar o tamanho em bytes na
 * criação da foto (`WorkOrderPhotoController`) evitaria esse custo em toda
 * rodada da rotina diária, recalculando só o delta; está fora do escopo desta
 * task, e fica registrado aqui para quando o Plano 6 sentir o custo em
 * produção.
 *
 * A apuração é leitura pura: nenhum método daqui grava nada fora de
 * `TenantUsage` (`apurar`) ou de `report()` para erro de um tenant.
 */
class TenantUsageService
{
    /**
     * Apura o uso de uma empresa no período informado e grava (ou sobrescreve)
     * a linha correspondente em `tenant_usages`.
     *
     * `updateOrCreate` na chave `[company_id, referencia]` é o que garante
     * "rodar duas vezes no mesmo mês sobrescreve, não duplica": a mesma unique
     * composta está no banco (migration da Task 5.2), esta é só a metade que
     * grava.
     *
     * `apurado_em` é o instante da apuração, gravado com `now()` (UTC), igual
     * a todo campo de instante do projeto. `referencia` continua sendo quem a
     * chamou decidiu: este método não recalcula o mês corrente sozinho, para
     * que a rotina agendada e um reprocessamento manual de mês passado usem o
     * mesmo caminho de código.
     */
    public function apurar(Company $empresa, string $referencia): TenantUsage
    {
        $this->validarReferencia($referencia);

        $uso = TenantAtual::comTenant(
            (int) $empresa->id,
            fn (): array => $this->contarUso($referencia)
        );

        return TenantUsage::updateOrCreate(
            ['company_id' => $empresa->id, 'referencia' => $referencia],
            [...$uso, 'apurado_em' => now()]
        );
    }

    /**
     * Apura todas as empresas cadastradas para o período informado.
     *
     * Erro em uma empresa é capturado e reportado (`report()`, para o log da
     * aplicação) sem abortar as demais: uma linha com `erro` preenchido e
     * `uso` nulo entra no retorno no lugar da apuração, e o laço segue para a
     * próxima empresa. Quem chama (o comando `plataforma:apurar-uso`) decide
     * como exibir isso.
     *
     * @return array<int, array{empresa: Company, uso: ?TenantUsage, erro: ?string}>
     */
    public function apurarTodos(string $referencia): array
    {
        $this->validarReferencia($referencia);

        $resultado = [];

        foreach (Company::query()->orderBy('id')->get() as $empresa) {
            try {
                $resultado[] = [
                    'empresa' => $empresa,
                    'uso' => $this->apurar($empresa, $referencia),
                    'erro' => null,
                ];
            } catch (Throwable $erro) {
                report($erro);

                $resultado[] = [
                    'empresa' => $empresa,
                    'uso' => null,
                    'erro' => $erro->getMessage(),
                ];
            }
        }

        return $resultado;
    }

    /**
     * Mesma contagem de `apurar()`, para o mês corrente no fuso do negócio,
     * mas sem gravar nada em `tenant_usages`.
     *
     * Existe para o painel do tenant (Plano 6) e o painel do super admin
     * mostrarem um número ao vivo do mês em andamento sem esperar a próxima
     * rodada da rotina diária (`plataforma:apurar-uso`, agendada às 03:00).
     *
     * @return array{usuarios: int, clientes: int, ordens_servico: int, certificados: int, armazenamento_mb: int}
     */
    public function usoAtual(Company $empresa): array
    {
        $referencia = BusinessDate::agora()->format('Y-m');

        return TenantAtual::comTenant(
            (int) $empresa->id,
            fn (): array => $this->contarUso($referencia)
        );
    }

    /**
     * As cinco contagens, já dentro do tenant corrente (`TenantAtual::comTenant`).
     *
     * `usuarios` e `clientes` são um retrato do estado atual (não têm "mês"):
     * quantos existem agora, independente da referência apurada. `ordens_servico`
     * e `certificados` são contados pela criação dentro do mês da referência.
     * `armazenamento_mb` também é um retrato do estado atual, porque foto
     * antiga continua ocupando disco.
     *
     * @return array{usuarios: int, clientes: int, ordens_servico: int, certificados: int, armazenamento_mb: int}
     */
    private function contarUso(string $referencia): array
    {
        [$inicio, $fim] = $this->limitesDoMes($referencia);

        return [
            'usuarios' => User::query()->daEmpresaAtual()->where('is_active', true)->count(),
            'clientes' => Client::query()->count(),
            'ordens_servico' => WorkOrder::query()
                ->where('created_at', '>=', $inicio)
                ->where('created_at', '<', $fim)
                ->count(),
            'certificados' => Certificate::query()
                ->where('created_at', '>=', $inicio)
                ->where('created_at', '<', $fim)
                ->count(),
            'armazenamento_mb' => $this->armazenamentoEmMb(),
        ];
    }

    /**
     * Início (inclusive) e fim (exclusive) do mês da referência, convertidos
     * para UTC.
     *
     * `created_at` é instante, gravado em UTC (regra do projeto para campo de
     * instante). "Mês da referência" é calculado no fuso do negócio: o início
     * é a meia-noite de `referencia-01` em America/Sao_Paulo, e o fim é a
     * meia-noite do primeiro dia do mês seguinte, também em
     * America/Sao_Paulo. Os dois são convertidos para UTC só depois de
     * montados, porque é isso, e não a rotina rodar às 03:00, que evita o erro
     * de mês: se os limites fossem calculados direto em UTC, uma apuração
     * rodando perto da virada do mês contaria (ou perderia) horas de OS que
     * pertencem ao mês vizinho no fuso do negócio.
     *
     * O corte é meio-aberto (`>= início` e `< fim`), não `whereBetween`
     * inclusive nos dois lados: evita depender de precisão de microssegundo
     * no último instante do mês, que a coluna `datetime` do MySQL não guarda.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function limitesDoMes(string $referencia): array
    {
        $inicioNoFuso = CarbonImmutable::createFromFormat(
            'Y-m-d',
            "{$referencia}-01",
            BusinessDate::fuso()
        )->startOfDay();

        $fimNoFuso = $inicioNoFuso->addMonthNoOverflow()->startOfDay();

        return [$inicioNoFuso->setTimezone('UTC'), $fimNoFuso->setTimezone('UTC')];
    }

    /**
     * Soma, em MB inteiros, o tamanho em disco de todas as fotos de OS do
     * tenant corrente.
     *
     * `WorkOrderPhoto` não grava tamanho em bytes (só `path`), então o valor
     * vem de um `stat` por arquivo via `Storage::disk('public')->size()`. O
     * disco `public` está configurado com `'throw' => false`
     * (`config/filesystems.php`): arquivo ausente (upload corrompido, exclusão
     * manual fora do sistema) faz `size()` devolver `false` em vez de lançar
     * exceção, e é tratado aqui como 0 bytes, para uma foto órfã não derrubar
     * a apuração da empresa inteira.
     */
    private function armazenamentoEmMb(): int
    {
        $bytes = WorkOrderPhoto::query()
            ->select(['id', 'path'])
            ->cursor()
            ->sum(function (WorkOrderPhoto $foto): int {
                $tamanho = Storage::disk('public')->size($foto->path);

                return $tamanho !== false ? $tamanho : 0;
            });

        return (int) round($bytes / 1024 / 1024);
    }

    /**
     * Soma, em MB, o armazenamento de todos os tenants, para o card de
     * armazenamento total do painel geral (Task 5.8).
     *
     * Decisão registrada aqui porque a Task 5.8 deixou em aberto: soma a
     * partir da apuração mais recente de cada tenant já gravada em
     * `tenant_usages` (o `armazenamento_mb` da rotina diária
     * `plataforma:apurar-uso`), e não chamando `usoAtual()` tenant a
     * tenant. `armazenamentoEmMb()` é o cálculo mais caro deste Service
     * porque faz um `stat()` de disco por foto (ver o cabeçalho da classe);
     * somar ao vivo para todos os tenants repetiria esse custo a cada
     * abertura do painel geral. A apuração diária existe exatamente para o
     * painel ler um número pronto, com a contrapartida de poder estar até
     * um dia desatualizado (a rotina roda às 03:00) — aceitável para um
     * card de visão geral da plataforma, que não é o extrato de cobrança de
     * um tenant específico.
     *
     * Para cada `company_id`, pega só a linha da `referencia` (mês) mais
     * recente, via subconsulta com `MAX(referencia)` e `join`, em vez de
     * somar todas as apurações históricas (o que contaria o mesmo
     * armazenamento várias vezes, um por mês apurado). Tenant sem nenhuma
     * apuração ainda (empresa recém-criada, antes da primeira rodada da
     * rotina) simplesmente não entra na soma.
     */
    public function armazenamentoTotalDaPlataforma(): int
    {
        $maisRecentePorEmpresa = TenantUsage::query()
            ->selectRaw('company_id, MAX(referencia) as referencia_mais_recente')
            ->groupBy('company_id');

        return (int) TenantUsage::query()
            ->joinSub(
                $maisRecentePorEmpresa,
                'mais_recente',
                fn ($join) => $join
                    ->on('tenant_usages.company_id', '=', 'mais_recente.company_id')
                    ->on('tenant_usages.referencia', '=', 'mais_recente.referencia_mais_recente')
            )
            ->sum('tenant_usages.armazenamento_mb');
    }

    private function validarReferencia(string $referencia): void
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $referencia) !== 1) {
            throw new InvalidArgumentException(
                "Referência de apuração inválida: \"{$referencia}\". Use o formato YYYY-MM."
            );
        }
    }
}
