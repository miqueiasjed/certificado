<?php

namespace App\Support\Geo;

use InvalidArgumentException;

/**
 * Um ponto geográfico simples: latitude e longitude, sem nenhum outro dado
 * junto (Plano 22, Task 22.3).
 *
 * Não é o mesmo papel de `ResultadoGeo` (Task 22.2): aquela classe é a
 * resposta de uma busca de geocodificação, carrega a precisão do provedor e
 * nasce ligada a esse contexto. `Coordenada` é só o par lat/lng, para
 * qualquer código que precise de um ponto no mapa sem falar de
 * geocodificação - a sede da empresa (parâmetro de
 * `RouteService::montar()`), o ponto de partida do otimizador
 * (`OtimizadorDeRota`) e as duas coordenadas de
 * `EstimadorDeDeslocamento::estimar()`. Verificado antes de criar esta
 * classe: não existia nenhum tipo equivalente no projeto, e nada da Task
 * 22.2 foi alterado para introduzi-la.
 */
final readonly class Coordenada
{
    public function __construct(
        public float $latitude,
        public float $longitude,
    ) {
        if ($latitude < -90.0 || $latitude > 90.0) {
            throw new InvalidArgumentException("Latitude fora do intervalo válido (-90 a 90): {$latitude}");
        }

        if ($longitude < -180.0 || $longitude > 180.0) {
            throw new InvalidArgumentException("Longitude fora do intervalo válido (-180 a 180): {$longitude}");
        }
    }

    /**
     * Constrói a partir de um par de valores possivelmente ausentes, como os
     * atributos `latitude`/`longitude` de `Address` chegam (cast
     * `decimal:7`, então string, ou `null` em endereço ainda não
     * geocodificado). Devolve `null` quando faltar qualquer um dos dois: um
     * endereço sem coordenada não pode virar uma `Coordenada` de mentira em
     * `0.0, 0.0`.
     */
    public static function apartirDe(mixed $latitude, mixed $longitude): ?self
    {
        if ($latitude === null || $longitude === null) {
            return null;
        }

        return new self((float) $latitude, (float) $longitude);
    }
}
