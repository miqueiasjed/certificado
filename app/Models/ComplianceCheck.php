<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Resultado da última verificação de um item do checklist de conformidade com
 * a RDC nº 622/2022 (Plano 24, Task 24.1).
 *
 * Uma linha por item por empresa, sobrescrita a cada rodada da verificação:
 * esta tabela guarda o **estado atual**, e não o histórico. O histórico de
 * quem mudou o quê é a auditoria do Plano 3.
 *
 * O checklist **informa, não certifica**. Dizer "sua empresa está regular"
 * seria assumir responsabilidade que não é da plataforma, e a ressalva fica
 * visível no topo da tela (Task 24.6). Nenhuma situação registrada aqui
 * impede concluir OS, assinar ou emitir documento.
 *
 * `SITUACAO_NAO_APLICAVEL` e a **ausência de linha** são o que impede o
 * checklist de acusar de irregular quem apenas não preencheu o cadastro:
 * "não informado" nunca é "irregular".
 *
 * A montagem das situações é regra de negócio do serviço de verificação
 * (Task 24.5), não deste model.
 */
class ComplianceCheck extends Model
{
    use BelongsToCompany;

    public const SITUACAO_REGULAR = 'regular';

    public const SITUACAO_ATENCAO = 'atencao';

    public const SITUACAO_IRREGULAR = 'irregular';

    public const SITUACAO_NAO_APLICAVEL = 'nao_aplicavel';

    /**
     * Situações aceitas, iguais ao enum da coluna `situacao`.
     *
     * @var array<int, string>
     */
    public const SITUACOES = [
        self::SITUACAO_REGULAR,
        self::SITUACAO_ATENCAO,
        self::SITUACAO_IRREGULAR,
        self::SITUACAO_NAO_APLICAVEL,
    ];

    protected $fillable = [
        'item',
        'situacao',
        'detalhe',
        'verificado_em',
    ];

    protected $casts = [
        // Instante em que a verificação rodou: `datetime`, gravado em UTC e
        // convertido na exibição.
        'verificado_em' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
