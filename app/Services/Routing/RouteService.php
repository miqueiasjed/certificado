<?php

namespace App\Services\Routing;

use App\Models\Compromisso;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\Technician;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\BusinessDate;
use App\Support\Geo\Coordenada;
use App\Support\Geo\ResultadoGeo;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Monta o roteiro do dia de um técnico e calcula a chegada estimada em cada
 * parada (Plano 22, Task 22.3).
 *
 * Sem colisão de nome com a facade `Illuminate\Support\Facades\Route` neste
 * arquivo: nenhuma rota HTTP é declarada aqui, só o model `App\Models\Route`
 * é usado, então o `use App\Models\Route;` comum não precisa de alias (ver o
 * aviso completo no cabeçalho de `App\Models\Route`, relevante para quem for
 * expor endpoint de roteiro na Task 22.5).
 *
 * ## Onde fica o endereço da sede (ponto de partida)
 *
 * Verificado antes de escrever este arquivo: `App\Models\Company` tem
 * endereço em texto (`street`, `number`, `district`, `city`, `state`,
 * `zip`), sem `latitude`/`longitude` - diferente de `Address`, que ganhou as
 * duas colunas na Task 22.1. Duas saídas possíveis: geocodificar o endereço
 * da empresa dentro deste serviço (chamada de rede escondida num método que
 * a Task 22.3 descreve como "sem serviço externo nesta entrega", e fora do
 * escopo dos três arquivos que a task pede), ou adicionar
 * `companies.latitude/longitude` (migration nova, também fora do escopo:
 * a task pede exatamente `RouteService`, `OtimizadorDeRota` e
 * `EstimadorDeDeslocamento`).
 *
 * A decisão tomada: `montar()` recebe a coordenada da sede já resolvida, via
 * parâmetro `?Coordenada $partida`, NULÁVEL de propósito. Quem chama (o
 * endpoint da Task 22.5, ou um comando/job futuro) decide de onde ela vem, e
 * `RouteController::sedeCoordenada()` devolve `null` enquanto `companies` não
 * tiver coordenada própria - nunca um ponto inventado como `(0, 0)` (Golfo da
 * Guiné): isso corromperia `distancia_total_km`/`duracao_estimada_min` com um
 * trecho de milhares de quilômetros sem significado nenhum, o mesmo cuidado
 * que `Coordenada::apartirDe()` já tem para endereço sem coordenada. Sem
 * sede conhecida, a otimização e o cálculo de distância simplesmente tratam
 * a primeira parada como se não tivesse "trecho anterior" (mesma regra já
 * aplicada a uma parada sem coordenada boa) - `gravarParadas()`/
 * `recalcularDistancias()` guardam isso com `$anterior instanceof Coordenada`,
 * e `OtimizadorDeRota::vizinhoMaisProximo()`/`melhorarComDoisOpt()` tratam
 * `$partida === null` como "sem referência de base para a primeira escolha".
 * Uma task futura pode automatizar a resolução da sede (geocodificar o
 * endereço da empresa, ou adicionar as colunas em `companies`) sem mudar
 * esta assinatura além do valor que passa a chegar já resolvido.
 *
 * ## Como uma OS é reconhecida como "horário combinado com o cliente"
 *
 * Não existe um campo booleano dedicado. Verificado no schema e na tela de
 * OS (`WorkOrders/Create.vue`, `WorkOrders/Edit.vue`): `work_orders` tem
 * `scheduled_date` (a data, sempre exigida) e `start_time` (datetime,
 * nullable, rotulado "Horário de Início" no formulário). Uma OS pode ser
 * agendada só para o dia, sem hora (`start_time` nulo - "janela livre"), ou
 * com um horário específico marcado por quem agenda, tipicamente porque o
 * cliente só recebe naquele horário. `paradasDasOrdens()` usa exatamente
 * essa presença: `start_time` preenchido é o sinal de horário combinado
 * (parada âncora); `start_time` nulo é parada livre, otimizável.
 *
 * ## Duração média de visita
 *
 * Não existe no sistema nenhuma duração média histórica de OS por técnico ou
 * tipo de serviço já calculada e pronta para uso (`WorkOrder` tem
 * `duration_hours`/`duration_text`, calculados a partir de `start_time`/
 * `end_time` de UMA ordem, não uma média). Calcular essa média está fora do
 * escopo desta task. Decisão: `DURACAO_MEDIA_VISITA_PADRAO_MIN = 45`,
 * documentado aqui como valor fixo, com o parâmetro opcional de
 * `chegadaEstimada()` pronto para receber um valor melhor assim que uma task
 * futura o calcular.
 *
 * ## Horário de início do dia do técnico
 *
 * `Route` não tem essa coluna, e não existe hoje configuração de jornada por
 * técnico. Decisão: `HORARIO_INICIO_PADRAO = '08:00:00'`, com o mesmo
 * parâmetro opcional em `chegadaEstimada()` para quando essa informação
 * existir (por exemplo, na tela de roteiro da Task 22.6, preenchida pelo
 * próprio técnico ou pela empresa).
 *
 * ## Ordem manual (Task 22.5): o buraco de arquitetura entre esta classe e a
 * regra "a ordem manual prevalece"
 *
 * Até a Task 22.5, `montar()` sempre rodava o otimizador e sobrescrevia a
 * ordem de toda parada, mesmo de um roteiro já reordenado à mão pelo
 * coordenador (`PUT /roteiros/{id}/ordem`) - o que contradiz a regra de
 * negócio inegociável daquela task: "o sistema não pode desfazer a decisão
 * dele sozinho".
 *
 * A solução, com as três colunas novas de `routes` (migration
 * `add_ordenacao_manual_to_routes_table`, Task 22.5):
 *
 * - `montar()` ganhou o parâmetro opcional `$forcarReotimizacao` (default
 *   `false`). Quando encontra uma `Route` já existente para
 *   `[technician_id, data]` com `ordenacao_manual = true` e
 *   `$forcarReotimizacao` continua `false`, NÃO roda o otimizador sobre as
 *   paradas já ordenadas: só sincroniza o que mudou (`sincronizarPreservandoOrdemManual()`)
 *   - OS que saíram do dia são removidas, OS novas entram no FIM da lista,
 *   na ordem em que aparecem, sem tocar na posição das que já tinham ordem.
 *   Em qualquer outro caso (roteiro novo, ou roteiro existente sem ordenação
 *   manual, ou `$forcarReotimizacao = true`), o caminho é o de sempre: reroda
 *   o otimizador sobre tudo e grava `ordenacao_manual = false` (a ordem
 *   passou a ser, de novo, a do algoritmo).
 * - `POST /roteiros/otimizar` (`RouteController`) é a ÚNICA rota que chama
 *   `montar()` com `$forcarReotimizacao = true`: é a reotimização explícita
 *   que a regra de negócio exige para descartar uma ordem manual.
 * - `GET /roteiros` (`RouteController`) nunca chama `montar()` quando já
 *   existe uma `Route` para o dia/técnico pedido - devolve como está, sem
 *   tocar em nada. Só chama `montar()` (sem forçar) na primeira vez, quando
 *   o roteiro daquele dia ainda não existe. Isso já é suficiente para nunca
 *   desfazer ordem manual numa busca comum, mas `sincronizarPreservandoOrdemManual()`
 *   continua existindo e sendo exercitada por qualquer chamador futuro
 *   (comando, job) que resolva chamar `montar()` de novo sobre um roteiro já
 *   montado, sem forçar.
 * - `PUT /roteiros/{id}/ordem` grava a nova ordem via `reordenarManualmente()`
 *   desta classe, que marca `ordenacao_manual = true`,
 *   `reordenada_por`/`reordenada_em`, e recalcula distância/duração a partir
 *   da nova sequência (sem chamar o otimizador).
 *
 * ## Parada de compromisso avulso, ao lado de OS (Plano 31)
 *
 * Até a Task 31.2, toda parada só podia representar uma OS. O Plano 30 criou
 * o `Compromisso` avulso (visita de orçamento, garantia, retorno, sem OS por
 * trás), e o Plano 31 faz esta classe tratá-lo como uma segunda origem de
 * parada, ao lado de `WorkOrder`, em todo método que monta, sincroniza ou
 * recalcula o roteiro.
 *
 * O array de "parada" que circula dentro da classe (o que
 * `paradasDasOrdens()`/`paradasDosCompromissos()` produzem e que
 * `OtimizadorDeRota::ordenar()` consome e devolve) tem `work_order_id` e
 * `compromisso_id`, **exatamente um dos dois preenchido por parada, nunca os
 * dois, nunca nenhum**. Essa exclusividade é regra desta classe, nunca de
 * `CHECK` de banco (mesmo critério já registrado na migration
 * `add_compromisso_to_route_stops_table`). `OtimizadorDeRota::ordenar()` não
 * precisou mudar: ele só ecoa `$parada + [...]`, então a chave nova atravessa
 * sem que o otimizador precise conhecê-la.
 *
 * Só compromisso com `situacao = 'agendado'` entra no roteiro do dia
 * (`compromissosAgendadosDoDia()`): `concluido` já aconteceu, não precisa de
 * deslocamento a planejar; `cancelado` não vai acontecer - mesmo raciocínio
 * de `SITUACOES_AGENDADAS` para OS. Compromisso sem `address_id` (endereço
 * só em texto livre, `endereco_texto`) é tratado como "sem coordenada boa",
 * mesmo critério de OS com endereço não geocodificado: `paradasDosCompromissos()`
 * só lê `$compromisso->address`, nunca `endereco_texto` (esse campo é para
 * exibição, quando a Task 31.3 apresentar a parada, não para geocodificação).
 *
 * ### Contrato de identificação de parada em `reordenarManualmente()`
 *
 * Com duas origens possíveis, `work_order_id` sozinho deixou de identificar
 * toda parada do roteiro. `reordenarManualmente()` aceita uma string por
 * parada, no formato `"os:{id}"` ou `"compromisso:{id}"` (ex.: `"os:12"`,
 * `"compromisso:5"`) - o mesmo contrato que `PUT /roteiros/{route}/ordem`
 * expõe (`paradas: [string]` no `ReordenarRotaRequest`, Task 31.3), sem
 * duplicar a decisão. Não existe atalho para um item puramente numérico:
 * backend e frontend trocaram de contrato juntos dentro do Plano 31, sem
 * período de transição, então um item fora do formato `"tipo:id"` é
 * rejeitado antes mesmo de chegar aqui, pela regex do `FormRequest`.
 */
class RouteService
{
    /**
     * Situações de OS consideradas "agendada para o dia" na montagem do
     * roteiro. `completed`/`cancelled`/`on_hold` ficam de fora: uma OS
     * cancelada ou em espera não vai acontecer hoje, e uma já concluída não
     * precisa de deslocamento a planejar. `in_progress` entra porque
     * `montar()` pode ser chamado de novo no meio do dia, para reotimizar o
     * que falta.
     *
     * @var array<int, string>
     */
    private const SITUACOES_AGENDADAS = ['pending', 'scheduled', 'in_progress'];

    /**
     * Horário padrão de início do dia do técnico, quando `chegadaEstimada()`
     * não recebe um valor explícito. Ver o cabeçalho da classe.
     */
    public const HORARIO_INICIO_PADRAO = '08:00:00';

    /**
     * Duração média de visita, em minutos, quando `chegadaEstimada()` não
     * recebe um valor explícito. Ver o cabeçalho da classe.
     */
    public const DURACAO_MEDIA_VISITA_PADRAO_MIN = 45;

    public function __construct(
        private readonly OtimizadorDeRota $otimizador,
        private readonly EstimadorDeDeslocamento $estimador,
    ) {}

    /**
     * Monta (ou remonta) o roteiro do dia do técnico: busca as OS agendadas
     * nesse dia, ordena por proximidade a partir da sede (respeitando OS com
     * horário combinado) e grava a `Route` e as `RouteStop` correspondentes.
     *
     * Idempotente: rodar de novo no mesmo dia atualiza a `Route` existente
     * (unique `[company_id, technician_id, data]`), sincroniza as paradas
     * (adiciona as novas, atualiza a ordem das existentes, remove as que não
     * fazem mais parte do dia) e recalcula distância e duração totais.
     *
     * `$forcarReotimizacao` é a válvula de escape da Task 22.5 para a regra
     * "ordem manual prevalece": quando `false` (o padrão) e já existe uma
     * `Route` para `[technician_id, data]` com `ordenacao_manual = true`,
     * este método NÃO roda o otimizador de novo - delega a
     * `sincronizarPreservandoOrdemManual()`, que só adiciona/remove parada,
     * sem reordenar as que já tinham posição. `true` força o caminho de
     * sempre (reroda o otimizador sobre tudo, grava `ordenacao_manual =
     * false`); é o que `POST /roteiros/otimizar` usa, de propósito, porque
     * ali a reotimização foi pedida explicitamente por quem tem
     * `roteiro-gerenciar`. Ver o cabeçalho da classe para o raciocínio
     * completo.
     *
     * @throws InvalidArgumentException Data em formato inválido.
     */
    public function montar(Technician $tecnico, string $data, ?Coordenada $partida, bool $forcarReotimizacao = false): Route
    {
        $dia = $this->normalizarDia($data);

        $rotaExistente = Route::query()
            ->where('technician_id', $tecnico->id)
            ->where('data', $dia)
            ->first();

        if ($rotaExistente !== null && $rotaExistente->ordenacao_manual && ! $forcarReotimizacao) {
            return $this->sincronizarPreservandoOrdemManual($tecnico, $dia, $rotaExistente, $partida);
        }

        $ordens = $this->osAgendadasDoDia($tecnico, $dia);
        $compromissos = $this->compromissosAgendadosDoDia($tecnico, $dia);
        $paradas = array_merge($this->paradasDasOrdens($ordens), $this->paradasDosCompromissos($compromissos));
        $ordenadas = $this->otimizador->ordenar($paradas, $partida);

        return DB::transaction(function () use ($tecnico, $dia, $ordenadas, $partida): Route {
            $rota = Route::query()->updateOrCreate(
                [
                    'technician_id' => $tecnico->id,
                    'data' => $dia,
                ],
                // Sem alterar `situacao` aqui de propósito: remontar um
                // roteiro que já está `em_andamento` não pode voltar para
                // `planejada` só porque a otimização rodou de novo. Uma
                // `Route` nova nasce `planejada` pelo `default` da coluna.
                //
                // `ordenacao_manual = false` (e o par `reordenada_por`/
                // `reordenada_em` limpo) sempre que este caminho roda: ou o
                // roteiro é novo (já nasce `false` pelo `default` da coluna,
                // reafirmado aqui por clareza), ou uma reotimização explícita
                // (`$forcarReotimizacao = true`) descartou a ordem manual que
                // existia - Task 22.5.
                [
                    'ordenacao_manual' => false,
                    'reordenada_por' => null,
                    'reordenada_em' => null,
                ]
            );

            [$distanciaTotalKm, $duracaoTotalMin] = $this->gravarParadas($rota, $ordenadas, $partida);

            $rota->update([
                'distancia_total_km' => $distanciaTotalKm,
                'duracao_estimada_min' => $duracaoTotalMin,
                'otimizada_em' => now(),
            ]);

            return $rota->fresh('stops');
        });
    }

    /**
     * Compara a ordem atual do roteiro com a ordem que o otimizador
     * proporia, SEM gravar nada (Task 22.6, `POST /roteiros/simular`).
     *
     * ## Por que este método existe (buraco entre a Task 22.5 e a 22.6)
     *
     * A Task 22.5 só expôs `POST /roteiros/otimizar`, que recalcula E
     * PERSISTE a ordem de uma vez - correto para quem já decidiu otimizar,
     * mas não dá para a tela de roteiro (Task 22.6) satisfazer a regra de
     * negócio inegociável "otimizar mostra o ganho ANTES de aplicar,
     * aplicar é decisão do usuário" (o exemplo do enunciado da task é
     * literal: "Ordem atual: 47 km. Ordem otimizada: 31 km."). Chamar
     * `otimizar()` para depois comparar antes/depois mostraria a comparação
     * DEPOIS de já ter aplicado - não atende o critério.
     *
     * A escolha: um método de leitura pura, que roda o mesmo
     * `OtimizadorDeRota` sobre as OS do dia e soma a mesma distância que
     * `gravarParadas()` somaria, mas nunca chama `DB::transaction()` nem
     * `Route::updateOrCreate()`/`RouteStop::updateOrCreate()` - nenhuma
     * escrita acontece aqui. A alternativa descartada foi "otimizar e
     * desfazer se o usuário recusar" (rodar `montar(..., forcarReotimizacao:
     * true)` e reverter): significaria gravar no banco a ordem antiga só
     * para o usuário decidir jogar fora, dois `UPDATE` em cascata
     * (`routes` + N `route_stops`) para uma decisão que o usuário pode
     * recusar na hora seguinte - desnecessário quando o cálculo em si
     * (otimizador + soma de distâncias) não precisa de nenhuma escrita para
     * existir.
     *
     * `atual` vem da `Route` já persistida (a mesma que `RouteController::index()`
     * já monta/busca antes do usuário sequer ver o botão "Otimizar ordem" -
     * na prática sempre existe quando este método é chamado a partir da
     * tela). Sem `Route` ainda existente (uso avulso, ou dia sem nenhuma OS
     * agendada ainda visitado), `atual` volta com os totais nulos - o
     * frontend trata isso como "sem ordem atual para comparar", nunca como
     * "0 km".
     *
     * @return array{
     *     atual: array{distancia_total_km: ?float, duracao_estimada_min: ?int},
     *     otimizada: array{distancia_total_km: float, duracao_estimada_min: int, work_order_ids: array<int, ?int>}
     * }
     *
     * @throws InvalidArgumentException Data em formato inválido.
     */
    public function simularOtimizacao(Technician $tecnico, string $data, ?Coordenada $partida): array
    {
        $dia = $this->normalizarDia($data);

        $rotaAtual = Route::query()
            ->where('technician_id', $tecnico->id)
            ->where('data', $dia)
            ->first();

        $ordens = $this->osAgendadasDoDia($tecnico, $dia);
        $compromissos = $this->compromissosAgendadosDoDia($tecnico, $dia);
        $paradas = array_merge($this->paradasDasOrdens($ordens), $this->paradasDosCompromissos($compromissos));
        $ordenadas = $this->otimizador->ordenar($paradas, $partida);

        [$distanciaOtimizadaKm, $duracaoOtimizadaMin] = $this->calcularTotaisSemGravar($ordenadas, $partida);

        return [
            'atual' => [
                'distancia_total_km' => $rotaAtual?->distancia_total_km !== null ? (float) $rotaAtual->distancia_total_km : null,
                'duracao_estimada_min' => $rotaAtual?->duracao_estimada_min,
            ],
            'otimizada' => [
                'distancia_total_km' => $distanciaOtimizadaKm,
                'duracao_estimada_min' => $duracaoOtimizadaMin,
                // Nulo nesta lista identifica parada de compromisso avulso
                // (Plano 31): `work_order_id` sozinho não é mais garantia de
                // preencher todo item, porque `$ordenadas` agora mistura as
                // duas origens.
                'work_order_ids' => array_map(
                    static fn (array $parada): ?int => $parada['work_order_id'],
                    $ordenadas,
                ),
            ],
        ];
    }

    /**
     * Soma a distância/duração total de uma sequência de paradas já
     * ordenada, com a MESMA regra de trecho de `gravarParadas()` (parada sem
     * coordenada boa não conta como origem do próximo trecho, sede nula não
     * entra no primeiro trecho) - mas sem gravar nenhuma `RouteStop`. Único
     * consumidor: `simularOtimizacao()`, ver o docblock daquele método para
     * o motivo de existir separado de `gravarParadas()` em vez de
     * reaproveitá-lo (aquele grava, este só soma).
     *
     * @param  array<int, array{work_order_id: ?int, compromisso_id: ?int, coordenada: ?Coordenada, precisao_geocodificacao: ?string, ancorada: bool, horario_ancora: ?string}>  $ordenadas
     * @return array{0: float, 1: int} Distância total (km) e duração total (min).
     */
    private function calcularTotaisSemGravar(array $ordenadas, ?Coordenada $partida): array
    {
        $anterior = $partida;
        $distanciaTotalKm = 0.0;
        $duracaoTotalMin = 0;

        foreach ($ordenadas as $parada) {
            $coordenada = $parada['coordenada'];

            if ($coordenada instanceof Coordenada && $anterior instanceof Coordenada) {
                $estimativa = $this->estimador->estimar($anterior, $coordenada);

                $distanciaTotalKm += $estimativa->distanciaKm;
                $duracaoTotalMin += $estimativa->duracaoMin;
            }

            if ($coordenada instanceof Coordenada) {
                $anterior = $coordenada;
            }
        }

        return [round($distanciaTotalKm, 2), $duracaoTotalMin];
    }

    /**
     * Grava uma nova ordem manual para as paradas do roteiro (Task 22.5,
     * `PUT /roteiros/{id}/ordem`): marca `ordenacao_manual = true`, registra
     * quem e quando, e recalcula distância/duração a partir da nova
     * sequência - sem chamar o otimizador, porque a ordem já foi decidida por
     * quem está reordenando.
     *
     * `$paradasNaNovaOrdem` precisa conter exatamente as mesmas paradas
     * atuais do roteiro, só reordenadas: este método reordena paradas
     * existentes, não adiciona nem remove nenhuma (adicionar/remover é o
     * papel de `montar()`/`sincronizarPreservandoOrdemManual()`).
     *
     * ## Contrato de identificação de parada (Plano 31, ver cabeçalho da classe)
     *
     * Com `Compromisso` como segunda origem possível de parada, um
     * `work_order_id` sozinho não identifica mais toda parada do roteiro.
     * Cada item de `$paradasNaNovaOrdem` é uma string no formato `"os:{id}"`
     * ou `"compromisso:{id}"` - o mesmo contrato que `PUT /roteiros/{route}/ordem`
     * expõe (Task 31.3). Nenhum outro formato é aceito: sem período de
     * transição entre o contrato antigo (`work_order_ids: [int]`) e o novo,
     * backend e frontend trocaram juntos dentro deste plano.
     *
     * ## Bug fechado nesta task (22.6): `distancia_total_km` ficava parado
     *
     * Até esta task, o corpo deste método só atualizava `ordem` por parada e
     * `ordenacao_manual`/`reordenada_por`/`reordenada_em` na `Route` - nunca
     * chamava `recalcularDistancias()`, apesar do parágrafo acima (já escrito
     * desde a Task 22.5) afirmar que recalculava. Consequência: depois de
     * QUALQUER reordenação manual, `distancia_total_km`/`duracao_estimada_min`
     * do roteiro e `distancia_anterior_km`/`duracao_anterior_min` de cada
     * parada continuavam com o valor da última otimização automática, não da
     * ordem que a pessoa acabou de montar - exatamente os campos que a Task
     * 22.6 pede para mostrar na tela ("distância desde a anterior", "o total
     * de quilômetros") e a base da comparação "ordem atual vs. otimizada" do
     * botão "Otimizar ordem". Encontrado escrevendo o teste de integração de
     * `POST /roteiros/simular` (Task 22.6): a distância "atual" batia com a
     * "otimizada" mesmo depois de reordenar deliberadamente para uma
     * sequência pior, porque "atual" nunca tinha sido recalculada.
     *
     * `$partida` (a coordenada da sede, hoje sempre `null` - ver o cabeçalho
     * da classe) é opcional aqui porque `reordenarManualmente()` já existia
     * sem esse parâmetro antes desta correção; passar explicitamente (o
     * `RouteController::ordem()` já foi ajustado para isso) mantém a mesma
     * regra de "sem trecho sede -> primeira parada quando não há sede
     * conhecida" usada em todo o resto da classe.
     *
     * @param  array<int, string>  $paradasNaNovaOrdem
     *
     * @throws InvalidArgumentException A lista não bate com as paradas atuais do roteiro.
     */
    public function reordenarManualmente(Route $rota, array $paradasNaNovaOrdem, User $usuario, ?Coordenada $partida = null): Route
    {
        $paradas = $rota->stops()->get()->keyBy(fn (RouteStop $parada): string => $this->chaveDaParada($parada));

        $chavesInformadas = collect($paradasNaNovaOrdem)->map(fn (string $item): string => $this->normalizarChaveDeParada($item));
        $chavesAtuais = $paradas->keys();

        if ($chavesInformadas->count() !== $chavesAtuais->count() || $chavesInformadas->diff($chavesAtuais)->isNotEmpty() || $chavesAtuais->diff($chavesInformadas)->isNotEmpty()) {
            throw new InvalidArgumentException(
                'A lista de paradas informada precisa conter exatamente as mesmas paradas que já estão neste roteiro.'
            );
        }

        return DB::transaction(function () use ($rota, $chavesInformadas, $paradas, $usuario, $partida): Route {
            foreach ($chavesInformadas->values() as $indice => $chave) {
                $paradas[$chave]->update(['ordem' => $indice + 1]);
            }

            [$distanciaTotalKm, $duracaoTotalMin] = $this->recalcularDistancias($rota, $partida);

            $rota->update([
                'ordenacao_manual' => true,
                'reordenada_por' => $usuario->id,
                'reordenada_em' => now(),
                'distancia_total_km' => $distanciaTotalKm,
                'duracao_estimada_min' => $duracaoTotalMin,
            ]);

            return $rota->fresh('stops');
        });
    }

    /**
     * Chave que identifica uma `RouteStop` já gravada, independente da
     * origem (OS ou compromisso), no mesmo formato que
     * `normalizarChaveDeParada()` produz a partir do que
     * `reordenarManualmente()` recebe. Ver o docblock daquele método para o
     * contrato completo (Plano 31).
     */
    private function chaveDaParada(RouteStop $parada): string
    {
        return $parada->eDeCompromisso()
            ? "compromisso:{$parada->compromisso_id}"
            : "os:{$parada->work_order_id}";
    }

    /**
     * Normaliza um item de `reordenarManualmente()` para a chave interna
     * `"os:{id}"`/`"compromisso:{id}"`. Sem atalho para valor puramente
     * numérico (removido na Task 31.3, junto da troca do contrato do
     * endpoint): o `FormRequest` já exige sempre o formato completo
     * `"tipo:id"` antes de o valor chegar aqui, então este método só existe
     * para manter num só lugar a conversão "item recebido -> chave interna",
     * o mesmo formato que `chaveDaParada()` produz a partir de uma
     * `RouteStop` já gravada.
     */
    private function normalizarChaveDeParada(string $item): string
    {
        return $item;
    }

    /**
     * Caminho de `montar()` quando o roteiro já existe e está com
     * `ordenacao_manual = true`: preserva a ordem das paradas já gravadas e
     * só sincroniza o que mudou no dia - OS/compromisso que saiu (cancelado,
     * remarcado para outro técnico/dia, ou compromisso cancelado) é
     * removido, OS/compromisso novo entra no FIM da lista, na ordem em que
     * aparece (por id, OS primeiro e depois compromisso), sem embaralhar as
     * demais.
     *
     * Não chama `OtimizadorDeRota::ordenar()`: rodar o otimizador, mesmo só
     * sobre as paradas novas, exigiria decidir onde encaixá-las no meio da
     * ordem manual - e qualquer encaixe automático é exatamente o tipo de
     * decisão que a Task 22.5 tira do sistema e deixa para quem reordenou à
     * mão. "No fim da lista" é a única posição que nunca contradiz uma ordem
     * manual existente.
     */
    private function sincronizarPreservandoOrdemManual(Technician $tecnico, string $dia, Route $rota, ?Coordenada $partida): Route
    {
        $ordens = $this->osAgendadasDoDia($tecnico, $dia)->keyBy('id');
        $compromissos = $this->compromissosAgendadosDoDia($tecnico, $dia)->keyBy('id');
        $paradasExistentes = $rota->stops()->orderBy('ordem')->get();

        return DB::transaction(function () use ($rota, $ordens, $compromissos, $paradasExistentes, $partida): Route {
            // Uma parada ainda pertence ao dia quando a origem dela (OS ou
            // compromisso) ainda está entre as agendadas para o dia -
            // qualquer que seja a origem da parada.
            $aindaNoDia = fn (RouteStop $parada): bool => $parada->eDeCompromisso()
                ? $compromissos->has($parada->compromisso_id)
                : $ordens->has($parada->work_order_id);

            // Remove parada cuja OS ou compromisso saiu do dia.
            $paradasExistentes
                ->reject($aindaNoDia)
                ->each(fn (RouteStop $parada) => $parada->delete());

            $mantidas = $paradasExistentes->filter($aindaNoDia);
            $proximaOrdem = ((int) $mantidas->max('ordem')) + 1;

            // OS nova (sem parada correspondente ainda) entra no fim, na
            // ordem em que `osAgendadasDoDia()` devolveu (por id).
            $workOrderIdsJaNaRota = $mantidas->pluck('work_order_id')->filter()->all();

            foreach ($ordens as $ordem) {
                if (in_array($ordem->id, $workOrderIdsJaNaRota, true)) {
                    continue;
                }

                RouteStop::query()->create([
                    'route_id' => $rota->id,
                    'work_order_id' => $ordem->id,
                    'compromisso_id' => null,
                    'ordem' => $proximaOrdem,
                ]);

                $proximaOrdem++;
            }

            // Compromisso novo, mesma lógica, depois das OS novas.
            $compromissoIdsJaNaRota = $mantidas->pluck('compromisso_id')->filter()->all();

            foreach ($compromissos as $compromisso) {
                if (in_array($compromisso->id, $compromissoIdsJaNaRota, true)) {
                    continue;
                }

                RouteStop::query()->create([
                    'route_id' => $rota->id,
                    'work_order_id' => null,
                    'compromisso_id' => $compromisso->id,
                    'ordem' => $proximaOrdem,
                ]);

                $proximaOrdem++;
            }

            [$distanciaTotalKm, $duracaoTotalMin] = $this->recalcularDistancias($rota, $partida);

            $rota->update([
                'distancia_total_km' => $distanciaTotalKm,
                'duracao_estimada_min' => $duracaoTotalMin,
            ]);

            return $rota->fresh('stops');
        });
    }

    /**
     * Recalcula `distancia_anterior_km`/`duracao_anterior_min` de cada
     * parada e os totais do roteiro, seguindo a ordem JÁ GRAVADA em
     * `RouteStop::ordem` (nunca decide ordem, só mede o trajeto que ela já
     * descreve). Usado depois de reordenar manualmente ou sincronizar paradas
     * preservando ordem manual - os dois casos em que a ordem final não veio
     * de `OtimizadorDeRota`.
     *
     * @return array{0: float, 1: int} Distância total (km) e duração total (min).
     */
    private function recalcularDistancias(Route $rota, ?Coordenada $partida): array
    {
        $paradas = $rota->stops()->with(['workOrder.address', 'compromisso.address'])->orderBy('ordem')->get();

        $anterior = $partida;
        $distanciaTotalKm = 0.0;
        $duracaoTotalMin = 0;

        foreach ($paradas as $parada) {
            $endereco = $parada->eDeCompromisso() ? $parada->compromisso?->address : $parada->workOrder?->address;
            $coordenada = Coordenada::apartirDe($endereco?->latitude, $endereco?->longitude);
            $coordenadaUtil = $coordenada instanceof Coordenada
                && $endereco?->precisao_geocodificacao !== ResultadoGeo::PRECISAO_CIDADE;

            $distanciaAnteriorKm = null;
            $duracaoAnteriorMin = null;

            if ($coordenadaUtil && $anterior instanceof Coordenada) {
                $estimativa = $this->estimador->estimar($anterior, $coordenada);

                $distanciaAnteriorKm = $estimativa->distanciaKm;
                $duracaoAnteriorMin = $estimativa->duracaoMin;

                $distanciaTotalKm += $estimativa->distanciaKm;
                $duracaoTotalMin += $estimativa->duracaoMin;
            }

            $parada->update([
                'distancia_anterior_km' => $distanciaAnteriorKm,
                'duracao_anterior_min' => $duracaoAnteriorMin,
            ]);

            if ($coordenadaUtil) {
                $anterior = $coordenada;
            }
        }

        return [round($distanciaTotalKm, 2), $duracaoTotalMin];
    }

    /**
     * Calcula e grava a chegada estimada em cada parada do roteiro, a partir
     * do horário de início do técnico, somando o deslocamento estimado
     * (já gravado por `montar()` em `distancia_anterior_km`/
     * `duracao_anterior_min`) e a duração média de visita.
     *
     * Parada com horário combinado (âncora) não acumula o relógio a partir
     * da estimativa das paradas anteriores: a chegada estimada dela É o
     * horário combinado, porque é o compromisso que o técnico tem com o
     * cliente. O relógio da simulação "realinha" nesse ponto, e as paradas
     * seguintes somam a partir dali - assim como aconteceria na prática.
     *
     * @throws InvalidArgumentException `$horarioInicioTecnico` em formato inválido.
     */
    public function chegadaEstimada(
        Route $rota,
        ?string $horarioInicioTecnico = null,
        ?int $duracaoMediaVisitaMin = null,
    ): Route {
        $duracaoVisitaMin = $duracaoMediaVisitaMin ?? self::DURACAO_MEDIA_VISITA_PADRAO_MIN;
        $horarioInicio = $horarioInicioTecnico ?? self::HORARIO_INICIO_PADRAO;

        $dia = $rota->data->toDateString();
        $instanteAtual = BusinessDate::paraFusoNegocio("{$dia} {$horarioInicio}");

        if ($instanteAtual === null) {
            throw new InvalidArgumentException("Horário de início inválido: \"{$horarioInicio}\".");
        }

        $paradas = $rota->stops()->with(['workOrder', 'compromisso'])->get();

        foreach ($paradas as $parada) {
            $horarioCombinado = $parada->eDeCompromisso() ? $parada->compromisso?->hora_inicio : $parada->workOrder?->start_time;

            if (filled($horarioCombinado)) {
                $instanteAtual = BusinessDate::paraFusoNegocio($horarioCombinado);
            } elseif ($parada->duracao_anterior_min !== null) {
                $instanteAtual = $instanteAtual->addMinutes($parada->duracao_anterior_min);
            }

            $parada->update(['chegada_estimada' => $instanteAtual->format('H:i:s')]);

            $instanteAtual = $instanteAtual->addMinutes($duracaoVisitaMin);
        }

        return $rota->fresh('stops');
    }

    /**
     * Normaliza `$data` para `Y-m-d` no fuso do negócio, via `BusinessDate`
     * (Plano 22 exige "o dia é o de Brasília").
     *
     * @throws InvalidArgumentException
     */
    private function normalizarDia(string $data): string
    {
        $dia = BusinessDate::paraFusoNegocio($data);

        if ($dia === null) {
            throw new InvalidArgumentException("Data inválida para montar o roteiro: \"{$data}\".");
        }

        return $dia->toDateString();
    }

    /**
     * OS do técnico agendadas para o dia informado, com o endereço já
     * carregado (evita N+1 ao montar as paradas).
     *
     * @return Collection<int, WorkOrder>
     */
    private function osAgendadasDoDia(Technician $tecnico, string $dia): Collection
    {
        return WorkOrder::query()
            ->with('address')
            ->where('technician_id', $tecnico->id)
            // `scheduled_date` é `date`, sem hora: comparação direta de dia,
            // sem conversão de fuso (regra de `datas-timezone`).
            ->whereDate('scheduled_date', $dia)
            ->whereIn('status', self::SITUACOES_AGENDADAS)
            ->active()
            ->orderBy('id')
            ->get();
    }

    /**
     * Compromissos avulsos do técnico agendados para o dia informado, com o
     * endereço já carregado (evita N+1 ao montar as paradas). Só
     * `situacao = 'agendado'` entra: `concluido` já aconteceu, não precisa
     * de deslocamento a planejar; `cancelado` não vai acontecer - mesmo
     * raciocínio de `SITUACOES_AGENDADAS` para OS, adaptado ao catálogo de
     * `Compromisso::SITUACOES`.
     *
     * @return Collection<int, Compromisso>
     */
    private function compromissosAgendadosDoDia(Technician $tecnico, string $dia): Collection
    {
        return Compromisso::query()
            ->with('address')
            ->where('technician_id', $tecnico->id)
            // `data` é `date`, sem hora: mesma comparação direta de dia de
            // `osAgendadasDoDia()` (regra de `datas-timezone`).
            ->whereDate('data', $dia)
            ->where('situacao', 'agendado')
            ->orderBy('id')
            ->get();
    }

    /**
     * Converte cada OS numa "parada" no formato que `OtimizadorDeRota`
     * espera. Ver o cabeçalho de `OtimizadorDeRota::ordenar()` para o
     * formato exato.
     *
     * @param  Collection<int, WorkOrder>  $ordens
     * @return array<int, array{work_order_id: ?int, compromisso_id: ?int, coordenada: ?Coordenada, precisao_geocodificacao: ?string, ancorada: bool, horario_ancora: ?string}>
     */
    private function paradasDasOrdens(Collection $ordens): array
    {
        return $ordens->map(function (WorkOrder $ordem): array {
            $endereco = $ordem->address;
            $horarioCombinado = $ordem->start_time;

            return [
                'work_order_id' => $ordem->id,
                'compromisso_id' => null,
                'coordenada' => Coordenada::apartirDe($endereco?->latitude, $endereco?->longitude),
                'precisao_geocodificacao' => $endereco?->precisao_geocodificacao,
                'ancorada' => filled($horarioCombinado),
                'horario_ancora' => filled($horarioCombinado)
                    ? BusinessDate::paraFusoNegocio($horarioCombinado)?->format('H:i:s')
                    : null,
            ];
        })->all();
    }

    /**
     * Converte cada compromisso avulso numa "parada", irmã de
     * `paradasDasOrdens()` para a origem `Compromisso` (Plano 31). Mesmo
     * formato de saída, e o mesmo critério de "horário combinado": presença
     * de `hora_inicio` é o sinal de parada âncora, equivalente a
     * `$ordem->start_time` para OS (ver o cabeçalho da classe).
     *
     * Lê `$compromisso->address`, nunca `endereco_texto`: compromisso sem
     * `address_id` não tem coordenada, mesmo tratamento que OS sem endereço
     * geocodificado já recebe em `paradasDasOrdens()`.
     *
     * @param  Collection<int, Compromisso>  $compromissos
     * @return array<int, array{work_order_id: ?int, compromisso_id: ?int, coordenada: ?Coordenada, precisao_geocodificacao: ?string, ancorada: bool, horario_ancora: ?string}>
     */
    private function paradasDosCompromissos(Collection $compromissos): array
    {
        return $compromissos->map(function (Compromisso $compromisso): array {
            $endereco = $compromisso->address;
            $horarioCombinado = $compromisso->hora_inicio;

            return [
                'work_order_id' => null,
                'compromisso_id' => $compromisso->id,
                'coordenada' => Coordenada::apartirDe($endereco?->latitude, $endereco?->longitude),
                'precisao_geocodificacao' => $endereco?->precisao_geocodificacao,
                'ancorada' => filled($horarioCombinado),
                'horario_ancora' => filled($horarioCombinado)
                    ? BusinessDate::paraFusoNegocio($horarioCombinado)?->format('H:i:s')
                    : null,
            ];
        })->all();
    }

    /**
     * Grava uma `RouteStop` por parada, na ordem final decidida pelo
     * otimizador, com a distância/duração do trecho vindo da parada anterior
     * (ou da sede, na primeira). Remove paradas que sobraram de uma
     * montagem anterior e não fazem mais parte do dia (OS ou compromisso
     * cancelado, remarcado para outro técnico ou outro dia, etc.).
     *
     * @param  array<int, array{work_order_id: ?int, compromisso_id: ?int, coordenada: ?Coordenada, precisao_geocodificacao: ?string, ancorada: bool, horario_ancora: ?string, sem_coordenada_boa: bool}>  $ordenadas
     * @return array{0: float, 1: int} Distância total (km) e duração total (min).
     */
    private function gravarParadas(Route $rota, array $ordenadas, ?Coordenada $partida): array
    {
        $anterior = $partida;
        $distanciaTotalKm = 0.0;
        $duracaoTotalMin = 0;
        $ordem = 1;
        $idsMantidos = [];

        foreach ($ordenadas as $parada) {
            $coordenada = $parada['coordenada'];
            $distanciaAnteriorKm = null;
            $duracaoAnteriorMin = null;

            if ($coordenada instanceof Coordenada && $anterior instanceof Coordenada) {
                $estimativa = $this->estimador->estimar($anterior, $coordenada);

                $distanciaAnteriorKm = $estimativa->distanciaKm;
                $duracaoAnteriorMin = $estimativa->duracaoMin;

                $distanciaTotalKm += $estimativa->distanciaKm;
                $duracaoTotalMin += $estimativa->duracaoMin;
            }

            // Chave composta pelas duas colunas: como exatamente uma delas é
            // sempre `null` por parada, o Eloquent traduz `null` em
            // `whereNull` e a busca encontra a linha certa nos dois casos
            // (parada de OS ou de compromisso).
            $stop = RouteStop::query()->updateOrCreate(
                [
                    'route_id' => $rota->id,
                    'work_order_id' => $parada['work_order_id'],
                    'compromisso_id' => $parada['compromisso_id'],
                ],
                [
                    'ordem' => $ordem,
                    'distancia_anterior_km' => $distanciaAnteriorKm,
                    'duracao_anterior_min' => $duracaoAnteriorMin,
                ]
            );

            $idsMantidos[] = $stop->id;

            // Só avança a origem do próximo trecho quando a parada atual tem
            // coordenada: uma parada sem coordenada boa (fim do roteiro, já
            // marcada por `OtimizadorDeRota`) não pode servir de referência
            // para calcular a distância da parada seguinte.
            if ($coordenada instanceof Coordenada) {
                $anterior = $coordenada;
            }

            $ordem++;
        }

        RouteStop::query()
            ->where('route_id', $rota->id)
            ->whereNotIn('id', $idsMantidos)
            ->delete();

        return [round($distanciaTotalKm, 2), $duracaoTotalMin];
    }
}
