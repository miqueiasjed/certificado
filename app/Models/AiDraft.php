<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Rascunho de texto gerado por modelo de linguagem (Plano 25).
 *
 * Nasce em `situacao = gerado` e só chega a `revisado` por ação explícita de
 * uma pessoa. Laudo técnico tem responsabilidade profissional: o responsável
 * técnico assina o que sai, e publicação automática de parecer gerado por
 * modelo transferiria para o cliente um risco que não é dele.
 *
 * `conteudo_gerado` nunca é sobrescrito. A revisão grava em
 * `conteudo_revisado`, e é a diferença entre as duas colunas que prova a
 * revisão humana perante uma auditoria sobre a autoria do documento. Por isso
 * o model leva `Auditavel`: quem revisou, quando e o que mudou precisa ficar
 * registrado.
 *
 * `origem` é polimórfica porque o mesmo rascunho serve a quatro destinos
 * diferentes: ordem de serviço, certificado, relatório de monitoramento e
 * orçamento.
 */
class AiDraft extends Model
{
    use Auditavel, BelongsToCompany;

    /** Parecer técnico da ordem de serviço. */
    public const TIPO_PARECER_OS = 'parecer_os';

    /** Parecer técnico do certificado. */
    public const TIPO_PARECER_CERTIFICADO = 'parecer_certificado';

    /** Resumo do período para o relatório de monitoramento (Plano 21). */
    public const TIPO_RESUMO_MONITORAMENTO = 'resumo_monitoramento';

    /** Justificativa em texto da sugestão de preço do orçamento. */
    public const TIPO_SUGESTAO_PRECO = 'sugestao_preco';

    /** Recém-gerado pelo modelo, sem nenhuma leitura humana. */
    public const SITUACAO_GERADO = 'gerado';

    /** Alguém abriu e está editando, mas ainda não aprovou. */
    public const SITUACAO_EM_REVISAO = 'em_revisao';

    /** Revisado e aprovado por uma pessoa: só aqui pode alimentar documento. */
    public const SITUACAO_REVISADO = 'revisado';

    /** Descartado por quem revisou: não serve a documento nenhum. */
    public const SITUACAO_DESCARTADO = 'descartado';

    /**
     * @return array<int, string>
     */
    public static function tipos(): array
    {
        return [
            self::TIPO_PARECER_OS,
            self::TIPO_PARECER_CERTIFICADO,
            self::TIPO_RESUMO_MONITORAMENTO,
            self::TIPO_SUGESTAO_PRECO,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function situacoes(): array
    {
        return [
            self::SITUACAO_GERADO,
            self::SITUACAO_EM_REVISAO,
            self::SITUACAO_REVISADO,
            self::SITUACAO_DESCARTADO,
        ];
    }

    protected $fillable = [
        'tipo',
        'origem_tipo',
        'origem_id',
        'conteudo_gerado',
        'conteudo_revisado',
        'situacao',
        'modelo',
        'gerado_por',
        'revisado_por',
        'revisado_em',
    ];

    protected $casts = [
        'origem_id' => 'integer',
        // Instante da revisão: gravado em UTC como todo instante do projeto e
        // convertido para America/Sao_Paulo só na exibição, via BusinessDate.
        'revisado_em' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Registro que originou o rascunho: OS, certificado, relatório de
     * monitoramento ou orçamento.
     */
    public function origem(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'origem_tipo', 'origem_id');
    }

    /**
     * Usuário que disparou a geração.
     */
    public function geradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'gerado_por');
    }

    /**
     * Usuário que revisou e aprovou o texto, quando já houve revisão.
     */
    public function revisadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revisado_por');
    }

    /**
     * Chamadas ao modelo que este rascunho custou.
     */
    public function usos(): HasMany
    {
        return $this->hasMany(AiUsage::class);
    }

    /**
     * Texto que vale hoje: o revisado quando existe, o gerado enquanto não
     * houve revisão. Accessor de leitura, nunca grava.
     */
    public function getConteudoAtualAttribute(): string
    {
        return $this->conteudo_revisado ?? $this->conteudo_gerado;
    }

    /**
     * O texto já passou por revisão humana aprovada?
     */
    public function getRevisadoAttribute(): bool
    {
        return $this->situacao === self::SITUACAO_REVISADO;
    }
}
