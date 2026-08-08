<?php

namespace App\Services\Ai;

use App\Models\Budget;
use App\Models\Company;
use App\Support\BusinessDate;
use Illuminate\Support\Collection;

/**
 * Orçamentos parecidos já aprovados pela própria empresa (Plano 25, Task 25.4).
 *
 * ## Isolamento absoluto
 *
 * Preço praticado é informação estratégica, e os tenants deste sistema são
 * concorrentes entre si. Nenhum orçamento de outra empresa entra nesta busca,
 * em nenhuma hipótese: nem como exemplo, nem agregado, nem anonimizado — uma
 * mediana calculada sobre a base de todo mundo entrega o preço de todo mundo.
 *
 * Duas camadas garantem isso, de propósito redundantes: o escopo global de
 * `Budget` (trait `BelongsToCompany`) e o `where` explícito por
 * `company_id` que este arquivo aplica. A redundância é barata e o erro que
 * ela previne é o mais grave possível aqui.
 *
 * ## O que conta como "parecido"
 *
 * Mesmo tipo de ambiente, área na mesma faixa e quantidade de cômodos na mesma
 * faixa. Faixa, e não valor exato, porque orçamento de 118 m² e de 125 m² são
 * o mesmo serviço na prática, e exigir igualdade zeraria a amostra na maioria
 * das buscas.
 *
 * Utilitário de leitura: não grava nada.
 */
class BuscaDeServicoParecido
{
    /**
     * Quantas referências no máximo voltam.
     *
     * Vinte é o suficiente para uma mediana estável e pouco o bastante para a
     * justificativa em texto caber em um contexto barato.
     */
    public const LIMITE = 20;

    /**
     * Janela do histórico, em meses.
     *
     * Dois anos: preço de três anos atrás não descreve o mercado de hoje, e
     * janela curta demais zera a amostra de quem orça pouco.
     */
    public const MESES_DE_HISTORICO = 24;

    /**
     * Tolerância da faixa de área e de cômodos, para cima e para baixo.
     */
    private const TOLERANCIA = 0.4;

    /**
     * Situações que contam como orçamento aprovado.
     *
     * `converted` entra junto com `approved` porque é o aprovado que já virou
     * ordem de serviço: deixá-lo de fora descartaria justamente as referências
     * mais confiáveis, que são as que o cliente pagou.
     *
     * @var array<int, string>
     */
    private const SITUACOES_APROVADAS = ['approved', 'converted'];

    /**
     * Referências de preço parecidas, da própria empresa.
     *
     * @param  array{environment_type?: ?string, size?: mixed, rooms?: mixed, service_ids?: array<int, int>}  $criterios
     * @return Collection<int, array{budget_id: int, valor: float, area: ?float, comodos: ?int, ambiente: ?string, data: ?string}>
     */
    public function buscar(array $criterios, Company $empresa): Collection
    {
        $consulta = Budget::query()
            // Redundante com o escopo global, e é para ser: ver o cabeçalho.
            ->where('company_id', $empresa->id)
            ->whereIn('status', self::SITUACOES_APROVADAS)
            ->where('date', '>=', BusinessDate::hoje()->subMonths(self::MESES_DE_HISTORICO)->toDateString())
            ->with(['services' => fn ($relacao) => $relacao->select('services.id')]);

        if (filled($criterios['environment_type'] ?? null)) {
            $consulta->where('environment_type', $criterios['environment_type']);
        }

        $servicos = array_filter((array) ($criterios['service_ids'] ?? []));

        if ($servicos !== []) {
            $consulta->whereHas(
                'services',
                fn ($relacao) => $relacao->whereIn('services.id', $servicos)
            );
        }

        $area = $this->numero($criterios['size'] ?? null);
        $comodos = $this->numero($criterios['rooms'] ?? null);

        return $consulta
            ->orderByDesc('date')
            ->get()
            ->map(fn (Budget $orcamento): array => [
                'budget_id' => (int) $orcamento->id,
                'valor' => $this->valorDoOrcamento($orcamento),
                'area' => $this->numero($orcamento->size),
                'comodos' => $this->numero($orcamento->rooms) !== null
                    ? (int) $this->numero($orcamento->rooms)
                    : null,
                'ambiente' => $orcamento->environment_type,
                'data' => BusinessDate::diaDe($orcamento->date),
            ])
            // Orçamento aprovado com valor zerado não é referência de preço: é
            // cadastro incompleto, e entraria puxando a mediana para baixo.
            ->filter(fn (array $referencia): bool => $referencia['valor'] > 0)
            ->filter(fn (array $referencia): bool => $this->dentroDaFaixa($referencia['area'], $area))
            ->filter(fn (array $referencia): bool => $this->dentroDaFaixa(
                $referencia['comodos'] !== null ? (float) $referencia['comodos'] : null,
                $comodos
            ))
            ->take(self::LIMITE)
            ->values();
    }

    /**
     * Valor fechado do orçamento: soma dos serviços menos o desconto.
     *
     * `budgets` não tem coluna de total; o total vive no pivot
     * `budget_services.subtotal`, congelado no momento do orçamento. É esse
     * valor congelado que interessa como referência histórica, e não o preço
     * de tabela de hoje.
     */
    private function valorDoOrcamento(Budget $orcamento): float
    {
        $bruto = (float) $orcamento->services->sum(
            fn ($servico): float => (float) ($servico->pivot->subtotal ?? 0)
        );

        return max(0.0, round($bruto - (float) $orcamento->discount, 2));
    }

    /**
     * A referência cai na faixa do critério?
     *
     * Sem critério informado, tudo passa. Com critério informado e referência
     * sem o dado, a referência passa também: descartar orçamento antigo por
     * campo em branco esvaziaria a amostra justamente em quem tem cadastro
     * irregular, que é a maioria.
     */
    private function dentroDaFaixa(?float $valor, ?float $referencia): bool
    {
        if ($referencia === null || $referencia <= 0 || $valor === null) {
            return true;
        }

        return $valor >= $referencia * (1 - self::TOLERANCIA)
            && $valor <= $referencia * (1 + self::TOLERANCIA);
    }

    /**
     * Primeiro número de um campo que é texto livre no cadastro.
     *
     * `budgets.size` e `budgets.rooms` são `string`: chegam como "120",
     * "120 m2", "cerca de 120m²". O que interessa é o número.
     */
    private function numero(mixed $valor): ?float
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        if (is_numeric($valor)) {
            return (float) $valor;
        }

        $texto = str_replace(',', '.', (string) $valor);

        if (preg_match('/\d+(\.\d+)?/', $texto, $encontrado) !== 1) {
            return null;
        }

        return (float) $encontrado[0];
    }
}
