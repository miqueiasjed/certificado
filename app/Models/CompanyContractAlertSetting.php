<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Configuração por tenant do prazo de alerta de contrato a vencer (Plano 23,
 * Task 23.5): quantos dias antes do fim da vigência cada marco de aviso
 * dispara. Ver o cabeçalho da migration
 * `2026_08_07_100014_create_company_contract_alert_settings_table` para a
 * decisão de modelagem.
 *
 * Uma linha por empresa (`company_id` único). Empresa sem linha aqui não está
 * mal configurada: significa que nunca abriu a tela de configuração (Task
 * 23.7/23.8), e `atual()` devolve os marcos padrão do sistema exatamente como
 * se a linha existisse com eles, mesmo critério de
 * `App\Models\CompanyBillingSetting::atual()`.
 */
class CompanyContractAlertSetting extends Model
{
    use BelongsToCompany;

    /**
     * Marcos padrão do sistema, da antecedência mais distante para a mais
     * urgente. Três marcos, mesmo critério de `marcos_certificado_a_vencer`:
     * o mais distante dá tempo de agendar a renovação, e o mais próximo é o
     * empurrão de quem deixou passar.
     *
     * @var array<int, int>
     */
    public const MARCOS_PADRAO = [60, 30, 15];

    protected $fillable = [
        'dias_antecedencia',
    ];

    protected $casts = [
        'dias_antecedencia' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Configuração do tenant corrente, ou uma instância com os marcos padrão
     * do sistema quando a empresa nunca configurou nada. Só leitura: nunca
     * grava a linha padrão no banco, mesmo critério de
     * `CompanyBillingSetting::atual()`.
     *
     * Resolve pelo tenant corrente através do escopo global de
     * `BelongsToCompany`, então só produz o valor certo dentro de
     * `TenantAtual::comTenant()` ou de uma requisição autenticada.
     */
    public static function atual(): self
    {
        return static::query()->first() ?? new self(['dias_antecedencia' => null]);
    }

    /**
     * Marcos de antecedência configurados, normalizados (inteiros positivos,
     * sem repetição, do maior para o menor), ou os marcos padrão quando a
     * empresa não personalizou nada ou configurou uma lista vazia/inválida.
     *
     * @return array<int, int>
     */
    public function marcos(): array
    {
        $marcos = $this->dias_antecedencia;

        if (! is_array($marcos) || $marcos === []) {
            return self::MARCOS_PADRAO;
        }

        $marcos = array_values(array_unique(array_map(
            static fn (mixed $dias): int => (int) $dias,
            $marcos
        )));

        $marcos = array_values(array_filter($marcos, static fn (int $dias): bool => $dias > 0));

        if ($marcos === []) {
            return self::MARCOS_PADRAO;
        }

        rsort($marcos);

        return $marcos;
    }
}
