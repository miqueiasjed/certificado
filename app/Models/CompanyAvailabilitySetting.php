<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Configuração de agendamento online da empresa (Plano 16, Task 16.2): dias de
 * atendimento, teto de visitas por período por técnico, antecedência mínima e
 * janela máxima que `App\Services\AvailabilityService` usa para montar a
 * grade de horários da página pública.
 *
 * Uma linha por empresa (`company_id` único, ver a migration). Empresa sem
 * linha aqui não está mal configurada: significa que nunca abriu a tela de
 * configuração, e `AvailabilityService` aplica o padrão do sistema
 * (`padrao()`) exatamente como se a linha existisse com esses valores.
 */
class CompanyAvailabilitySetting extends Model
{
    use BelongsToCompany;

    /**
     * Dias da semana no padrão ISO-8601 usado pelo Carbon (`dayOfWeekIso`):
     * 1 = segunda, ..., 7 = domingo. Padrão do sistema: segunda a sexta.
     *
     * @var array<int, int>
     */
    public const DIAS_PADRAO = [1, 2, 3, 4, 5];

    /**
     * Teto padrão de visitas por técnico por período (manhã ou tarde).
     */
    public const VISITAS_POR_PERIODO_PADRAO = 4;

    /**
     * Antecedência mínima padrão, em dias, para um pedido de horário.
     */
    public const ANTECEDENCIA_MINIMA_PADRAO_DIAS = 2;

    /**
     * Janela máxima padrão, em dias, a partir de hoje, para oferecer horário.
     */
    public const JANELA_MAXIMA_PADRAO_DIAS = 60;

    protected $fillable = [
        'dias_da_semana',
        'visitas_por_periodo',
        'antecedencia_minima_dias',
        'janela_maxima_dias',
        'aceita_agendamento_online',
    ];

    protected $casts = [
        'dias_da_semana' => 'array',
        'visitas_por_periodo' => 'integer',
        'antecedencia_minima_dias' => 'integer',
        'janela_maxima_dias' => 'integer',
        'aceita_agendamento_online' => 'boolean',
    ];

    /**
     * Valores aplicados quando a empresa não tem configuração salva.
     *
     * `App\Services\AvailabilityService` lê exatamente este array quando a
     * consulta a esta tabela não encontra linha para a empresa, para que o
     * padrão do sistema exista em um lugar só.
     *
     * @return array{dias_da_semana: array<int, int>, visitas_por_periodo: int, antecedencia_minima_dias: int, janela_maxima_dias: int, aceita_agendamento_online: bool}
     */
    public static function padrao(): array
    {
        return [
            'dias_da_semana' => self::DIAS_PADRAO,
            'visitas_por_periodo' => self::VISITAS_POR_PERIODO_PADRAO,
            'antecedencia_minima_dias' => self::ANTECEDENCIA_MINIMA_PADRAO_DIAS,
            'janela_maxima_dias' => self::JANELA_MAXIMA_PADRAO_DIAS,
            'aceita_agendamento_online' => false,
        ];
    }
}
