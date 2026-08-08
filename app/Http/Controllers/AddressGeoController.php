<?php

namespace App\Http\Controllers;

use App\Http\Requests\DefinirCoordenadaRequest;
use App\Models\Address;
use App\Services\Geo\GeocodificacaoService;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Pendências de geocodificação e correção manual de coordenada (Plano 22,
 * Task 22.5). Controller fino: toda regra de negócio vive em
 * `GeocodificacaoService` (Task 22.2); este arquivo só traduz requisição em
 * chamada de Service e resposta JSON.
 */
class AddressGeoController extends Controller
{
    public function __construct(private readonly GeocodificacaoService $geocodificacao) {}

    /**
     * `GET /enderecos/pendencias-geo/painel`: casca Inertia de
     * `Enderecos/PendenciasGeo.vue` (Plano 22, Task 22.6). A lista de
     * pendências em si continua vindo de `pendencias()` (JSON, paginado),
     * chamada pelo próprio Vue - mesmo motivo e mesmo padrão de
     * `RouteController::painel()`: `/enderecos/pendencias-geo` já é o
     * contrato JSON testado da Task 22.5, então a página ganha uma rota
     * própria em vez de sobrecarregar aquela.
     */
    public function painel(): InertiaResponse
    {
        return Inertia::render('Enderecos/PendenciasGeo');
    }

    /**
     * `GET /enderecos/pendencias-geo`: endereço sem coordenada OU com
     * precisão `cidade` (ver `Address::scopePendenteDeGeocodificacao()`).
     */
    public function pendencias(): JsonResponse
    {
        $enderecos = Address::query()
            ->pendenteDeGeocodificacao()
            ->with('client:id,name')
            ->orderBy('city')
            ->orderBy('street')
            ->paginate(15);

        return response()->json($enderecos);
    }

    /**
     * `PUT /enderecos/{address}/coordenada`: correção manual. Sempre grava
     * `origem_coordenada = manual` (`GeocodificacaoService::definirManualmente()`);
     * a partir daí a geocodificação automática em lote (comando
     * `enderecos:geocodificar`, Task 22.2) nunca mais sobrescreve este
     * endereço sem `--forcar` explícito.
     */
    public function definirCoordenada(DefinirCoordenadaRequest $request, Address $address): JsonResponse
    {
        $this->geocodificacao->definirManualmente(
            $address,
            (float) $request->validated('latitude'),
            (float) $request->validated('longitude'),
        );

        return response()->json($this->apresentarEndereco($address->fresh()));
    }

    /**
     * `POST /enderecos/{address}/geocodificar`: tentar de novo, forçado.
     *
     * Diferente do comando em lote da Task 22.2 (`enderecos:geocodificar`,
     * que nunca força sem `--forcar` explícito, para não atropelar correção
     * manual de ninguém em massa): aqui é uma ação explícita do usuário sobre
     * UM endereço específico, escolhido na tela, então sempre força - mesmo
     * por cima de uma correção manual anterior, se for o caso. Quem clicou
     * "tentar de novo" neste endereço está pedindo exatamente isso.
     */
    public function geocodificar(Address $address): JsonResponse
    {
        $resultado = $this->geocodificacao->geocodificar($address, forcar: true);

        return response()->json([
            'resultado' => $resultado,
            'endereco' => $this->apresentarEndereco($address->fresh()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function apresentarEndereco(Address $endereco): array
    {
        return [
            'id' => $endereco->id,
            'client_id' => $endereco->client_id,
            'cliente' => $endereco->client?->name,
            'endereco' => $endereco->full_address,
            'latitude' => $endereco->latitude !== null ? (float) $endereco->latitude : null,
            'longitude' => $endereco->longitude !== null ? (float) $endereco->longitude : null,
            'precisao_geocodificacao' => $endereco->precisao_geocodificacao,
            'origem_coordenada' => $endereco->origem_coordenada,
            'geocodificado_em' => optional($endereco->geocodificado_em)->toIso8601String(),
        ];
    }
}
