<?php

namespace App\Services;

use App\Models\Address;
use App\Models\Device;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Criação em lote dos dispositivos de um endereço.
 *
 * Instalar monitoramento em um imóvel novo significa espalhar dezenas de porta
 * iscas numerados em sequência pelo perímetro. Cadastrar um a um na tela é meia
 * hora de digitação e é onde nasce a numeração furada, que só aparece meses
 * depois, quando o técnico procura o "PCE 017" que nunca foi cadastrado.
 *
 * Três decisões sustentam este Service:
 *
 * - **O lote é tudo ou nada.** A checagem de números repetidos acontece antes de
 *   qualquer insert, e a criação inteira roda em uma transação. Criar metade e
 *   parar no meio deixaria o técnico sem saber o que existe de fato no endereço,
 *   e a correção manual disso é pior do que refazer o lote.
 * - **A colisão é por endereço, não por empresa.** Dois imóveis diferentes têm,
 *   os dois, um dispositivo "001", e isso é normal: o número é a posição no
 *   croqui daquele imóvel. Barrar por empresa impediria o segundo cliente de
 *   começar do 1.
 * - **O código público sai do `creating` do `Device`.** Nada aqui informa
 *   `codigo_publico`, justamente para o model sortear um código inédito por
 *   dispositivo. Gerar em lote aqui duplicaria a regra e correria o risco de
 *   repetir código dentro do próprio lote.
 *
 * O zero à esquerda segue o MAIOR número da faixa, com piso de três casas. As
 * duas partes importam: o piso mantém o formato de etiqueta que a operação já
 * usa (`PCE 001`), e seguir o maior impede que um lote que começa em 95 e vai
 * até 104 saia com "95" ao lado de "104", o que embaralharia o croqui na
 * ordenação alfabética da listagem. Faixa que passa de 999 ganha a quarta casa
 * sozinha, do primeiro ao último.
 */
class DeviceBatchService
{
    /**
     * Teto de dispositivos por chamada.
     *
     * Cada dispositivo é um `create()` individual, porque é o `creating` do
     * model que sorteia o código público, e cada sorteio confere o banco. Acima
     * deste teto a requisição estoura o tempo limite do PHP e o técnico fica sem
     * resposta, sem saber se criou. Lote maior se resolve em duas chamadas, e
     * duas chamadas com resposta valem mais que uma sem.
     */
    public const QUANTIDADE_MAXIMA = 200;

    /**
     * Situação com que todo dispositivo do lote nasce. Espelha o enum de
     * `devices.situacao`.
     */
    public const SITUACAO_ATIVA = 'ativo';

    /**
     * Piso de casas do número, contando o zero à esquerda.
     *
     * Três é o formato de etiqueta que a operação já usa: um lote de 40 sai
     * `001` a `040`, e não `01` a `40`. A faixa que precisar de mais casas ganha
     * quantas o maior número pedir; este é o mínimo, não um tamanho fixo.
     */
    private const MINIMO_DE_CASAS = 3;

    /**
     * Quantos números em conflito aparecem na mensagem antes de virar contagem.
     *
     * Um lote de 200 que colide inteiro produziria uma mensagem ilegível. Os
     * primeiros já dizem onde a faixa começou a bater; o resto vira "e mais N".
     */
    private const NUMEROS_LISTADOS_NA_MENSAGEM = 10;

    /**
     * Cria a faixa de dispositivos no endereço.
     *
     * `$dados` aceita `quantidade`, `numero_inicial`, `prefixo`, e os opcionais
     * `bait_type_id` e `default_location_note`. Os limites já vêm validados por
     * `StoreDeviceBatchRequest`, e são reconferidos aqui porque o Service também
     * é chamado por comando e por teste, onde não passa FormRequest nenhum.
     *
     * @param  array<string, mixed>  $dados
     * @return array{success: bool, message: string, data: array{dispositivos: Collection<int, Device>, numeros_em_conflito: array<int, string>}}
     */
    public function criarLote(Address $endereco, array $dados): array
    {
        $quantidade = (int) ($dados['quantidade'] ?? 0);
        $numeroInicial = (int) ($dados['numero_inicial'] ?? 0);

        if ($quantidade < 1 || $quantidade > self::QUANTIDADE_MAXIMA) {
            return $this->recusa(sprintf(
                'A quantidade do lote precisa ficar entre 1 e %d dispositivos.',
                self::QUANTIDADE_MAXIMA
            ));
        }

        if ($numeroInicial < 1) {
            return $this->recusa('O número inicial precisa ser um inteiro maior que zero.');
        }

        $numeros = $this->numerosDaFaixa($numeroInicial, $quantidade);
        $prefixo = trim((string) ($dados['prefixo'] ?? ''));
        $baitTypeId = blank($dados['bait_type_id'] ?? null) ? null : (int) $dados['bait_type_id'];
        $observacao = $this->observacaoDeLocalizacao($dados);

        return DB::transaction(function () use ($endereco, $numeros, $prefixo, $baitTypeId, $observacao): array {
            // Trava a linha do endereço antes de conferir os números. Dois
            // técnicos disparando o mesmo lote no mesmo imóvel passariam os dois
            // pela checagem e criariam a faixa duplicada: não há unique em
            // `[address_id, number]` para o banco recusar depois. Com a trava, o
            // segundo só confere depois que o primeiro gravou, e cai na recusa.
            Address::query()->whereKey($endereco->getKey())->lockForUpdate()->first();

            $conflitos = $this->numerosJaUsadosNoEndereco($endereco, $numeros);

            if ($conflitos !== []) {
                return $this->recusa($this->mensagemDeConflito($conflitos), $conflitos);
            }

            // Coleção do Eloquent, e não a coleção base: quem chama precisa
            // poder fazer `->load('baitType')` na lista inteira em uma consulta
            // só, em vez de carregar o mesmo tipo de isca 200 vezes.
            $dispositivos = new Collection(array_map(
                fn (string $numero): Device => Device::query()->create([
                    'address_id' => $endereco->getKey(),
                    'label' => $this->rotulo($prefixo, $numero),
                    'number' => $numero,
                    // `codigo_publico` fica de fora de propósito: o `creating` do
                    // Device sorteia um código inédito para cada linha.
                    'bait_type_id' => $baitTypeId,
                    'default_location_note' => $observacao,
                    'active' => true,
                    'situacao' => self::SITUACAO_ATIVA,
                ]),
                $numeros
            ));

            return [
                'success' => true,
                'message' => sprintf(
                    '%d dispositivo(s) criado(s) neste endereço, de %s a %s.',
                    $dispositivos->count(),
                    $numeros[0],
                    $numeros[count($numeros) - 1]
                ),
                'data' => [
                    'dispositivos' => $dispositivos,
                    'numeros_em_conflito' => [],
                ],
            ];
        });
    }

    /**
     * Os números da faixa, todos com o mesmo comprimento.
     *
     * O comprimento sai do maior número, respeitando o piso de três casas, e é
     * calculado antes do laço: padronizar pelo inicial deixaria `095` ao lado de
     * `104` na mesma faixa.
     *
     * @return array<int, string>
     */
    private function numerosDaFaixa(int $numeroInicial, int $quantidade): array
    {
        $numeroFinal = $numeroInicial + $quantidade - 1;
        $casas = max(self::MINIMO_DE_CASAS, strlen((string) $numeroFinal));

        $numeros = [];

        for ($numero = $numeroInicial; $numero <= $numeroFinal; $numero++) {
            $numeros[] = str_pad((string) $numero, $casas, '0', STR_PAD_LEFT);
        }

        return $numeros;
    }

    /**
     * Quais números da faixa já existem NESTE endereço.
     *
     * A consulta passa pelo escopo global por empresa e ainda filtra por
     * `address_id`: o número identifica a posição no croqui de um imóvel, e o
     * mesmo "001" existindo em outro endereço do mesmo cliente não é conflito
     * nenhum.
     *
     * @param  array<int, string>  $numeros
     * @return array<int, string>
     */
    private function numerosJaUsadosNoEndereco(Address $endereco, array $numeros): array
    {
        return Device::query()
            ->where('address_id', $endereco->getKey())
            ->whereIn('number', $numeros)
            ->pluck('number')
            ->map(fn (mixed $numero): string => (string) $numero)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Rótulo impresso na etiqueta: prefixo e número separados por um espaço.
     *
     * Sem prefixo, o rótulo é o próprio número. Isso mantém o campo opcional sem
     * gerar um rótulo começando com espaço.
     */
    private function rotulo(string $prefixo, string $numero): string
    {
        return $prefixo === '' ? $numero : $prefixo.' '.$numero;
    }

    /**
     * Observação de localização do lote, com espaços aparados e vazio virando
     * nulo. Todos os dispositivos do lote nascem com a mesma; o ajuste ponto a
     * ponto é feito na edição de cada um.
     *
     * @param  array<string, mixed>  $dados
     */
    private function observacaoDeLocalizacao(array $dados): ?string
    {
        $observacao = trim((string) ($dados['default_location_note'] ?? ''));

        return $observacao === '' ? null : $observacao;
    }

    /**
     * Mensagem da recusa por número repetido, com os primeiros conflitos à
     * vista. É ela que diz ao técnico onde a faixa bateu no que já existe.
     *
     * @param  array<int, string>  $conflitos
     */
    private function mensagemDeConflito(array $conflitos): string
    {
        $mostrados = array_slice($conflitos, 0, self::NUMEROS_LISTADOS_NA_MENSAGEM);
        $restantes = count($conflitos) - count($mostrados);

        $lista = implode(', ', $mostrados);

        if ($restantes > 0) {
            $lista .= sprintf(' e mais %d', $restantes);
        }

        return sprintf(
            'Estes números já existem neste endereço: %s. Nenhum dispositivo foi criado. '
            .'Ajuste o número inicial ou a quantidade e envie o lote de novo.',
            $lista
        );
    }

    /**
     * @param  array<int, string>  $conflitos
     * @return array{success: bool, message: string, data: array{dispositivos: Collection<int, Device>, numeros_em_conflito: array<int, string>}}
     */
    private function recusa(string $mensagem, array $conflitos = []): array
    {
        return [
            'success' => false,
            'message' => $mensagem,
            'data' => [
                // Coleção do Eloquent também na recusa: quem chama não deve
                // precisar saber se deu certo para poder chamar `->load()`.
                'dispositivos' => new Collection(),
                'numeros_em_conflito' => $conflitos,
            ],
        ];
    }
}
