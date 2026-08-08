<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Documento de um veículo da frota, com validade (Plano 27).
 *
 * Licenciamento e seguro vencidos têm consequência legal: circular com o
 * licenciamento vencido é infração, e sinistro com seguro vencido é prejuízo
 * inteiro. Por isso `validade` é obrigatória — documento sem validade não tem o
 * que alertar — e o aviso de documento vencido **insiste semanalmente** (Task
 * 27.3), no mesmo critério do lote vencido do Plano 17 e do documento
 * regulatório do Plano 24.
 *
 * `validade` é `date` (um dia, sem hora relevante) e nunca sofre conversão de
 * fuso. A comparação de vencido é feita por dia no fuso do negócio, via
 * `App\Support\BusinessDate`, nunca contra `now()` em UTC, que daria o
 * documento como vencido a partir das 21h de Brasília do dia anterior.
 */
class VehicleDocument extends Model
{
    use BelongsToCompany;

    /**
     * Tipos aceitos, iguais ao enum da coluna `tipo`.
     *
     * @var array<int, string>
     */
    public const TIPOS = ['licenciamento', 'seguro', 'outro'];

    protected $fillable = [
        'vehicle_id',
        'tipo',
        'numero',
        'validade',
        'arquivo_path',
    ];

    protected $casts = [
        // Dia sem hora relevante: nunca sofre conversão de fuso.
        'validade' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Veículo a que o documento pertence.
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
