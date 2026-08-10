<?php

namespace App\Services\Routing;

use App\Support\Geo\Coordenada;
use App\Support\Geo\ResultadoGeo;

/**
 * Ordena as paradas de um roteiro do dia por proximidade, respeitando OS com
 * horário combinado com o cliente (Plano 22, Task 22.3).
 *
 * ## Algoritmo
 *
 * Vizinho mais próximo a partir da sede, seguido de uma passada de melhoria
 * 2-opt (troca de pares de arestas que reduz a distância total do trajeto).
 * Com até 15 paradas - o caso real de um dia -, essa combinação chega perto
 * do ótimo em milissegundos, sem precisar de um solver mais pesado nem de
 * serviço externo.
 *
 * ## Formato de `$paradas`
 *
 * Array associativo por parada, montado por `RouteService`, sem um DTO
 * próprio: a Task 22.3 pede a criação de dois value objects
 * (`Coordenada` e `EstimativaDeDeslocamento`), e um terceiro tipo só para
 * empacotar estas cinco chaves não paga o custo de mais uma classe. Cada
 * item:
 *
 * - `work_order_id` (`?int`) e `compromisso_id` (`?int`): identificam a
 *   parada, exatamente um dos dois preenchido, nunca os dois, nunca nenhum
 *   (Plano 31, Task 31.2 - `RouteService` mescla OS e compromisso avulso
 *   como origem de parada). Este método é agnóstico ao conteúdo dessas duas
 *   chaves: só ecoa `$parada + [...]` sem lê-las, então continua
 *   funcionando sem nenhuma mudança de código aqui.
 * - `coordenada` (`?Coordenada`): `null` quando o endereço não tem
 *   coordenada.
 * - `precisao_geocodificacao` (`?string`): uma das constantes
 *   `ResultadoGeo::PRECISAO_*`, ou `null`.
 * - `ancorada` (`bool`): `true` quando a OS tem horário combinado com o
 *   cliente.
 * - `horario_ancora` (`?string`, formato `H:i:s`): obrigatório quando
 *   `ancorada` é `true`.
 *
 * O retorno tem o mesmo formato, na ordem final de visita, com uma chave a
 * mais: `sem_coordenada_boa` (`bool`), verdadeira nas paradas jogadas para o
 * fim do roteiro por falta de coordenada útil.
 *
 * ## Parada sem coordenada útil
 *
 * Endereço sem coordenada (`coordenada === null`) ou com precisão
 * `ResultadoGeo::PRECISAO_CIDADE` (o ponto está na cidade, não no endereço)
 * vai para o fim do roteiro, marcada, e não participa do cálculo de
 * otimização das demais - nem como ponto a visitar, nem como referência de
 * distância. Esta regra é verificada **antes** da regra de âncora e vale
 * até para uma parada âncora: uma OS com horário combinado, mas cujo
 * endereço não tem coordenada, também vai para o fim, marcada. As duas
 * regras da especificação da Task 22.3 estão escritas sem exceção uma da
 * outra, e a leitura literal evita inventar uma prioridade que a
 * especificação não define. Na prática é raro (o cadastro tem o horário
 * combinado, só falta a geocodificação do endereço), e quando acontece, o
 * compromisso de horário continua visível para quem lê o roteiro através do
 * próprio horário gravado na OS, só não entra no cálculo geométrico.
 *
 * ## Parada com horário combinado (âncora)
 *
 * OS com horário combinado nunca é reordenada pela otimização geométrica:
 * reordenar uma visita marcada com o cliente é pior do que rodar um
 * quilômetro a mais. A implementação faz isso em duas fases:
 *
 * 1. Otimiza (vizinho mais próximo + 2-opt) TODAS as paradas com coordenada
 *    boa juntas, âncoras inclusive, como se não houvesse âncora - isso
 *    define, para cada âncora, um "espaço" geometricamente coerente entre
 *    as paradas livres ao redor dela.
 * 2. Reordena só as âncoras ENTRE SI, por horário, mantendo cada uma no
 *    índice (posição) que a fase 1 já tinha reservado para alguma âncora,
 *    do mais cedo para o mais tarde. As paradas livres não se movem: elas
 *    já estavam otimizadas ao redor das âncoras na fase 1, e nenhuma delas
 *    troca de posição na fase 2.
 *
 * O resultado: cada âncora acaba na posição que corresponde à ordem
 * cronológica das âncoras entre si, e a otimização decide só a ordem do
 * resto ao redor delas - exatamente a regra da especificação.
 */
class OtimizadorDeRota
{
    public function __construct(
        private readonly EstimadorDeDeslocamento $estimador = new EstimadorDeDeslocamento,
    ) {}

    /**
     * `$partida` é `null` quando a coordenada da sede não é conhecida (ver
     * `RouteController::sedeCoordenada()`): a otimização segue normalmente,
     * só sem uma referência de "mais próxima da base" para a primeira
     * escolha - a sequência começa pela primeira parada da lista de entrada,
     * e o encadeamento por proximidade decide o resto a partir dali. Nunca
     * inventa um ponto `(0, 0)` ou qualquer outro valor de mentira: uma
     * partida desconhecida não tem coordenada nenhuma, mesmo critério já
     * usado em `Coordenada::apartirDe()` para endereço sem coordenada.
     *
     * @param  array<int, array{work_order_id: ?int, compromisso_id: ?int, coordenada: ?Coordenada, precisao_geocodificacao: ?string, ancorada: bool, horario_ancora: ?string}>  $paradas
     * @return array<int, array{work_order_id: ?int, compromisso_id: ?int, coordenada: ?Coordenada, precisao_geocodificacao: ?string, ancorada: bool, horario_ancora: ?string, sem_coordenada_boa: bool}>
     */
    public function ordenar(array $paradas, ?Coordenada $partida): array
    {
        [$comCoordenadaBoa, $semCoordenadaBoa] = $this->separarPorCoordenada($paradas);

        if ($comCoordenadaBoa === []) {
            return $this->marcarSemCoordenada($semCoordenadaBoa);
        }

        $sequencia = $this->vizinhoMaisProximo($comCoordenadaBoa, $partida);
        $sequencia = $this->melhorarComDoisOpt($sequencia, $partida);
        $sequencia = $this->fixarAncorasPorHorario($sequencia);

        $otimizadas = array_map(
            static fn (array $parada): array => $parada + ['sem_coordenada_boa' => false],
            $sequencia
        );

        return array_merge($otimizadas, $this->marcarSemCoordenada($semCoordenadaBoa));
    }

    /**
     * @param  array<int, array<string, mixed>>  $paradas
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function separarPorCoordenada(array $paradas): array
    {
        $comCoordenadaBoa = [];
        $semCoordenadaBoa = [];

        foreach ($paradas as $parada) {
            $temCoordenada = $parada['coordenada'] instanceof Coordenada;
            $precisaoUtil = $parada['precisao_geocodificacao'] !== ResultadoGeo::PRECISAO_CIDADE;

            if ($temCoordenada && $precisaoUtil) {
                $comCoordenadaBoa[] = $parada;
            } else {
                $semCoordenadaBoa[] = $parada;
            }
        }

        return [$comCoordenadaBoa, $semCoordenadaBoa];
    }

    /**
     * Constrói a sequência gulosa: a cada passo, escolhe entre as paradas
     * restantes a mais próxima da posição atual (a sede, na primeira
     * escolha, quando a coordenada dela é conhecida).
     *
     * Sem sede conhecida (`$partida === null`), não há "mais próxima da
     * base" para calcular: a primeira parada da sequência é a primeira da
     * lista de entrada (ordem estável, sem significado geográfico), e o
     * encadeamento por proximidade decide o resto a partir dali.
     *
     * @param  array<int, array<string, mixed>>  $paradas
     * @return array<int, array<string, mixed>>
     */
    private function vizinhoMaisProximo(array $paradas, ?Coordenada $partida): array
    {
        $restantes = $paradas;
        $sequencia = [];
        $atual = $partida;

        if ($atual === null) {
            $primeira = array_shift($restantes);
            $sequencia[] = $primeira;
            $atual = $primeira['coordenada'];
        }

        while ($restantes !== []) {
            $indiceMaisProximo = null;
            $menorDistancia = null;

            foreach ($restantes as $indice => $parada) {
                $distancia = $this->estimador->distanciaEmLinhaReta($atual, $parada['coordenada']);

                if ($menorDistancia === null || $distancia < $menorDistancia) {
                    $menorDistancia = $distancia;
                    $indiceMaisProximo = $indice;
                }
            }

            $escolhida = $restantes[$indiceMaisProximo];
            unset($restantes[$indiceMaisProximo]);

            $sequencia[] = $escolhida;
            $atual = $escolhida['coordenada'];
        }

        return $sequencia;
    }

    /**
     * Passada de melhoria 2-opt sobre um trajeto aberto (parte da sede, não
     * volta): repete a varredura de todos os pares de arestas trocáveis até
     * não achar mais troca que reduza a distância total, comparando só o
     * custo local de cada troca (quatro distâncias), não o trajeto inteiro.
     *
     * `$partida` pode ser `null` (sede sem coordenada conhecida): o custo da
     * aresta "base -> primeira parada" simplesmente não entra na comparação
     * nesse caso (mesmo tratamento já dado à aresta final inexistente,
     * `$proximo === null`), em vez de calcular distância contra um ponto
     * inventado.
     *
     * @param  array<int, array<string, mixed>>  $sequencia
     * @return array<int, array<string, mixed>>
     */
    private function melhorarComDoisOpt(array $sequencia, ?Coordenada $partida): array
    {
        $total = count($sequencia);

        if ($total < 3) {
            // Com uma ou duas paradas não existe par de arestas para trocar.
            return $sequencia;
        }

        $melhorou = true;

        while ($melhorou) {
            $melhorou = false;

            for ($i = 0; $i < $total - 1; $i++) {
                $anterior = $i === 0 ? $partida : $sequencia[$i - 1]['coordenada'];
                $a = $sequencia[$i]['coordenada'];

                for ($j = $i + 1; $j < $total; $j++) {
                    $b = $sequencia[$j]['coordenada'];
                    $proximo = $j === $total - 1 ? null : $sequencia[$j + 1]['coordenada'];

                    $custoAtual = ($anterior !== null ? $this->estimador->distanciaEmLinhaReta($anterior, $a) : 0.0)
                        + ($proximo !== null ? $this->estimador->distanciaEmLinhaReta($b, $proximo) : 0.0);

                    $custoTrocado = ($anterior !== null ? $this->estimador->distanciaEmLinhaReta($anterior, $b) : 0.0)
                        + ($proximo !== null ? $this->estimador->distanciaEmLinhaReta($a, $proximo) : 0.0);

                    // Margem pequena (1e-6) para não trocar por causa de
                    // erro de ponto flutuante quando o custo é essencialmente
                    // igual.
                    if ($custoTrocado < $custoAtual - 1e-6) {
                        $sequencia = [
                            ...array_slice($sequencia, 0, $i),
                            ...array_reverse(array_slice($sequencia, $i, $j - $i + 1)),
                            ...array_slice($sequencia, $j + 1),
                        ];

                        $a = $sequencia[$i]['coordenada'];
                        $melhorou = true;
                    }
                }
            }
        }

        return $sequencia;
    }

    /**
     * Reordena só as âncoras entre si, por horário, mantendo cada uma no
     * índice que a otimização geométrica já tinha reservado para alguma
     * âncora. Ver o cabeçalho da classe para o raciocínio completo.
     *
     * @param  array<int, array<string, mixed>>  $sequencia
     * @return array<int, array<string, mixed>>
     */
    private function fixarAncorasPorHorario(array $sequencia): array
    {
        $indicesDeAncoras = [];

        foreach ($sequencia as $indice => $parada) {
            if ($parada['ancorada']) {
                $indicesDeAncoras[] = $indice;
            }
        }

        if (count($indicesDeAncoras) < 2) {
            // Zero ou uma âncora: não há entre-si nenhum para reordenar.
            return $sequencia;
        }

        $ancorasEmOrdem = array_values(array_filter(
            $sequencia,
            static fn (array $parada): bool => $parada['ancorada']
        ));

        usort(
            $ancorasEmOrdem,
            static fn (array $a, array $b): int => $a['horario_ancora'] <=> $b['horario_ancora']
        );

        foreach ($indicesDeAncoras as $posicao => $indice) {
            $sequencia[$indice] = $ancorasEmOrdem[$posicao];
        }

        return $sequencia;
    }

    /**
     * @param  array<int, array<string, mixed>>  $paradas
     * @return array<int, array<string, mixed>>
     */
    private function marcarSemCoordenada(array $paradas): array
    {
        return array_values(array_map(
            static fn (array $parada): array => $parada + ['sem_coordenada_boa' => true],
            $paradas
        ));
    }
}
