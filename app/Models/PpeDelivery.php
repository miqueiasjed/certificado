<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ficha de entrega de EPI a um técnico (Plano 28): uma linha por item entregue.
 *
 * É o registro que a NR-6 exige (item 6.5.1) e a prova do empregador em
 * reclamatória trabalhista, com arquivamento recomendado de 20 anos.
 *
 * Três decisões sustentam este model:
 *
 * - **O CA é copiado no ato da entrega, nunca lido pela relação.** `numero_ca` e
 *   `validade_ca` daqui são a fotografia do certificado no dia em que o técnico
 *   recebeu o equipamento. Ler o CA por `personalProtectiveEquipment` faria a
 *   ficha do ano passado mudar sozinha quando o cadastro fosse renovado, e é
 *   pela mesma razão que documento emitido não se recalcula.
 * - **A entrega não se edita nem se exclui.** Documento oponível não admite
 *   `UPDATE` de conteúdo nem `DELETE`: erro vira `estornada_em` +
 *   `motivo_estorno`, na convenção do movimento de ajuste do razão de estoque
 *   do Plano 17. A entrega estornada sai dos alertas e **continua** na extração
 *   por período, marcada como estornada — apagá-la seria apagar o rastro do
 *   próprio erro. Por isso a trait `Auditavel` está aqui e não no cadastro de
 *   EPI: estorno e devolução precisam deixar registro de quem alterou.
 * - **As duas validades são independentes.** `validade_ca` é do certificado do
 *   fabricante, copiada do cadastro; `trocar_ate` é do item que este técnico
 *   tem na mão, calculado de `vida_util_dias` no ato da entrega (Task 28.2).
 *   Renovar o certificado não adia a troca do respirador usado há dois anos, e
 *   trocar o respirador não renova certificado nenhum.
 *
 * Casts de data: `entregue_em`, `validade_ca`, `trocar_ate` e `devolvido_em`
 * são dias, sem hora relevante, e nunca sofrem conversão de fuso; `assinado_em`
 * e `estornada_em` são instantes, gravados em UTC e convertidos só na exibição,
 * porque precisam de ordem entre si. Toda comparação de vencimento é feita por
 * dia no fuso do negócio, via `App\Support\BusinessDate`, nunca contra `now()`
 * em UTC.
 *
 * A ficha sobrevive ao técnico: o desligamento inativa o técnico e a ficha dele
 * continua consultável e extraível, que é o motivo do `restrictOnDelete` nas
 * duas chaves estrangeiras de domínio.
 */
class PpeDelivery extends Model
{
    use Auditavel, BelongsToCompany;

    /**
     * Motivos aceitos, iguais ao enum da coluna `motivo`.
     *
     * @var array<int, string>
     */
    public const MOTIVOS = [
        'primeira_entrega',
        'substituicao',
        'dano',
        'perda',
        'vencimento',
        'outro',
    ];

    /**
     * Rótulo legível de cada motivo, na mesma ordem de `MOTIVOS`.
     *
     * Mesma razão do `ROTULOS_DE_TIPO` do cadastro de EPI: o rótulo mora junto
     * do enum que descreve, para que a ficha, a extração e qualquer aviso falem
     * o mesmo texto. Quem acrescentar um motivo em `MOTIVOS` acrescenta o rótulo
     * aqui.
     *
     * @var array<string, string>
     */
    public const ROTULOS_DE_MOTIVO = [
        'primeira_entrega' => 'Primeira entrega',
        'substituicao' => 'Substituição',
        'dano' => 'Dano',
        'perda' => 'Perda',
        'vencimento' => 'Vencimento',
        'outro' => 'Outro',
    ];

    protected $fillable = [
        'technician_id',
        'personal_protective_equipment_id',
        'quantidade',
        'entregue_em',
        'motivo',
        'numero_ca',
        'validade_ca',
        'trocar_ate',
        'assinatura_path',
        'assinado_em',
        'devolvido_em',
        'motivo_devolucao',
        'estornada_em',
        'motivo_estorno',
        'user_id',
    ];

    protected $casts = [
        'quantidade' => 'integer',
        // Dias sem hora relevante: nunca sofrem conversão de fuso.
        'entregue_em' => 'date',
        'validade_ca' => 'date',
        'trocar_ate' => 'date',
        'devolvido_em' => 'date',
        // Instantes: gravados em UTC, convertidos para o fuso do negócio só na
        // exibição, via BusinessDate.
        'assinado_em' => 'datetime',
        'estornada_em' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Técnico que recebeu o equipamento.
     */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    /**
     * Modelo de EPI entregue.
     *
     * Serve para exibir nome, tipo e fabricante. O CA da ficha **não** vem
     * daqui: ele está copiado nas colunas `numero_ca` e `validade_ca` deste
     * próprio registro.
     */
    public function personalProtectiveEquipment(): BelongsTo
    {
        return $this->belongsTo(PersonalProtectiveEquipment::class);
    }

    /**
     * Quem registrou a entrega.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
