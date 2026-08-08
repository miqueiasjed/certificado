<?php

namespace App\Services\Geo;

use App\Support\Geo\ResultadoGeo;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Provedor padrão de geocodificação: Nominatim (OpenStreetMap).
 *
 * Escolha de provedor
 * --------------------
 * Este ambiente não tem credencial paga de geocodificação configurada
 * (Google Maps, Mapbox etc.). Nominatim é a opção sensata quando não há
 * orçamento/credencial paga decidida: é gratuito, não exige chave de API, e
 * cobre razoavelmente bem endereço urbano brasileiro por já indexar dados do
 * OpenStreetMap.
 *
 * Trocar para um provedor pago no futuro é criar outra classe implementando
 * `App\Services\Geo\ProvedorDeGeocodificacao` e apontar
 * `config('services.geocodificacao.provedor')` (variável de ambiente
 * `GEOCODIFICACAO_PROVEDOR_DRIVER`) para ela. `GeocodificacaoService` e o
 * comando `enderecos:geocodificar` não mudam nada: os dois dependem só da
 * interface.
 *
 * Política de uso do Nominatim
 * -----------------------------
 * https://operations.osmfoundation.org/policies/nominatim/ - respeitada
 * assim:
 *
 * - **No máximo ~1 requisição por segundo.** Esta classe faz uma única
 *   chamada HTTP por invocação de `buscar()` e não implementa pausa nenhuma
 *   internamente; quem processa em lote (`GeocodificarEnderecos`) é
 *   responsável pelo intervalo entre chamadas (`--pausa-ms`, padrão
 *   1000ms). Uma chamada avulsa (job de endereço novo, correção manual) não
 *   precisa de pausa, porque é uma única requisição isolada.
 * - **User-Agent identificando a aplicação é obrigatório.** Sem ele o
 *   Nominatim pode bloquear o IP de origem. Configurável em
 *   `services.geocodificacao.nominatim.user_agent` (env
 *   `GEOCODIFICACAO_USER_AGENT`); sem valor configurado, cai para o nome e a
 *   URL da aplicação.
 * - **Uso comercial de alto volume** deveria rodar uma instância própria do
 *   Nominatim ou contratar um provedor pago. O volume de uma empresa de
 *   controle de pragas geocodificando o próprio cadastro de clientes (dezenas
 *   a poucos milhares de endereços, geocodificados uma vez cada) está dentro
 *   do uso moderado que a política permite gratuitamente.
 */
class ProvedorNominatim implements ProvedorDeGeocodificacao
{
    private const TIMEOUT_PADRAO_SEGUNDOS = 10;

    private const URL_PADRAO = 'https://nominatim.openstreetmap.org/search';

    /**
     * Tipos de resultado do Nominatim que representam uma área ampla
     * (cidade, município, estado, bairro), não um ponto no endereço.
     *
     * @var array<int, string>
     */
    private const TIPOS_DE_AREA_AMPLA = [
        'city', 'town', 'village', 'municipality', 'county',
        'state', 'administrative', 'hamlet', 'suburb', 'postcode',
    ];

    public function buscar(string $endereco): ?ResultadoGeo
    {
        $endereco = trim($endereco);

        if ($endereco === '') {
            return null;
        }

        try {
            $resposta = Http::withHeaders(['User-Agent' => $this->userAgent()])
                ->timeout($this->timeout())
                ->connectTimeout(5)
                ->get($this->url(), [
                    'q' => $endereco,
                    'format' => 'jsonv2',
                    'addressdetails' => 1,
                    'limit' => 1,
                ]);
        } catch (ConnectionException $erro) {
            Log::warning('geocodificacao.nominatim.erro_de_conexao', [
                'endereco' => $endereco,
                'erro' => $erro->getMessage(),
            ]);

            throw new RuntimeException('Nominatim indisponível: '.$erro->getMessage(), previous: $erro);
        }

        if ($resposta->failed()) {
            Log::warning('geocodificacao.nominatim.resposta_com_erro', [
                'endereco' => $endereco,
                'status' => $resposta->status(),
            ]);

            throw new RuntimeException('Nominatim respondeu com status '.$resposta->status().'.');
        }

        $resultados = $resposta->json();

        if (! is_array($resultados) || $resultados === [] || ! is_array($resultados[0] ?? null)) {
            return null;
        }

        return $this->paraResultadoGeo($resultados[0]);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function paraResultadoGeo(array $item): ?ResultadoGeo
    {
        $latitude = isset($item['lat']) && is_numeric($item['lat']) ? (float) $item['lat'] : null;
        $longitude = isset($item['lon']) && is_numeric($item['lon']) ? (float) $item['lon'] : null;

        if ($latitude === null || $longitude === null) {
            return null;
        }

        return new ResultadoGeo($latitude, $longitude, $this->precisaoDoItem($item));
    }

    /**
     * Deriva a precisão a partir do resultado do Nominatim.
     *
     * Um `house_number` presente no detalhamento do endereço (pedido via
     * `addressdetails=1`) é o sinal mais confiável de que o ponto está no
     * imóvel, não só na rua. Na ausência dele, `class`/`type` classificam o
     * resultado como área ampla (cidade) ou como via/logradouro
     * (aproximada).
     *
     * @param  array<string, mixed>  $item
     */
    private function precisaoDoItem(array $item): string
    {
        $endereco = is_array($item['address'] ?? null) ? $item['address'] : [];

        if (trim((string) ($endereco['house_number'] ?? '')) !== '') {
            return ResultadoGeo::PRECISAO_EXATA;
        }

        $classe = (string) ($item['class'] ?? '');
        $tipo = (string) ($item['type'] ?? '');

        if ($classe === 'place' || in_array($tipo, self::TIPOS_DE_AREA_AMPLA, true)) {
            return ResultadoGeo::PRECISAO_CIDADE;
        }

        return ResultadoGeo::PRECISAO_APROXIMADA;
    }

    private function url(): string
    {
        return (string) config('services.geocodificacao.nominatim.url', self::URL_PADRAO);
    }

    private function timeout(): int
    {
        return (int) config('services.geocodificacao.nominatim.timeout', self::TIMEOUT_PADRAO_SEGUNDOS);
    }

    private function userAgent(): string
    {
        $configurado = trim((string) config('services.geocodificacao.nominatim.user_agent', ''));

        if ($configurado !== '') {
            return $configurado;
        }

        return trim((string) config('app.name', 'Sistema')).' ('.trim((string) config('app.url', '')).')';
    }
}
