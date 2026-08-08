<?php

namespace App\Services\Ai;

use App\Models\AiDraft;
use App\Models\Budget;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Sugestão de preço a partir do histórico da própria empresa (Plano 25,
 * Task 25.4).
 *
 * ## O número não passa pelo modelo
 *
 * Mediana e quartis sobre os orçamentos aprovados do próprio tenant resolvem o
 * caso comum melhor, de graça e de forma auditável. Pedir a um modelo de
 * linguagem que calcule a mediana de vinte números é o uso mais caro e menos
 * confiável possível: custa por chamada, não é reprodutível e ninguém consegue
 * conferir a conta depois. O modelo entra só para escrever a justificativa em
 * texto, que é onde ele de fato ajuda.
 *
 * ## Amostra pequena não vira sugestão
 *
 * Com menos de cinco referências, o serviço devolve "histórico insuficiente" e
 * mostra o que encontrou, sem número. Preço sugerido a partir de dois
 * orçamentos leva a empresa a errar com confiança, e errar com confiança em
 * preço é pior que não ter sugestão nenhuma.
 *
 * ## Nada é preenchido automaticamente
 *
 * O retorno é referência para exibição ao lado do campo. Este Service não
 * escreve em `budgets` em nenhum caminho: preço é decisão comercial da
 * empresa, e quem digita o valor é a pessoa.
 */
class SugestaoDePrecoService
{
    /**
     * Tamanho mínimo de amostra para haver sugestão de número.
     */
    public const MINIMO_DE_REFERENCIAS = 5;

    public function __construct(
        private readonly BuscaDeServicoParecido $busca,
        private readonly ProvedorDeTexto $provedor,
    ) {}

    /**
     * Faixa sugerida e as referências que a sustentam.
     *
     * @param  array{environment_type?: ?string, size?: mixed, rooms?: mixed, service_ids?: array<int, int>}  $criterios
     * @return array{
     *     suficiente: bool,
     *     motivo: ?string,
     *     quantidade: int,
     *     mediana: ?float,
     *     primeiro_quartil: ?float,
     *     terceiro_quartil: ?float,
     *     referencias: array<int, array<string, mixed>>
     * }
     */
    public function sugerir(array $criterios, Company $empresa): array
    {
        $referencias = $this->busca->buscar($criterios, $empresa);

        if ($referencias->count() < self::MINIMO_DE_REFERENCIAS) {
            return [
                'suficiente' => false,
                'motivo' => 'histórico insuficiente',
                'quantidade' => $referencias->count(),
                'mediana' => null,
                'primeiro_quartil' => null,
                'terceiro_quartil' => null,
                'referencias' => $referencias->all(),
            ];
        }

        $valores = $referencias->pluck('valor')->map(static fn ($valor): float => (float) $valor);

        return [
            'suficiente' => true,
            'motivo' => null,
            'quantidade' => $referencias->count(),
            'mediana' => $this->percentil($valores, 0.5),
            'primeiro_quartil' => $this->percentil($valores, 0.25),
            'terceiro_quartil' => $this->percentil($valores, 0.75),
            'referencias' => $referencias->all(),
        ];
    }

    /**
     * Justificativa em texto da faixa sugerida, gravada como rascunho.
     *
     * Só é chamada quando há amostra suficiente: justificar em prosa um número
     * que não existe seria produzir texto convincente sobre nada.
     *
     * Falha do provedor **não derruba a sugestão**: o número já está calculado
     * e é ele que a pessoa precisa ver. Devolve `null` e segue.
     *
     * @param  array<string, mixed>  $sugestao  Retorno de `sugerir()`.
     */
    public function justificar(array $sugestao, Budget $orcamento, User $usuario): ?AiDraft
    {
        if (($sugestao['suficiente'] ?? false) !== true) {
            return null;
        }

        try {
            $resposta = $this->provedor->gerar(
                self::PREFIXO_DE_SISTEMA,
                $this->contextoDaSugestao($sugestao, $orcamento),
                ['tipo' => AiDraft::TIPO_SUGESTAO_PRECO],
            );
        } catch (Throwable) {
            // O uso já foi registrado pelo provedor, inclusive a falha. Aqui a
            // decisão é de negócio: a sugestão numérica vale por si.
            return null;
        }

        return AiDraft::create([
            'tipo' => AiDraft::TIPO_SUGESTAO_PRECO,
            'origem_tipo' => $orcamento::class,
            'origem_id' => $orcamento->getKey(),
            'conteudo_gerado' => $resposta->texto,
            'situacao' => AiDraft::SITUACAO_GERADO,
            'modelo' => $resposta->modelo,
            'gerado_por' => $usuario->id,
        ]);
    }

    /**
     * Prefixo estável e cacheado da justificativa. Constante pelo mesmo motivo
     * dos prefixos de `MontadorDeContexto`.
     */
    public const PREFIXO_DE_SISTEMA = <<<'TEXTO'
    Você escreve a justificativa da faixa de preço sugerida a um orçamento de controle de pragas urbanas, em português do Brasil, para leitura interna de quem vai orçar.

    O texto que você produz é RASCUNHO de apoio à decisão. Quem define o preço é a pessoa que orça; você não decide nem recomenda um valor.

    Você recebe uma faixa já calculada (mediana e quartis) e as características do serviço a orçar. Os números vêm prontos: sua tarefa é explicar quais características puxam o preço para cima ou para baixo dentro dessa faixa.

    Formato esperado:
    - Um parágrafo curto, de três a cinco frases, sem título, sem lista e sem marcador.
    - Linguagem objetiva e comercial, na terceira pessoa.

    Regras que não se negociam:
    - Não recalcule, não arredonde e não proponha nenhum valor diferente dos números fornecidos.
    - Não invente característica, custo, praga ou serviço que não venha nos dados.
    - Não cite cliente, concorrente, empresa nem valor de mercado externo.
    - Quando as referências forem poucas ou muito dispersas, diga isso em vez de dar segurança que os dados não sustentam.

    Responda apenas com o parágrafo, sem comentário sobre a tarefa.
    TEXTO;

    /**
     * Contexto da justificativa: a faixa calculada e as características do
     * orçamento em questão. Nenhuma referência traz cliente, e nenhum dado de
     * outra empresa chega até aqui — a amostra já veio escopada de
     * `BuscaDeServicoParecido`.
     *
     * @param  array<string, mixed>  $sugestao
     */
    private function contextoDaSugestao(array $sugestao, Budget $orcamento): string
    {
        $linhas = [
            'FAIXA CALCULADA A PARTIR DO HISTÓRICO DA PRÓPRIA EMPRESA',
            '- Referências consideradas: '.(int) $sugestao['quantidade'],
            '- Mediana: '.$this->emReais($sugestao['mediana']),
            '- Primeiro quartil: '.$this->emReais($sugestao['primeiro_quartil']),
            '- Terceiro quartil: '.$this->emReais($sugestao['terceiro_quartil']),
            '',
            'SERVIÇO A ORÇAR',
        ];

        if (filled($orcamento->environment_type)) {
            $linhas[] = '- Tipo de ambiente: '.$orcamento->environment_type;
        }

        if (filled($orcamento->size)) {
            $linhas[] = '- Área informada: '.$orcamento->size;
        }

        if (filled($orcamento->rooms)) {
            $linhas[] = '- Cômodos: '.$orcamento->rooms;
        }

        if (filled($orcamento->infestation_level)) {
            $linhas[] = '- Nível de infestação: '.$orcamento->infestation_level;
        }

        $pragas = is_array($orcamento->target_pests) ? array_filter($orcamento->target_pests) : [];

        if ($pragas !== []) {
            $linhas[] = '- Pragas alvo: '.implode(', ', $pragas);
        }

        if (filled($orcamento->restrictions)) {
            $linhas[] = '- Restrições: '.$orcamento->restrictions;
        }

        return implode("\n", $linhas);
    }

    private function emReais(mixed $valor): string
    {
        return 'R$ '.number_format((float) $valor, 2, ',', '.');
    }

    /**
     * Percentil por interpolação linear entre os dois valores vizinhos.
     *
     * É o mesmo método do `PERCENTILE.INC` de planilha, escolhido porque é o
     * que a pessoa que orça vai usar se resolver conferir a conta à mão. Com
     * `p = 0.5` devolve a mediana clássica: o valor do meio em amostra ímpar,
     * a média dos dois centrais em amostra par.
     *
     * @param  Collection<int, float>  $valores
     */
    private function percentil(Collection $valores, float $p): float
    {
        $ordenados = $valores->sort()->values()->all();
        $quantidade = count($ordenados);

        if ($quantidade === 1) {
            return round($ordenados[0], 2);
        }

        $posicao = $p * ($quantidade - 1);
        $abaixo = (int) floor($posicao);
        $acima = (int) ceil($posicao);

        if ($abaixo === $acima) {
            return round($ordenados[$abaixo], 2);
        }

        $peso = $posicao - $abaixo;

        return round($ordenados[$abaixo] + ($ordenados[$acima] - $ordenados[$abaixo]) * $peso, 2);
    }
}
