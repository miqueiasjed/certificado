<?php

namespace App\Services\Geo;

use App\Support\Geo\ResultadoGeo;

/**
 * Contrato do provedor de geocodificação de endereço (Plano 22, Task 22.2).
 *
 * Mesmo espírito de `App\Services\Fiscal\ProvedorDeNfse`: integração HTTP
 * externa configurável atrás de uma interface, para trocar de provedor sem
 * reescrever quem consome.
 *
 * A implementação de hoje é `App\Services\Geo\ProvedorNominatim` (OpenStreetMap
 * Nominatim, gratuito, sem chave de API - ver o cabeçalho daquela classe para
 * o porquê). Trocar de provedor - por exemplo para Google Maps ou Mapbox,
 * quando houver orçamento e credencial paga decididos - é criar outra classe
 * atrás desta interface e apontar `config('services.geocodificacao.provedor')`
 * (variável de ambiente `GEOCODIFICACAO_PROVEDOR_DRIVER`) para ela. Nenhum
 * consumidor (`GeocodificacaoService`, o comando `enderecos:geocodificar`)
 * muda uma linha.
 */
interface ProvedorDeGeocodificacao
{
    /**
     * Busca a coordenada do endereço informado como texto livre.
     *
     * Devolve `null` quando a busca é válida mas não encontra nada (endereço
     * não localizado pelo provedor) - isto é resultado normal, não erro.
     *
     * Erro de conexão, timeout ou resposta de erro do provedor devem ser
     * lançados como exceção, nunca devolvidos como `null`: quem chama
     * precisa distinguir "não encontramos este endereço" (vira pendência
     * permanente, para correção manual) de "não conseguimos perguntar agora"
     * (precisa ser tentado de novo na próxima execução). Ver
     * `GeocodificacaoService::geocodificar()`.
     */
    public function buscar(string $endereco): ?ResultadoGeo;
}
