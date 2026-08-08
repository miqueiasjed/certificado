<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Manutenção preventiva ou corretiva de um veículo da frota (Plano 27).
 *
 * O que define a modelagem é o alerta: **`proxima_em_data` e `proxima_em_km`
 * são critérios independentes, e vence o que chegar primeiro**. Troca de óleo é
 * "6 meses ou 10 mil quilômetros", e derivar um do outro (estimando
 * quilometragem por tempo, ou o contrário) produziria alerta que dispara no dia
 * errado justamente no veículo que roda muito mais, ou muito menos, que a
 * média. Os dois são nullable: manutenção corretiva não tem próxima prevista.
 *
 * `data` e `km` também são nullable porque manutenção `agendada` ainda não
 * aconteceu: não tem o dia em que foi feita nem a leitura do hodômetro do
 * momento. Os dois são preenchidos quando ela passa a `realizada`.
 *
 * `data` é `date` (um dia, sem hora relevante) e nunca sofre conversão de fuso.
 * A comparação de vencimento do alerta é feita por dia no fuso do negócio, via
 * `App\Support\BusinessDate` (Task 27.3), nunca contra `now()` em UTC.
 *
 * `payable_id` guarda o título a pagar quando o usuário aceita a oferta de
 * lançá-lo (Task 27.4). O título é oferecido, não criado automaticamente.
 */
class VehicleMaintenance extends Model
{
    use BelongsToCompany;

    /**
     * Tipos aceitos, iguais ao enum da coluna `tipo`.
     *
     * @var array<int, string>
     */
    public const TIPOS = ['preventiva', 'corretiva'];

    /**
     * Situações aceitas, iguais ao enum da coluna `situacao`.
     *
     * @var array<int, string>
     */
    public const SITUACOES = ['agendada', 'realizada', 'cancelada'];

    protected $fillable = [
        'vehicle_id',
        'tipo',
        'descricao',
        'data',
        'km',
        'proxima_em_data',
        'proxima_em_km',
        'valor',
        'oficina',
        'situacao',
        'payable_id',
    ];

    protected $casts = [
        // Dias sem hora relevante: nunca sofrem conversão de fuso.
        'data' => 'date',
        'proxima_em_data' => 'date',
        'km' => 'integer',
        'proxima_em_km' => 'integer',
        'valor' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Veículo em manutenção.
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * Título a pagar gerado a partir desta manutenção, quando o usuário
     * aceitou a oferta.
     */
    public function payable(): BelongsTo
    {
        return $this->belongsTo(Payable::class);
    }

    /**
     * Manutenções que ainda vão acontecer.
     */
    public function scopeAgendadas($consulta)
    {
        return $consulta->where('situacao', 'agendada');
    }

    /**
     * Manutenções já executadas, que são as que carregam a próxima prevista.
     */
    public function scopeRealizadas($consulta)
    {
        return $consulta->where('situacao', 'realizada');
    }
}
