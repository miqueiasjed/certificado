<?php

namespace App\Services\Geo;

use App\Models\Address;
use App\Support\Geo\ResultadoGeo;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Preenche e corrige a coordenada de um endereço (Plano 22, Task 22.2).
 *
 * O provedor concreto é injetado pelo container a partir da interface
 * `ProvedorDeGeocodificacao` (ligação em
 * `AppServiceProvider::registrarProvedorDeGeocodificacao()`), então trocar de
 * Nominatim para outro provedor não muda uma linha deste arquivo.
 *
 * Duas regras de negócio inegociáveis, repetidas aqui porque são o motivo de
 * este serviço existir:
 *
 * 1. **Correção manual nunca é sobrescrita.** Endereço com
 *    `origem_coordenada = manual` não é tocado por `geocodificar()`, a menos
 *    que `$forcar = true` seja passado explicitamente. É a checagem que faz
 *    valer a pena alguém investir tempo corrigindo um ponto no mapa: se o
 *    trabalho some na próxima rodada automática, ninguém corrige de novo.
 * 2. **O provedor não é consultado à toa, e falha de rede não vira pendência
 *    permanente.** Uma busca que o provedor respondeu e não achou nada
 *    (`buscar()` devolve `null`) marca o endereço como processado
 *    (`geocodificado_em` preenchido, sem coordenada): repetir a mesma
 *    consulta para o mesmo texto tende a dar o mesmo resultado, então vira
 *    pendência para correção manual, não uma nova tentativa a cada execução.
 *    Já um erro de conexão/timeout do provedor (`buscar()` lança exceção)
 *    NÃO marca o endereço como processado, porque isto não é "endereço sem
 *    coordenada", é "não conseguimos perguntar ainda" - a próxima execução
 *    tenta de novo sem precisar de `--forcar`.
 */
class GeocodificacaoService
{
    /**
     * Endereço tem origem manual e a chamada não passou `$forcar`: nenhuma
     * consulta ao provedor foi feita, nada foi alterado.
     */
    public const RESULTADO_IGNORADO_MANUAL = 'ignorado_manual';

    /**
     * O provedor respondeu, mas não achou nada para o endereço informado
     * (busca válida, zero resultados), ou o endereço não tinha dado
     * suficiente para montar uma consulta. Vira pendência.
     */
    public const RESULTADO_NAO_ENCONTRADO = 'nao_encontrado';

    /**
     * A chamada ao provedor falhou (rede, timeout, resposta de erro). O
     * endereço NÃO foi marcado como processado: a próxima execução tenta de
     * novo automaticamente.
     */
    public const RESULTADO_ERRO_PROVEDOR = 'erro_provedor';

    public function __construct(private readonly ProvedorDeGeocodificacao $provedor) {}

    /**
     * Busca a coordenada do endereço e grava o resultado.
     *
     * @return string Uma das constantes `RESULTADO_*` desta classe, ou uma
     *                das constantes `ResultadoGeo::PRECISAO_*` quando o
     *                provedor encontrou o endereço. O comando
     *                `enderecos:geocodificar` usa este valor para montar o
     *                relatório sem precisar reconsultar o banco.
     */
    public function geocodificar(Address $endereco, bool $forcar = false): string
    {
        [$status, $resultado] = $this->resolver($endereco, $forcar);

        // Nada é gravado quando o endereço foi ignorado (correção manual
        // preservada) ou quando o provedor falhou (evita marcar
        // `geocodificado_em` numa tentativa que não chegou a acontecer de
        // verdade).
        if ($status === self::RESULTADO_IGNORADO_MANUAL || $status === self::RESULTADO_ERRO_PROVEDOR) {
            return $status;
        }

        $this->gravar($endereco, $resultado);

        return $status;
    }

    /**
     * Mesma busca de `geocodificar()`, sem gravar nada. Usado pelo
     * `--dry-run` do comando `enderecos:geocodificar` para montar o
     * relatório de precisão exercitando a consulta real ao provedor.
     */
    public function simular(Address $endereco, bool $forcar = false): string
    {
        [$status] = $this->resolver($endereco, $forcar);

        return $status;
    }

    /**
     * Correção manual pela tela (endpoint de task futura do Plano 22).
     *
     * Sempre grava, mesmo por cima de uma coordenada automática anterior:
     * quem chama este método já decidiu, a mão, qual é a coordenada certa.
     */
    public function definirManualmente(Address $endereco, float $latitude, float $longitude): void
    {
        $endereco->forceFill([
            'latitude' => $latitude,
            'longitude' => $longitude,
            'precisao_geocodificacao' => ResultadoGeo::PRECISAO_EXATA,
            'origem_coordenada' => Address::ORIGEM_COORDENADA_MANUAL,
            'geocodificado_em' => now(),
        ])->save();
    }

    /**
     * Núcleo compartilhado por `geocodificar()` e `simular()`: decide se
     * consulta o provedor e devolve o status junto do resultado bruto, sem
     * gravar nada. Gravar é responsabilidade exclusiva de `geocodificar()`.
     *
     * @return array{0: string, 1: ?ResultadoGeo}
     */
    private function resolver(Address $endereco, bool $forcar): array
    {
        if (! $forcar && $endereco->origem_coordenada === Address::ORIGEM_COORDENADA_MANUAL) {
            return [self::RESULTADO_IGNORADO_MANUAL, null];
        }

        $consulta = $this->montarConsulta($endereco);

        if ($consulta === '') {
            return [self::RESULTADO_NAO_ENCONTRADO, null];
        }

        try {
            $resultado = $this->provedor->buscar($consulta);
        } catch (Throwable $erro) {
            Log::warning('geocodificacao.erro_ao_consultar_provedor', [
                'address_id' => $endereco->id,
                'consulta' => $consulta,
                'erro' => $erro->getMessage(),
            ]);

            return [self::RESULTADO_ERRO_PROVEDOR, null];
        }

        return [$resultado?->precisao ?? self::RESULTADO_NAO_ENCONTRADO, $resultado];
    }

    /**
     * Único ponto de gravação automática. `forceFill()` porque estas colunas
     * não estão em `$fillable` de propósito (ver `App\Models\Address`).
     */
    private function gravar(Address $endereco, ?ResultadoGeo $resultado): void
    {
        $endereco->forceFill([
            'latitude' => $resultado?->latitude,
            'longitude' => $resultado?->longitude,
            'precisao_geocodificacao' => $resultado?->precisao,
            'geocodificado_em' => now(),
            'origem_coordenada' => Address::ORIGEM_COORDENADA_AUTOMATICA,
        ])->save();
    }

    /**
     * Monta a consulta textual a partir de logradouro, número, bairro,
     * cidade, estado e CEP. Campos em branco são ignorados; endereço sem
     * nenhum dado utilizável devolve string vazia, e `resolver()` trata isso
     * como "não encontrado" sem gastar uma chamada ao provedor.
     */
    private function montarConsulta(Address $endereco): string
    {
        $partes = array_filter([
            trim((string) $endereco->street),
            trim((string) $endereco->number),
            trim((string) $endereco->district),
            trim((string) $endereco->city),
            trim((string) $endereco->state),
            trim((string) $endereco->zip),
        ], static fn (string $parte): bool => $parte !== '');

        return implode(', ', $partes);
    }
}
