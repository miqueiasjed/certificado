<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Veículo da frota (Plano 27).
 *
 * É a raiz do módulo: abastecimento, manutenção e documentação pendem daqui, e
 * é o veículo que leva o custo de deslocamento para dentro do custo da OS
 * (Task 27.2).
 *
 * Decisões que moram neste model:
 *
 * - **`placa` é única por empresa, nunca globalmente.** A unique composta
 *   `[company_id, placa]` está na migration `create_fleet_tables`; unique
 *   global impediria o segundo tenant de cadastrar uma placa que ele nem sabe
 *   que existe no primeiro.
 * - **`km_atual` é a leitura corrente do hodômetro**, atualizada pelo
 *   abastecimento e pela manutenção. Quilometragem retroativa é recusada pelo
 *   serviço (Task 27.2), não aqui: model não carrega regra de negócio.
 * - **`custo_km_padrao` com 4 casas decimais**, mesmo critério de
 *   `ProductBatch::$casts['custo_unitario']`: custo por quilômetro vive na
 *   terceira e na quarta casa, e arredondar para centavo desloca o rateio de
 *   forma invisível. É o valor usado quando o histórico de abastecimento ainda
 *   não tem 3 intervalos de tanque cheio; nesse caso o resultado sai marcado
 *   como padrão, nunca como medido.
 * - **`stockLocation()` é o local de estoque `tipo = veiculo` do Plano 17**,
 *   criado junto com o veículo (Task 27.4), para que a integração com estoque
 *   não dependa de o usuário criar os dois e ligá-los à mão.
 */
class Vehicle extends Model
{
    use BelongsToCompany;

    /**
     * Tipos aceitos, iguais ao enum da coluna `tipo`.
     *
     * @var array<int, string>
     */
    public const TIPOS = ['carro', 'moto', 'utilitario', 'caminhao'];

    /**
     * Situações aceitas, iguais ao enum da coluna `situacao`.
     *
     * @var array<int, string>
     */
    public const SITUACOES = ['ativo', 'manutencao', 'inativo'];

    protected $fillable = [
        'placa',
        'modelo',
        'marca',
        'ano',
        'tipo',
        'technician_id',
        'stock_location_id',
        'km_atual',
        'situacao',
        'custo_km_padrao',
    ];

    protected $casts = [
        'ano' => 'integer',
        'km_atual' => 'integer',
        'custo_km_padrao' => 'decimal:4',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Técnico responsável pelo veículo, quando existe.
     */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    /**
     * Local de estoque do veículo (Plano 17), quando existe.
     */
    public function stockLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class);
    }

    /**
     * Abastecimentos do veículo, do mais recente para o mais antigo.
     */
    public function refuelings(): HasMany
    {
        return $this->hasMany(Refueling::class);
    }

    /**
     * Manutenções preventivas e corretivas do veículo.
     */
    public function maintenances(): HasMany
    {
        return $this->hasMany(VehicleMaintenance::class);
    }

    /**
     * Documentos do veículo, com validade.
     */
    public function documents(): HasMany
    {
        return $this->hasMany(VehicleDocument::class);
    }

    /**
     * Ordens de serviço executadas com este veículo.
     */
    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    /**
     * Apenas veículos em operação.
     */
    public function scopeAtivos($consulta)
    {
        return $consulta->where('situacao', 'ativo');
    }
}
