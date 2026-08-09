<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * EPI que um serviço exige (Plano 29, Task 29.1).
 *
 * É a declaração de escritório: quem cadastra o serviço diz o que a execução
 * dele exige vestir. O registro do que o técnico de fato confirmou vestir vive
 * em `WorkOrderPpeConfirmation`, e a prova de que o equipamento foi entregue a
 * ele vive em `PpeDelivery` (Plano 28). São três coisas diferentes, e juntá-las
 * esconderia justamente a pendência que importa.
 *
 * Serviço sem nenhuma linha aqui é o estado normal de quem acabou de ligar o
 * módulo, e nunca irregularidade: a etapa de confirmação simplesmente não
 * aparece para aquele serviço.
 *
 * `obrigatorio` é a exigência **neste serviço**, e não se confunde com
 * `PersonalProtectiveEquipment::$obrigatorio`, que é o padrão do cadastro do
 * modelo de EPI.
 */
class ServicePpeRequirement extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'service_id',
        'personal_protective_equipment_id',
        'obrigatorio',
    ];

    protected $casts = [
        'obrigatorio' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Serviço que exige o equipamento.
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * Modelo de EPI exigido.
     */
    public function personalProtectiveEquipment(): BelongsTo
    {
        return $this->belongsTo(PersonalProtectiveEquipment::class);
    }
}
