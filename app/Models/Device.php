<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\CodigoDeDispositivo;
use App\Support\DominioMultiempresa;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Device extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = [
        'address_id',
        'label',
        'number',
        'codigo_publico',
        'bait_type_id',
        'default_location_note',
        'active',
        'situacao',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    /**
     * Todo dispositivo nasce com código público, sem ninguém precisar informar.
     *
     * O listener fica em `booted()`, e não no boot de uma trait, por causa da
     * ordem em que o Eloquent liga as coisas: `Model::boot()` roda
     * `bootTraits()` primeiro e só depois chama `booted()`. Como
     * `BelongsToCompany` registra o preenchimento de `company_id` no boot da
     * trait, quando este listener executa a empresa do dispositivo já está
     * definida, e a checagem de unicidade consulta a empresa certa. Registrar
     * antes disso olharia para `company_id` ainda nulo e poderia liberar um
     * código já usado na empresa de destino.
     *
     * Código já informado nunca é sobrescrito: o código é imutável depois de
     * impresso na etiqueta, e importação ou substituição que traz o código
     * pronto manda mais que a geração automática.
     */
    protected static function booted(): void
    {
        static::creating(function (self $dispositivo): void {
            if (filled($dispositivo->codigo_publico)) {
                return;
            }

            $empresa = $dispositivo->getAttribute(DominioMultiempresa::COLUNA_TENANT);

            $dispositivo->codigo_publico = CodigoDeDispositivo::gerarInedito(
                fn (string $codigo): bool => self::codigoPublicoEmUso($codigo, $empresa === null ? null : (int) $empresa)
            );
        });
    }

    /**
     * O código já está em uso na empresa informada?
     *
     * Consulta sem o escopo global de empresa e filtrando pela empresa do
     * próprio dispositivo: em backfill, importação ou rotina de plataforma o
     * tenant corrente pode ser outro (ou nenhum), e o que importa para a unique
     * composta `[company_id, codigo_publico]` é a empresa que vai ser gravada.
     *
     * Sem empresa resolvida, a checagem passa a valer para o banco inteiro. É
     * mais restritivo do que a unique exige, e de propósito: sem saber em qual
     * empresa a linha vai cair, recusar um código que existe em qualquer uma
     * delas é o único jeito de não escolher um código que já esteja tomado.
     */
    private static function codigoPublicoEmUso(string $codigo, ?int $empresa): bool
    {
        return static::query()
            ->deTodasAsEmpresas()
            ->when(
                $empresa !== null,
                fn (Builder $consulta): Builder => $consulta->where(DominioMultiempresa::COLUNA_TENANT, $empresa)
            )
            ->where('codigo_publico', $codigo)
            ->exists();
    }

    // Relacionamentos
    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    public function baitType(): BelongsTo
    {
        return $this->belongsTo(BaitType::class);
    }

    public function deviceEvents(): HasMany
    {
        return $this->hasMany(DeviceEvent::class);
    }

    /**
     * Get the work orders linked to this device.
     */
    public function workOrders(): BelongsToMany
    {
        return $this->belongsToMany(WorkOrder::class, 'work_order_device', 'device_id', 'work_order_id')
            ->withPivot('observation')
            ->withTimestamps();
    }

    /**
     * Get the work order device events for this device.
     */
    public function workOrderEvents(): HasMany
    {
        return $this->hasMany(WorkOrderDeviceEvent::class);
    }

    /**
     * Substituição que deu origem a este dispositivo: ele é o dispositivo novo
     * de uma troca, e a relação aponta para trás, para quem ele substituiu.
     *
     * Nulo em dispositivo instalado do zero, que é o caso da maioria.
     */
    public function substituicaoDeOrigem(): HasOne
    {
        return $this->hasOne(DeviceReplacement::class, 'device_novo_id');
    }

    /**
     * Substituição em que este dispositivo foi trocado: ele é o dispositivo
     * anterior, e a relação aponta para frente, para quem assumiu o ponto.
     *
     * Nulo enquanto o dispositivo segue em uso. Subindo por
     * `substituicaoDeOrigem` e descendo por esta, `DeviceReplacementService`
     * remonta a cadeia inteira do ponto.
     */
    public function substituicaoDeDestino(): HasOne
    {
        return $this->hasOne(DeviceReplacement::class, 'device_anterior_id');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeByAddress($query, $addressId)
    {
        return $query->where('address_id', $addressId);
    }

    public function scopeByNumber($query, $number)
    {
        return $query->where('number', 'like', "%{$number}%");
    }

    // Accessors
    public function getActiveBadgeAttribute(): string
    {
        return $this->active ? 'Ativo' : 'Inativo';
    }

    public function getActiveColorAttribute(): string
    {
        return $this->active ? 'green' : 'red';
    }

    public function getFullLabelAttribute(): string
    {
        return "{$this->label} ({$this->number})";
    }
}
