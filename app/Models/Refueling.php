<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Abastecimento de um veículo da frota (Plano 27).
 *
 * É a matéria-prima do consumo e, por consequência, do custo por quilômetro que
 * entra na margem da OS. Duas decisões definem o que se pode e o que não se
 * pode calcular a partir daqui:
 *
 * - **`tanque_cheio` é o que torna o consumo confiável.** Consumo só é apurado
 *   entre dois abastecimentos completos: entre um parcial e o seguinte não se
 *   sabe quanto combustível havia no tanque, e o número que sai parece
 *   plausível sem ser. O `RefuelingService` (Task 27.2) ignora as linhas em que
 *   esta coluna for falsa ao montar a série.
 * - **`km` é a leitura do hodômetro no momento do abastecimento**, não a
 *   distância percorrida. É a diferença entre dois `km` consecutivos que dá a
 *   distância. Por isso quilometragem retroativa é recusada na Task 27.2, com
 *   a última registrada na mensagem: um erro de digitação contamina todos os
 *   intervalos seguintes, não só o próprio.
 *
 * `valor_litro` tem 4 casas e `litros` tem 3 porque é assim que a bomba
 * registra; arredondar aqui desloca o custo por quilômetro exatamente na casa
 * em que ele vive.
 *
 * `payable_id` guarda o título a pagar quando o usuário aceita a oferta de
 * lançá-lo (Task 27.4). O título é **oferecido, não criado automaticamente**:
 * quem controla frota só operacionalmente não quer lançamento financeiro que
 * não pediu.
 */
class Refueling extends Model
{
    use BelongsToCompany;

    /**
     * Combustíveis aceitos, iguais ao enum da coluna `tipo_combustivel`.
     *
     * @var array<int, string>
     */
    public const TIPOS_DE_COMBUSTIVEL = ['gasolina', 'etanol', 'diesel', 'gnv'];

    protected $fillable = [
        'vehicle_id',
        'data',
        'km',
        'litros',
        'valor_total',
        'valor_litro',
        'tipo_combustivel',
        'posto',
        'tanque_cheio',
        'user_id',
        'payable_id',
    ];

    protected $casts = [
        // Dia sem hora relevante: nunca sofre conversão de fuso.
        'data' => 'date',
        'km' => 'integer',
        'litros' => 'decimal:3',
        'valor_total' => 'decimal:2',
        'valor_litro' => 'decimal:4',
        'tanque_cheio' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Veículo abastecido.
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * Usuário que registrou o abastecimento.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Título a pagar gerado a partir deste abastecimento, quando o usuário
     * aceitou a oferta.
     */
    public function payable(): BelongsTo
    {
        return $this->belongsTo(Payable::class);
    }

    /**
     * Apenas abastecimentos de tanque cheio, os únicos que sustentam cálculo
     * de consumo.
     */
    public function scopeTanqueCheio($consulta)
    {
        return $consulta->where('tanque_cheio', true);
    }
}
