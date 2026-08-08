<?php

namespace App\Services\Routing;

use App\Support\Geo\Coordenada;
use App\Support\Routing\EstimativaDeDeslocamento;

/**
 * Estima distância e duração de deslocamento entre duas coordenadas, sem
 * chamar serviço externo de rota (Plano 22, Task 22.3).
 *
 * ## Por que sem serviço externo
 *
 * Decisão de custo já registrada em `.claude/tasks/22/INDEX.md`: o custo por
 * requisição de um provedor de rotas (Google Directions, Mapbox etc.),
 * multiplicado por técnico e por dia, não se justifica com o ganho sobre a
 * linha reta corrigida - o caso real do plano é até 15 paradas por técnico
 * por dia.
 *
 * ## A fórmula
 *
 * 1. Distância em linha reta entre as duas coordenadas pela fórmula de
 *    Haversine (padrão para distância entre dois pontos numa esfera).
 * 2. Multiplicada por `FATOR_CORRECAO_VIARIA` (1,3): nenhuma via é reta, o
 *    fator aproxima o percurso real de área urbana sem depender de um
 *    provedor de rotas.
 * 3. Duração = distância corrigida dividida pela velocidade média.
 *
 * ## "Velocidade média configurável por tenant" - o que existe hoje
 *
 * Não existe, hoje, nenhuma coluna para isso. Conferido antes de escrever
 * este arquivo: `company_availability_settings` (Plano 10, Task 16.2) é a
 * única tabela de configuração por empresa relacionada a agendamento/rota, e
 * guarda antecedência mínima, janela máxima, dias de atendimento e teto de
 * visita por período - nada sobre velocidade de deslocamento. `Technician`
 * também não tem nada equivalente (só `custo_hora`, do Plano 18).
 *
 * Criar uma coluna nova está fora do escopo desta task, que pede exatamente
 * os três arquivos de serviço listados na Task 22.3 (`RouteService`,
 * `OtimizadorDeRota`, `EstimadorDeDeslocamento`). A decisão tomada aqui: o
 * padrão do sistema (`VELOCIDADE_MEDIA_PADRAO_KMH = 30`, o valor pedido pela
 * especificação) fica embutido nesta classe, mas o construtor aceita um
 * valor já resolvido por quem chama. Assim, quando uma task futura guardar
 * essa configuração em algum lugar (o candidato mais provável continua sendo
 * uma coluna nova em `company_availability_settings`), a mudança fica
 * inteira na chamadora - esta classe não precisa mudar uma linha.
 *
 * ## O resultado nunca é apresentado como medição exata
 *
 * `estimar()` devolve `EstimativaDeDeslocamento`, que carrega
 * `estimativa: true` sempre. Ver o cabeçalho daquela classe.
 */
class EstimadorDeDeslocamento
{
    /**
     * Fator de correção viário para área urbana, pedido pela especificação
     * da Task 22.3: nenhuma via é reta, então a distância em linha reta
     * subestima o percurso real percorrido de carro/moto.
     */
    private const FATOR_CORRECAO_VIARIA = 1.3;

    /**
     * Velocidade média padrão em cidade, em km/h, usada quando quem chama
     * não informar uma velocidade por tenant já resolvida. Ver o cabeçalho
     * da classe para o raciocínio completo sobre a ausência de configuração
     * por tenant hoje.
     */
    public const VELOCIDADE_MEDIA_PADRAO_KMH = 30;

    /**
     * Raio médio da Terra em quilômetros, usado na fórmula de Haversine.
     */
    private const RAIO_DA_TERRA_KM = 6371.0;

    public function __construct(
        private readonly float $velocidadeMediaKmh = self::VELOCIDADE_MEDIA_PADRAO_KMH,
    ) {}

    /**
     * Distância e duração estimadas entre duas coordenadas. Duas coordenadas
     * iguais devolvem distância e duração zero, sem lançar exceção.
     */
    public function estimar(Coordenada $de, Coordenada $para): EstimativaDeDeslocamento
    {
        $distanciaKm = $this->distanciaEmLinhaReta($de, $para) * self::FATOR_CORRECAO_VIARIA;

        $duracaoMin = $this->velocidadeMediaKmh > 0
            ? (int) round(($distanciaKm / $this->velocidadeMediaKmh) * 60)
            : 0;

        return new EstimativaDeDeslocamento(
            distanciaKm: round($distanciaKm, 2),
            duracaoMin: $duracaoMin,
            estimativa: true,
        );
    }

    /**
     * Distância em linha reta (Haversine), em quilômetros, SEM o fator de
     * correção viário.
     *
     * Uso interno de `estimar()`, e também usado diretamente por
     * `OtimizadorDeRota` para decidir a parada mais próxima: o fator de
     * correção multiplica toda aresta igualmente, então não muda qual
     * parada é a mais próxima de outra, e pular a multiplicação nas
     * comparações do otimizador economiza uma operação de ponto flutuante
     * por comparação sem mudar o resultado.
     */
    public function distanciaEmLinhaReta(Coordenada $de, Coordenada $para): float
    {
        $latDe = deg2rad($de->latitude);
        $latPara = deg2rad($para->latitude);
        $deltaLat = deg2rad($para->latitude - $de->latitude);
        $deltaLng = deg2rad($para->longitude - $de->longitude);

        $a = sin($deltaLat / 2) ** 2
            + cos($latDe) * cos($latPara) * sin($deltaLng / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return self::RAIO_DA_TERRA_KM * $c;
    }
}
