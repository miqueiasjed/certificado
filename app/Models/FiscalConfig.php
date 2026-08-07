<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * Configuração do provedor de NFS-e de um tenant.
 *
 * As credenciais ficam cifradas no banco pelo cast do Eloquent e ocultas de
 * qualquer serialização do model.
 */
class FiscalConfig extends Model
{
    use BelongsToCompany;

    public const AMBIENTES = ['homologacao', 'producao'];

    private const CAMPOS_IMUTAVEIS_APOS_USO = [
        'provedor',
        'ambiente',
        'credenciais',
        'regime_tributario',
        'codigo_servico',
        'cnae',
        'aliquota_iss',
        'iss_retido',
        'natureza_operacao',
        'serie',
        'proximo_numero',
        'exige_inscricao_municipal_tomador',
    ];

    protected $fillable = [
        'provedor',
        'ambiente',
        'credenciais',
        'regime_tributario',
        'codigo_servico',
        'cnae',
        'aliquota_iss',
        'iss_retido',
        'natureza_operacao',
        'serie',
        'proximo_numero',
        'emissao_automatica',
        'gatilho_emissao_automatica',
        'exige_inscricao_municipal_tomador',
        'ativo',
    ];

    protected $casts = [
        'credenciais' => 'encrypted:array',
        'aliquota_iss' => 'decimal:2',
        'iss_retido' => 'boolean',
        'proximo_numero' => 'integer',
        'emissao_automatica' => 'boolean',
        'exige_inscricao_municipal_tomador' => 'boolean',
        'ativo' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $hidden = [
        'credenciais',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $configuracao): void {
            if ($configuracao->ativo) {
                $outrasAtivas = self::query()->where('ativo', true);

                if ($configuracao->exists) {
                    $outrasAtivas->whereKeyNot($configuracao->getKey());
                }

                if ($outrasAtivas->exists()) {
                    throw new RuntimeException('A empresa já possui uma configuração fiscal ativa. Desative a atual antes de ativar outra.');
                }
            }

            if (! $configuracao->exists
                || ! $configuracao->isDirty(self::CAMPOS_IMUTAVEIS_APOS_USO)) {
                return;
            }

            if ($configuracao->serviceInvoices()->exists()) {
                throw new RuntimeException(
                    'A configuração fiscal já foi usada por uma nota e seus dados fiscais não podem ser alterados. Crie outra configuração para mudar provedor, credenciais ou dados do contrato fiscal.'
                );
            }
        });
    }

    public function serviceInvoices(): HasMany
    {
        return $this->hasMany(ServiceInvoice::class);
    }
}
