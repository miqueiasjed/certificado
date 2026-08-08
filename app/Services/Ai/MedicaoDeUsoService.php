<?php

namespace App\Services\Ai;

use App\Exceptions\TetoDeIaAtingidoException;
use App\Models\AiUsage;
use App\Models\Company;
use App\Support\Ai\TabelaDePrecos;
use App\Support\BusinessDate;
use App\Support\TenantAtual;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Custo e consumo de IA por tenant (Plano 25, Task 25.5).
 *
 * ## Por que medir antes de vender
 *
 * O custo do recurso é por chamada, e varia com o tamanho do documento e com
 * o aproveitamento do cache de prefixo. Transformar isso em item de plano sem
 * um mês de apuração real é escolher o preço no chute. Este Service é o que
 * produz o número: consumo por empresa e por competência, com o custo aberto
 * por tarifa.
 *
 * ## Preço em configuração
 *
 * A tabela vive em `config('ai.precos')`. Preço de modelo muda sem relação
 * nenhuma com o sistema, e atualizar a tabela não pode exigir deploy de
 * lógica.
 *
 * ## Teto
 *
 * O teto conta **chamadas do mês corrente**, com sucesso ou sem: tentativa que
 * consumiu token consumiu dinheiro. Ao atingir, apenas a geração é recusada —
 * ordem de serviço, certificado e financeiro seguem funcionando. Limite de um
 * recurso opcional que derruba o sistema é pior que não ter limite.
 *
 * Utilitário de leitura: não grava em `ai_usages` (quem grava é o provedor, na
 * hora da chamada).
 */
class MedicaoDeUsoService
{
    /**
     * Custo estimado de uma chamada, em dólar, a partir dos contadores de
     * token e da tabela de preço do modelo.
     *
     * Modelo fora da tabela devolve zero em vez de estimar por analogia: um
     * custo inventado é pior que um custo ausente, porque parece apurado.
     *
     * @param  array{entrada?: int, saida?: int, cache_leitura?: int, cache_escrita?: int}  $tokens
     */
    public function custoDaChamada(string $modelo, array $tokens): float
    {
        return TabelaDePrecos::custoDaChamada($modelo, $tokens);
    }

    /**
     * Consumo de uma empresa em uma competência (`AAAA-MM`).
     *
     * Consulta escopada explicitamente por `company_id`, e não pelo escopo
     * global: o painel da plataforma (Plano 5) chama isto de fora de qualquer
     * tenant, com a empresa escolhida na tela.
     *
     * @return array{
     *     competencia: string,
     *     chamadas: int,
     *     chamadas_com_sucesso: int,
     *     chamadas_com_falha: int,
     *     tokens_entrada: int,
     *     tokens_saida: int,
     *     tokens_cache_leitura: int,
     *     custo_estimado: float,
     *     aproveitamento_de_cache: float
     * }
     */
    public function consumoDoTenant(Company $empresa, string $competencia): array
    {
        [$inicio, $fim] = $this->limitesDaCompetencia($competencia);

        $linhas = TenantAtual::semEscopo(fn () => AiUsage::query()
            ->where('company_id', $empresa->id)
            ->whereBetween('created_at', [$inicio, $fim])
            ->get([
                'sucesso',
                'tokens_entrada',
                'tokens_saida',
                'tokens_cache_leitura',
                'custo_estimado',
            ]));

        $entrada = (int) $linhas->sum('tokens_entrada');
        $cacheLeitura = (int) $linhas->sum('tokens_cache_leitura');
        $lidosNoTotal = $entrada + $cacheLeitura;

        return [
            'competencia' => $competencia,
            'chamadas' => $linhas->count(),
            'chamadas_com_sucesso' => $linhas->where('sucesso', true)->count(),
            'chamadas_com_falha' => $linhas->where('sucesso', false)->count(),
            'tokens_entrada' => $entrada,
            'tokens_saida' => (int) $linhas->sum('tokens_saida'),
            'tokens_cache_leitura' => $cacheLeitura,
            'custo_estimado' => round((float) $linhas->sum(
                static fn (AiUsage $uso): float => (float) $uso->custo_estimado
            ), 6),
            // Quanto do que foi lido veio do cache. É o indicador que diz se o
            // prefixo estável está funcionando: perto de zero em volume alto
            // significa que alguém interpolou algo variável no prompt de
            // sistema e a conta está multiplicada.
            'aproveitamento_de_cache' => $lidosNoTotal > 0
                ? round(($cacheLeitura / $lidosNoTotal) * 100, 2)
                : 0.0,
        ];
    }

    /**
     * Custo somado de todos os tenants na competência, para o aviso ao super
     * admin.
     */
    public function custoDaPlataforma(string $competencia): float
    {
        [$inicio, $fim] = $this->limitesDaCompetencia($competencia);

        return round((float) TenantAtual::semEscopo(fn (): float => (float) AiUsage::query()
            ->whereBetween('created_at', [$inicio, $fim])
            ->sum('custo_estimado')), 6);
    }

    /**
     * O custo do mês passou do valor a partir do qual a plataforma avisa?
     */
    public function custoAcimaDoAviso(?string $competencia = null): bool
    {
        $teto = (float) config('ai.aviso_de_custo_mensal', 0);

        if ($teto <= 0) {
            return false;
        }

        return $this->custoDaPlataforma($competencia ?? $this->competenciaAtual()) > $teto;
    }

    /**
     * Gerações que a empresa já fez no mês corrente.
     */
    public function geracoesNoMes(Company $empresa): int
    {
        [$inicio, $fim] = $this->limitesDaCompetencia($this->competenciaAtual());

        return TenantAtual::semEscopo(fn (): int => AiUsage::query()
            ->where('company_id', $empresa->id)
            ->whereBetween('created_at', [$inicio, $fim])
            ->count());
    }

    /**
     * Teto mensal de gerações do plano da empresa. `null` é sem teto.
     */
    public function tetoDoMes(Company $empresa): ?int
    {
        $tetos = (array) config('ai.teto_de_geracoes_por_mes', []);
        $slug = $empresa->plan?->slug;

        $teto = $slug !== null && array_key_exists($slug, $tetos)
            ? $tetos[$slug]
            : ($tetos['padrao'] ?? null);

        return $teto === null ? null : (int) $teto;
    }

    /**
     * Recusa a geração quando a empresa já bateu o teto do mês.
     *
     * Chamada só nos caminhos de geração. Nenhum outro ponto do sistema
     * consulta este método: é essa separação que garante que o teto não
     * derrube OS, certificado ou financeiro.
     *
     * @throws TetoDeIaAtingidoException
     */
    public function garantirDentroDoTeto(Company $empresa): void
    {
        $teto = $this->tetoDoMes($empresa);

        if ($teto === null) {
            return;
        }

        $usadas = $this->geracoesNoMes($empresa);

        if ($usadas >= $teto) {
            throw TetoDeIaAtingidoException::doMes($teto);
        }
    }

    /**
     * Competência do mês corrente, no fuso do negócio.
     */
    public function competenciaAtual(): string
    {
        return BusinessDate::hoje()->format('Y-m');
    }

    /**
     * Primeiro e último instante da competência, em UTC.
     *
     * A competência é um mês no calendário do negócio (America/Sao_Paulo),
     * mas `ai_usages.created_at` é gravado em UTC como todo instante do
     * projeto. Comparar sem converter jogaria as três primeiras horas do dia
     * 1º para o mês anterior.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function limitesDaCompetencia(string $competencia): array
    {
        if (preg_match('/^\d{4}-\d{2}$/', $competencia) !== 1) {
            throw new InvalidArgumentException('A competência precisa estar no formato AAAA-MM.');
        }

        $primeiroDia = CarbonImmutable::createFromFormat(
            'Y-m-d H:i:s',
            $competencia.'-01 00:00:00',
            BusinessDate::fuso()
        );

        return [
            $primeiroDia->utc(),
            $primeiroDia->endOfMonth()->utc(),
        ];
    }
}
