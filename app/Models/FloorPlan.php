<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Planta de um endereço, versionada: a versão antiga nunca é apagada, e cada
 * versão carrega as próprias `DevicePosition`. Trocar de planta é criar uma
 * linha nova com `versao` maior, nunca sobrescrever a anterior, para que um
 * relatório emitido há um ano continue refletindo o layout daquela época.
 */
class FloorPlan extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'address_id',
        'versao',
        'nome',
        'arquivo_path',
        'largura_px',
        'altura_px',
        'ativa',
        'substituida_em',
        'observacao',
    ];

    protected $casts = [
        'versao' => 'integer',
        'largura_px' => 'integer',
        'altura_px' => 'integer',
        'ativa' => 'boolean',
        'substituida_em' => 'datetime',
    ];

    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    public function devicePositions(): HasMany
    {
        return $this->hasMany(DevicePosition::class);
    }

    /**
     * URL pública do arquivo da planta, no mesmo padrão do disco `public`
     * usado por WorkOrderPhoto para arquivo de imagem.
     */
    public function getArquivoUrlAttribute(): string
    {
        return asset('storage/'.$this->arquivo_path);
    }
}
