<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Consentimento e configuração do rastreamento contínuo do técnico em campo
 * (Plano 22, Task 22.4). Ver o cabeçalho da migration
 * `2026_08_07_100009_create_technician_tracking_settings_table` para a
 * distinção entre linha geral do tenant (`technician_id = null`) e override
 * por técnico, e para a diferença deliberada em relação ao consentimento raso
 * de localização da assinatura (Plano 13, Task 13.9).
 *
 * Não está listado no arquivo da Task 22.4 (que cita só a migration e os dois
 * Services), mas é necessário para `LocalDeExecucaoService` funcionar - mesmo
 * critério já usado por outras tasks deste plano que precisaram criar o model
 * implícito de uma tabela pedida na especificação.
 */
class TechnicianTrackingSetting extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'technician_id',
        'rastreamento_continuo',
        'consentido_em',
        'consentido_por',
        'intervalo_minutos',
    ];

    protected $casts = [
        'rastreamento_continuo' => 'boolean',
        // Instante: gravado em UTC, convertido para o fuso do negócio só na
        // exibição.
        'consentido_em' => 'datetime',
        'intervalo_minutos' => 'integer',
    ];

    /**
     * Guarda a regra de negócio inegociável do Plano 22 no ponto único de
     * gravação: rastreamento contínuo nunca liga sem os dois lados do
     * consentimento registrados.
     *
     * Vive aqui, e não só em `LocalDeExecucaoService::ligarRastreamentoContinuo()`,
     * porque a tabela é o que "formaliza" o consentimento (ver docblock da
     * migration); um `update()` direto que escapasse do método do Service
     * ainda precisa ser barrado no ponto de gravação, mesmo padrão já usado
     * em `FiscalConfig::booted()` (única configuração fiscal ativa por
     * empresa) para invariante de dado que não pode depender só da disciplina
     * de quem chama.
     */
    protected static function booted(): void
    {
        static::saving(function (self $configuracao): void {
            if (! $configuracao->rastreamento_continuo) {
                return;
            }

            if ($configuracao->consentido_em === null || $configuracao->consentido_por === null) {
                throw new RuntimeException(
                    'Rastreamento contínuo só pode ser ligado com o consentimento do técnico registrado (consentido_em e consentido_por).'
                );
            }
        });
    }

    /**
     * Técnico dono deste override. Nulo na linha geral do tenant.
     */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    /**
     * Usuário que registrou o consentimento do técnico.
     */
    public function consentidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consentido_por');
    }
}
