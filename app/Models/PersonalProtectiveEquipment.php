<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Modelo de EPI que a empresa compra e entrega ao técnico (Plano 28).
 *
 * É o cadastro, não a ficha: aqui vive o Certificado de Aprovação do
 * fabricante, e a entrega feita a um técnico vive em `PpeDelivery`.
 *
 * `validade_ca` é a validade **do certificado**, e vale para as entregas
 * futuras deste modelo. Ela não é a validade do item que o técnico tem na mão,
 * que é `PpeDelivery::trocar_ate` — a separação é a decisão central do plano.
 * Renovar o CA aqui não mexe na troca programada de nenhuma entrega já feita, e
 * também não reescreve o CA copiado nas fichas antigas: quem entregou EPI com o
 * CA antigo entregou com o CA antigo, e a ficha precisa continuar dizendo isso.
 *
 * `numero_ca` e `validade_ca` são nullable de propósito: o tenant cadastra o
 * EPI antes de ter o certificado em mãos, e campo em branco é estado neutro,
 * nunca irregularidade. EPI sem CA cadastrado fica fora da varredura de
 * vencimento (Task 28.3), em vez de virar alerta falso.
 *
 * `vida_util_dias` nulo significa "sem troca programada", e não um prazo padrão
 * inventado: prazo inventado vira alerta falso, e alerta falso faz o usuário
 * desligar o alerta inteiro.
 *
 * `validade_ca` é `date` (um dia, sem hora relevante) e nunca sofre conversão
 * de fuso. A comparação de vencido é por dia no fuso do negócio, via
 * `App\Support\BusinessDate`, nunca contra `now()` em UTC, que daria o
 * certificado como vencido a partir das 21h de Brasília do dia anterior.
 */
class PersonalProtectiveEquipment extends Model
{
    use BelongsToCompany;

    /**
     * Tipos aceitos, iguais ao enum da coluna `tipo`.
     *
     * @var array<int, string>
     */
    public const TIPOS = [
        'respirador',
        'luva',
        'oculos',
        'protetor_auricular',
        'calcado',
        'vestimenta',
        'outro',
    ];

    /**
     * Declarado à mão porque "equipment" é incontável em inglês: a
     * pluralização do Eloquent devolveria `personal_protective_equipment`, no
     * singular, e a tabela é `personal_protective_equipments`, no plural, como
     * toda tabela do schema.
     */
    protected $table = 'personal_protective_equipments';

    protected $fillable = [
        'nome',
        'tipo',
        'fabricante',
        'numero_ca',
        'validade_ca',
        'vida_util_dias',
        'obrigatorio',
        'ativo',
        'observacoes',
    ];

    protected $casts = [
        // Dia sem hora relevante: nunca sofre conversão de fuso.
        'validade_ca' => 'date',
        'vida_util_dias' => 'integer',
        'obrigatorio' => 'boolean',
        'ativo' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Entregas já feitas deste modelo de EPI.
     */
    public function ppeDeliveries(): HasMany
    {
        return $this->hasMany(PpeDelivery::class);
    }
}
