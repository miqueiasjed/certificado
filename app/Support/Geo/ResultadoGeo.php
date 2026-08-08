<?php

namespace App\Support\Geo;

/**
 * Resposta de uma busca de geocodificação bem-sucedida (Plano 22, Task 22.2).
 *
 * Mesmo papel de `App\Support\Fiscal\RespostaDeNfse`: dado de resposta de uma
 * integração externa, sem comportamento nenhum. `App\Services\Geo\ProvedorDeGeocodificacao::buscar()`
 * devolve uma instância desta classe quando encontra o endereço, ou `null`
 * quando a busca é válida mas não acha nada.
 */
final readonly class ResultadoGeo
{
    /**
     * Coordenada no nível do imóvel (número encontrado no resultado).
     */
    public const PRECISAO_EXATA = 'exata';

    /**
     * Coordenada no nível da rua/bairro, sem o número do imóvel.
     */
    public const PRECISAO_APROXIMADA = 'aproximada';

    /**
     * Coordenada no nível da cidade/município. Não serve para roteiro: o
     * ponto não está no endereço, só na cidade. Tratada como pendência pelo
     * comando `enderecos:geocodificar`, nunca como sucesso.
     */
    public const PRECISAO_CIDADE = 'cidade';

    public function __construct(
        public float $latitude,
        public float $longitude,
        public string $precisao,
    ) {}
}
