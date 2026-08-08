<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\Geo\ResultadoGeo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Address extends Model
{
    use BelongsToCompany, HasFactory;

    /**
     * Origem da coordenada gravada (Plano 22, Task 22.2).
     */
    public const ORIGEM_COORDENADA_AUTOMATICA = 'automatica';

    public const ORIGEM_COORDENADA_MANUAL = 'manual';

    protected $fillable = [
        'client_id',
        'nickname',
        'street',
        'number',
        'district',
        'city',
        'state',
        'zip',
        'codigo_municipio_ibge',
        'reference',
        'active',
    ];

    // `latitude`, `longitude`, `geocodificado_em`, `origem_coordenada` e
    // `precisao_geocodificacao` (Task 22.1) NÃO entram em `$fillable`, de
    // propósito.
    //
    // A regra de negócio inegociável do Plano 22 é: correção manual
    // (`origem_coordenada = manual`) nunca é sobrescrita por uma
    // geocodificação automática, exceto com `$forcar` explícito. Essa regra
    // vive inteira em `App\Services\Geo\GeocodificacaoService`. Se estas
    // colunas entrassem em `$fillable`, um `AddressRequest` futuro que
    // juntasse um campo de latitude/longitude ao formulário (ou um
    // `$address->update($request->validated())` descuidado) gravaria a
    // coordenada por fora do serviço, sem passar pela checagem de origem
    // manual - a única forma de garantir a regra é essas colunas nunca
    // aceitarem mass assignment. `GeocodificacaoService::geocodificar()` e
    // `::definirManualmente()` usam `forceFill()`, que ignora `$fillable` de
    // propósito, e são os dois únicos pontos de gravação do sistema nestas
    // colunas.

    protected $casts = [
        'active' => 'boolean',
        'codigo_municipio_ibge' => 'string',
        // Mesmo padrão de WorkOrderSignature::$casts (Plano 13) para
        // coordenada: decimal(10,7) na migration, 'decimal:7' aqui, para
        // devolver string com a precisão exata em vez de float sujeito a
        // arredondamento binário.
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'geocodificado_em' => 'datetime',
    ];

    // Relacionamentos
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    public function pestSightings(): HasMany
    {
        return $this->hasMany(PestSighting::class);
    }

    public function contract(): HasOne
    {
        return $this->hasOne(Contract::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeByClient($query, $clientId)
    {
        return $query->where('client_id', $clientId);
    }

    public function scopeByCity($query, $city)
    {
        return $query->where('city', 'like', "%{$city}%");
    }

    public function scopeByState($query, $state)
    {
        return $query->where('state', $state);
    }

    /**
     * Endereço sem coordenada OU com precisão `cidade` (Plano 22, Task 22.5,
     * `GET /enderecos/pendencias-geo`): o ponto não está no endereço, só na
     * cidade, e por isso não serve para roteiro - mesmo critério que
     * `OtimizadorDeRota::separarPorCoordenada()` usa para jogar a parada para
     * o fim do roteiro.
     */
    public function scopePendenteDeGeocodificacao($query)
    {
        return $query->where(function ($consulta) {
            $consulta->whereNull('latitude')
                ->orWhereNull('longitude')
                ->orWhere('precisao_geocodificacao', ResultadoGeo::PRECISAO_CIDADE);
        });
    }

    // Accessors
    public function getFullAddressAttribute(): string
    {
        return "{$this->street}, {$this->number} - {$this->district}, {$this->city}/{$this->state} - CEP: {$this->zip}";
    }

    public function getShortAddressAttribute(): string
    {
        return "{$this->street}, {$this->number} - {$this->district}";
    }
}
