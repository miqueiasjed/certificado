<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceReplacement;
use App\Models\User;
use App\Support\BusinessDate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Troca de um dispositivo por outro no mesmo ponto de monitoramento e leitura
 * da linha do tempo desse ponto.
 *
 * O ponto de monitoramento é o que a fiscalização enxerga: "o que aconteceu
 * atrás da geladeira da cozinha nos últimos dois anos". O dispositivo é o
 * objeto físico que ocupa aquele ponto, e ele quebra, some e é trocado de tipo.
 * Editar o dispositivo antigo para virar o novo apagaria a troca; apagar o
 * antigo apagaria os eventos dele. Por isso a substituição cria um registro
 * novo, preserva o antigo inteiro e liga os dois por uma linha em
 * `device_replacements`.
 *
 * Três decisões que sustentam o resto:
 *
 * - **Nenhum evento muda de dono.** Um evento de consumo de isca pertence ao
 *   dispositivo que estava no ponto naquele dia. Mover os eventos do antigo
 *   para o novo produziria um documento que afirma que um dispositivo registrou
 *   ocorrências antes de existir, e isso é falsificação de histórico. A
 *   continuidade do ponto vem da cadeia lida por `historicoDoPonto()`, nunca de
 *   mexer no `device_id` de um evento já gravado.
 * - **O código público do novo é sempre novo.** O `creating` do `Device` gera um
 *   código inédito quando ele não vem informado, e este Service não informa.
 *   Reaproveitar o código do anterior faria a etiqueta velha, que pode continuar
 *   colada em algum canto do imóvel, apontar para o registro errado na leitura
 *   de QR.
 * - **Só o dispositivo atual do ponto pode ser substituído.** Aceitar uma
 *   segunda troca partindo do mesmo dispositivo anterior transformaria a cadeia
 *   linear em árvore, e o histórico do ponto deixaria de ter uma resposta única.
 *
 * Nenhum método aqui lê o usuário autenticado: o autor chega como parâmetro,
 * vindo do controller.
 */
class DeviceReplacementService
{
    /**
     * Situação do dispositivo que saiu do ponto. Espelha o enum da coluna
     * `devices.situacao`.
     */
    public const SITUACAO_SUBSTITUIDO = 'substituido';

    /**
     * Situação com que o dispositivo novo nasce.
     */
    public const SITUACAO_ATIVA = 'ativo';

    /**
     * Recusa de trocar um dispositivo que já saiu do ponto. Fica em constante
     * porque o teste da Task 11.9 e a tela da Task 11.8 apontam para a mesma
     * frase.
     */
    public const MENSAGEM_JA_SUBSTITUIDO = 'Este dispositivo já foi substituído. Para trocar de novo, substitua o dispositivo atual do ponto.';

    /**
     * Teto de elos percorridos em cada direção da cadeia.
     *
     * A cadeia é linear por construção: `substituicaoDeOrigem` e
     * `substituicaoDeDestino` são `hasOne`, e `substituir()` recusa a segunda
     * troca do mesmo anterior. O teto existe para o caso que a construção não
     * cobre, que é dado inconsistente entrando por importação ou por correção
     * manual no banco: sem ele, um ciclo A -> B -> A viraria laço infinito na
     * tela do dispositivo. Quinhentas trocas no mesmo ponto é um número que a
     * operação real não alcança.
     */
    private const MAXIMO_DE_ELOS = 500;

    /**
     * Campos que o dispositivo novo herda do anterior.
     *
     * São os que descrevem o ponto, e não o objeto: endereço, rótulo, número e
     * observação de onde ele fica. O tipo de isca entra aqui porque a troca
     * costuma manter o mesmo, e o motivo `troca_de_tipo` existe justamente para
     * o caso em que muda.
     *
     * `codigo_publico` está fora de propósito: ver o cabeçalho da classe.
     *
     * @var array<int, string>
     */
    private const CAMPOS_HERDADOS = [
        'address_id',
        'label',
        'number',
        'bait_type_id',
        'default_location_note',
    ];

    /**
     * Substitui o dispositivo do ponto por um novo.
     *
     * O que acontece, em uma transação só: o dispositivo novo é criado
     * herdando os campos do anterior (sobrescritos pelo que vier em `$dados`),
     * o anterior é marcado como `substituido` e inativo, e a linha da troca é
     * gravada com motivo, dia e autor. Falha em qualquer etapa desfaz tudo:
     * ponto com dois dispositivos ativos, ou com um dispositivo novo solto sem
     * a linha que o liga ao anterior, é pior que a troca não ter acontecido.
     *
     * `$dados` aceita `motivo`, `observacao`, `substituido_em` e os campos do
     * dispositivo novo listados em `CAMPOS_HERDADOS`. Campo obrigatório só é
     * sobrescrito quando vem preenchido; `default_location_note` pode ser
     * esvaziado de propósito, porque o ponto pode ter mudado de lugar na troca.
     *
     * Sem `substituido_em` informado, o dia é hoje no fuso do negócio.
     *
     * @param  array<string, mixed>  $dados
     * @return array{success: bool, message: string, data: array{anterior: ?Device, novo: ?Device, substituicao: ?DeviceReplacement}}
     */
    public function substituir(Device $anterior, array $dados, ?User $autor = null): array
    {
        return DB::transaction(function () use ($anterior, $dados, $autor): array {
            // Trava a linha do dispositivo que sai antes de decidir qualquer
            // coisa. Dois cliques no mesmo botão, ou dois técnicos no mesmo
            // ponto, passariam os dois pela checagem e criariam dois
            // dispositivos novos para o mesmo anterior. Com a trava, a segunda
            // requisição só reconfere depois que a primeira gravou, e cai na
            // recusa em vez de bifurcar a cadeia.
            $emBanco = Device::query()
                ->whereKey($anterior->getKey())
                ->lockForUpdate()
                ->first() ?? $anterior;

            if ($this->jaFoiSubstituido($emBanco)) {
                return $this->recusa(self::MENSAGEM_JA_SUBSTITUIDO);
            }

            $novo = Device::query()->create($this->atributosDoDispositivoNovo($emBanco, $dados));

            // O anterior sai de circulação, mas continua no banco com os
            // eventos que gerou: ele é o histórico daquele ponto.
            $anterior->update([
                'situacao' => self::SITUACAO_SUBSTITUIDO,
                'active' => false,
            ]);

            $substituicao = DeviceReplacement::query()->create([
                'device_anterior_id' => $anterior->getKey(),
                'device_novo_id' => $novo->getKey(),
                'motivo' => $dados['motivo'] ?? null,
                'observacao' => $this->observacao($dados),
                'substituido_em' => $this->diaDaTroca($dados),
                'user_id' => $autor?->getKey(),
            ]);

            return [
                'success' => true,
                'message' => sprintf(
                    'Dispositivo substituído. O novo recebeu o código %s, e o histórico do anterior continua registrado neste ponto.',
                    (string) $novo->codigo_publico
                ),
                'data' => [
                    'anterior' => $anterior,
                    'novo' => $novo,
                    'substituicao' => $substituicao,
                ],
            ];
        });
    }

    /**
     * Todos os dispositivos que já ocuparam o ponto, do mais antigo ao atual.
     *
     * Parte de qualquer elo da cadeia: sobe por `substituicaoDeOrigem` até o
     * primeiro dispositivo instalado ali e desce por `substituicaoDeDestino`
     * até o que está no ponto hoje. Chamar com o dispositivo do meio, com o
     * primeiro ou com o último devolve exatamente a mesma lista, porque é a
     * mesma cadeia lida de pontos de partida diferentes.
     *
     * Dispositivo sem nenhuma substituição devolve uma coleção com ele mesmo:
     * um ponto instalado do zero e nunca trocado também tem histórico, e ele é
     * de um dispositivo só.
     *
     * A varredura guarda os ids já vistos e respeita `MAXIMO_DE_ELOS` nas duas
     * direções. A cadeia é linear por construção, mas laço que percorre banco
     * sem trava de parada é o tipo de coisa que só aparece em produção, no dia
     * em que alguém corrigir uma linha na mão.
     *
     * @return Collection<int, Device>
     */
    public function historicoDoPonto(Device $device): Collection
    {
        $vistos = [$device->getKey() => true];

        $anteriores = [];
        $atual = $device;

        for ($elo = 0; $elo < self::MAXIMO_DE_ELOS; $elo++) {
            $anterior = $atual->substituicaoDeOrigem()->first()?->deviceAnterior;

            if ($anterior === null || isset($vistos[$anterior->getKey()])) {
                break;
            }

            $vistos[$anterior->getKey()] = true;
            $anteriores[] = $anterior;
            $atual = $anterior;
        }

        $posteriores = [];
        $atual = $device;

        for ($elo = 0; $elo < self::MAXIMO_DE_ELOS; $elo++) {
            $novo = $atual->substituicaoDeDestino()->first()?->deviceNovo;

            if ($novo === null || isset($vistos[$novo->getKey()])) {
                break;
            }

            $vistos[$novo->getKey()] = true;
            $posteriores[] = $novo;
            $atual = $novo;
        }

        // `$anteriores` saiu do mais recente para o mais antigo, porque a
        // varredura subiu a cadeia. Invertido, o resultado fica em ordem
        // cronológica de ponta a ponta.
        return collect(array_merge(array_reverse($anteriores), [$device], $posteriores));
    }

    /**
     * Este dispositivo já saiu do ponto?
     *
     * A pergunta é respondida pela cadeia, que é o registro do fato: existir
     * linha em `device_replacements` com ele como anterior significa que outro
     * dispositivo assumiu o ponto. A situação da coluna entra como segunda
     * checagem para o caso de um dispositivo marcado como substituído por
     * correção manual ou importação, sem a linha correspondente: nesse estado
     * ele também não pode originar uma troca nova.
     */
    private function jaFoiSubstituido(Device $dispositivo): bool
    {
        if ($dispositivo->substituicaoDeDestino()->exists()) {
            return true;
        }

        return $dispositivo->situacao === self::SITUACAO_SUBSTITUIDO;
    }

    /**
     * Atributos do dispositivo que assume o ponto.
     *
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    private function atributosDoDispositivoNovo(Device $anterior, array $dados): array
    {
        $atributos = [];

        foreach (self::CAMPOS_HERDADOS as $campo) {
            $atributos[$campo] = $anterior->getAttribute($campo);
        }

        // Campo que identifica o ponto só é sobrescrito quando vem preenchido:
        // string vazia no lugar do rótulo deixaria o dispositivo novo sem
        // identificação na etiqueta e na lista do endereço. `address_id` está
        // na lista por completude, mas o endpoint não o expõe de propósito:
        // substituição acontece no mesmo ponto, e ponto que muda de endereço é
        // outra instalação, não uma troca.
        foreach (self::CAMPOS_HERDADOS as $campo) {
            if ($campo === 'default_location_note') {
                continue;
            }

            if (array_key_exists($campo, $dados) && filled($dados[$campo])) {
                $atributos[$campo] = $dados[$campo];
            }
        }

        // A observação de localização é o único campo que pode ser esvaziado
        // de propósito na troca: o ponto pode ter saído de trás da geladeira.
        if (array_key_exists('default_location_note', $dados)) {
            $atributos['default_location_note'] = $dados['default_location_note'];
        }

        // O dispositivo novo nasce em uso. `codigo_publico` fica de fora para o
        // `creating` do Device gerar um código inédito.
        $atributos['active'] = true;
        $atributos['situacao'] = self::SITUACAO_ATIVA;

        return $atributos;
    }

    /**
     * Dia da troca, normalizado para 'Y-m-d' no fuso do negócio.
     *
     * `substituido_em` é campo de dia, e por isso nunca passa por conversão de
     * fuso: converter é o que produz o erro de um dia. Sem valor informado, o
     * padrão é hoje em Brasília, e não o "hoje" do servidor em UTC, que depois
     * das 21h já é outro dia.
     *
     * @param  array<string, mixed>  $dados
     */
    private function diaDaTroca(array $dados): string
    {
        return BusinessDate::diaDe($dados['substituido_em'] ?? null)
            ?? BusinessDate::hoje()->toDateString();
    }

    /**
     * Observação da troca, com espaços aparados e vazio virando nulo.
     *
     * @param  array<string, mixed>  $dados
     */
    private function observacao(array $dados): ?string
    {
        $observacao = trim((string) ($dados['observacao'] ?? ''));

        return $observacao === '' ? null : $observacao;
    }

    /**
     * @return array{success: bool, message: string, data: array{anterior: ?Device, novo: ?Device, substituicao: ?DeviceReplacement}}
     */
    private function recusa(string $mensagem): array
    {
        return [
            'success' => false,
            'message' => $mensagem,
            'data' => ['anterior' => null, 'novo' => null, 'substituicao' => null],
        ];
    }

    /**
     * Formata a cadeia de `historicoDoPonto()` para o formato que a tela
     * consome: um item por dispositivo, do mais antigo ao atual, com o motivo
     * e o dia da troca que tirou cada um de circulação.
     *
     * Promovido de `DeviceReplacementController` (Task 11.5) para cá porque
     * `DeviceController::show()` (Task 11.8) precisa do mesmo formato para
     * mostrar o histórico ao abrir a tela do dispositivo, e não só depois de
     * uma substituição na mesma sessão. Um formatador só, dois consumidores.
     *
     * As datas saem formatadas do backend, no fuso do negócio, porque o
     * navegador do técnico não é fonte confiável de dia.
     *
     * @param  Collection<int, Device>  $dispositivos
     * @return array<int, array<string, mixed>>
     */
    public function historicoParaTela(Collection $dispositivos): array
    {
        return $dispositivos
            ->map(function (Device $dispositivo): array {
                /** @var DeviceReplacement|null $saida */
                $saida = $dispositivo->substituicaoDeDestino()->first();

                return [
                    'id' => $dispositivo->id,
                    'codigo_publico' => $dispositivo->codigo_publico,
                    'label' => $dispositivo->label,
                    'number' => $dispositivo->number,
                    'situacao' => $dispositivo->situacao,
                    'active' => (bool) $dispositivo->active,
                    'substituido_em' => $saida === null
                        ? null
                        : BusinessDate::paraFusoNegocio($saida->substituido_em)?->format('d/m/Y'),
                    'substituido_em_iso' => $saida === null
                        ? null
                        : BusinessDate::diaDe($saida->substituido_em),
                    'motivo' => $saida?->motivo,
                    'observacao' => $saida?->observacao,
                ];
            })
            ->values()
            ->all();
    }
}
