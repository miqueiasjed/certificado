<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Configuração por tenant da emissão recorrente de cobrança e da régua de
 * cobrança (Plano 19, Task 19.5): ligar/desligar a régua, escolher os
 * marcos ativos e a antecedência da emissão automática de cobrança de
 * parcela de contrato.
 *
 * Uma linha por empresa (`company_id` único, ver a migration). Empresa sem
 * linha aqui não está mal configurada: significa que nunca abriu a tela de
 * configuração (Task 19.7), e `atual()` devolve os valores padrão do
 * sistema exatamente como se a linha existisse com eles — mesmo critério de
 * `App\Models\CompanyAvailabilitySetting::padrao()`.
 *
 * Este model não conhece os nomes dos marcos da régua (`antes_3`, `no_dia`,
 * `depois_3`...): esse vocabulário é de
 * `App\Services\Payments\ReguaDeCobrancaService`, que é quem decide o que
 * significa `marcos_ativos` nulo (todos os marcos valem). Manter o
 * vocabulário fora do model evita que uma migration nova seja necessária
 * só porque um marco foi renomeado ou um marco novo entrou no catálogo.
 */
class CompanyBillingSetting extends Model
{
    use BelongsToCompany;

    /**
     * Antecedência padrão, em dias, da emissão recorrente de cobrança. É o
     * prazo mencionado na especificação da Task 19.5: nem tão cedo que o
     * cliente esqueça o boleto, nem tão em cima da hora que ele não tenha
     * tempo de pagar.
     */
    public const ANTECEDENCIA_EMISSAO_PADRAO_DIAS = 10;

    protected $fillable = [
        'regua_ativa',
        'marcos_ativos',
        'antecedencia_emissao_dias',
        'emissao_pelo_cliente_habilitada',
    ];

    protected $casts = [
        'regua_ativa' => 'boolean',
        'marcos_ativos' => 'array',
        'antecedencia_emissao_dias' => 'integer',
        'emissao_pelo_cliente_habilitada' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Configuração do tenant corrente, ou uma instância com os valores
     * padrão do sistema quando a empresa nunca configurou nada. Só leitura:
     * nunca grava a linha padrão no banco, mesmo critério de
     * `CompanyAvailabilitySetting::padrao()` (lá devolvida como array; aqui
     * como instância, para os métodos `reguaAtiva()`/`antecedenciaEmissaoDias()`
     * ficarem disponíveis nos dois casos, com linha salva ou sem ela).
     */
    public static function atual(): self
    {
        return static::query()->first() ?? new self([
            'regua_ativa' => true,
            'marcos_ativos' => null,
            'antecedencia_emissao_dias' => self::ANTECEDENCIA_EMISSAO_PADRAO_DIAS,
            'emissao_pelo_cliente_habilitada' => false,
        ]);
    }

    /**
     * A régua de cobrança está ligada para este tenant?
     */
    public function reguaAtiva(): bool
    {
        return $this->regua_ativa ?? true;
    }

    /**
     * Antecedência da emissão recorrente, em dias.
     */
    public function antecedenciaEmissaoDias(): int
    {
        return $this->antecedencia_emissao_dias ?? self::ANTECEDENCIA_EMISSAO_PADRAO_DIAS;
    }

    /**
     * Marcos ativos da régua, ou `null` quando a empresa não restringiu
     * nenhum: significa que todos os marcos do catálogo valem. Quem decide
     * quais chaves são marcos válidos, e o que fazer com o `null`, é
     * `App\Services\Payments\ReguaDeCobrancaService`.
     *
     * @return array<int, string>|null
     */
    public function marcosAtivos(): ?array
    {
        return $this->marcos_ativos;
    }

    /**
     * O cliente final pode gerar a própria cobrança pelo portal (Plano 19,
     * Task 19.6, `POST /portal/faturas/{parcela}/cobranca`)? Opt-in por
     * tenant, e nunca o padrão: empresa sem linha aqui, ou com a linha mas
     * sem marcar esta opção, mantém a emissão reservada à própria empresa.
     */
    public function emissaoPeloClienteHabilitada(): bool
    {
        return $this->emissao_pelo_cliente_habilitada ?? false;
    }
}
