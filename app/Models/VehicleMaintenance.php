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
     * Manutenções já executadas: são as que geraram custo, e por isso as únicas
     * que entram no custo por quilômetro (`CustoPorKmService`).
     *
     * A próxima prevista **não** é exclusividade delas — ver
     * `scopeComProximaPrevista()`.
     */
    public function scopeRealizadas($consulta)
    {
        return $consulta->where('situacao', 'realizada');
    }

    /**
     * Manutenções cuja próxima prevista ainda está valendo: é o conjunto que o
     * alerta de frota olha (`AlertaDeFrotaService::manutencoesAVencer()`).
     *
     * O critério é a existência de `proxima_em_data` ou `proxima_em_km`, não a
     * situação, porque a previsão nasce dos dois fluxos naturais de registro:
     *
     * - `realizada` — "troquei o óleo hoje, a próxima é em 10.000 km". É o mais
     *   comum, e é exatamente o que uma manutenção executada deixa como
     *   herança: a data e a quilometragem da próxima;
     * - `agendada` — o serviço marcado que ainda vai acontecer, cuja
     *   `proxima_em_data`/`proxima_em_km` é a própria previsão dele.
     *
     * Só `cancelada` fica de fora: previsão de serviço que a empresa desistiu
     * de fazer não tem o que avisar.
     *
     * Ligar o alerta a uma única situação é o que fazia o módulo falhar em
     * silêncio: quem registrava a manutenção como `realizada` — o fluxo
     * natural — nunca recebia aviso nenhum, e quem deixava como `agendada` só
     * para receber o aviso deixava o serviço fora do custo por quilômetro.
     */
    public function scopeComProximaPrevista($consulta)
    {
        return $consulta
            ->where('situacao', '!=', 'cancelada')
            ->where(function ($consulta): void {
                $consulta->whereNotNull('proxima_em_data')->orWhereNotNull('proxima_em_km');
            });
    }
}
