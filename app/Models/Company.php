<?php

namespace App\Models;

use App\Support\TenantAtual;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    protected $fillable = [
        'name',
        'cnpj',
        'email',
        'phone',
        'street',
        'number',
        'complement',
        'district',
        'city',
        'state',
        'zip',
        'crq',
        'license_environmental',
        'license_sanitary',
        'license_business',
        'register_visa',
        'register_crea',
        'ceatox_info',
        'operational_manager_name',
        'operational_manager_title',
        'technical_responsible_name',
        'technical_responsible_title',
        'logo_path',
        'signature_operational_path',
        'signature_chemical_path',
        'signature_responsible_path',

        // Campos de plataforma (Plano 5). Só a área do super admin escreve
        // neles; o único endpoint de autoatendimento que atualiza a empresa
        // (`CompanyController::update`) valida com whitelist explícita, então
        // nenhum deles é alcançável pelo formulário do tenant.
        'plan_id',
        'situacao',
        'is_internal',
        'trial_ends_at',
        'suspensa_em',
        'motivo_suspensao',
        'ultimo_acesso_em',
    ];

    protected $casts = [
        'is_internal' => 'boolean',
        'trial_ends_at' => 'date',
        'suspensa_em' => 'datetime',
        'ultimo_acesso_em' => 'datetime',
    ];

    /**
     * Empresa (tenant) do contexto corrente.
     *
     * Antes do Plano 4 este método era `firstOrCreate(['id' => 1])`, o que fazia
     * sentido enquanto existia uma empresa só: qualquer tela que precisasse do
     * cabeçalho de um PDF criava a linha se ela faltasse. Com vários tenants
     * isso vira defeito grave em duas frentes. Uma requisição de leitura passa a
     * poder gravar no banco, e o registro criado nasce sem dono definido, o que
     * é exatamente o tipo de linha que depois aparece na empresa errada.
     *
     * A empresa agora vem de `TenantAtual`, que resolve pelo tenant explícito
     * (comando artisan, seeder, job de fila, "assumir tenant" do super admin) ou
     * pelo `company_id` do usuário autenticado. Sem nenhum dos dois, `exigirId()`
     * lança `RuntimeException` com a instrução do que fazer, e `findOrFail()`
     * lança `ModelNotFoundException` quando o usuário aponta para uma empresa
     * que não existe mais.
     *
     * Falhar é o comportamento desejado. Devolver a empresa 1 por padrão seria o
     * caminho mais curto para um tenant emitir documento com o cabeçalho de
     * outro, e documento emitido aqui tem valor perante fiscalização.
     *
     * Fundação do tenant em banco novo não passa por aqui: quem cria a primeira
     * empresa é a migration
     * `2026_07_26_155000_seed_founding_company_for_fresh_installs`, com insert
     * direto e apenas quando `companies` está vazia.
     *
     * @throws \RuntimeException Quando não há tenant resolvido.
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException Quando o tenant resolvido não existe em `companies`.
     */
    public static function current(): self
    {
        return static::findOrFail(TenantAtual::exigirId());
    }

    /**
     * Usuários que pertencem a esta empresa.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Plano comercial contratado.
     *
     * Nulo enquanto o super admin não define um plano para o tenant, e nulo
     * também quando o plano é excluído do catálogo (`nullOnDelete`). Quem lê
     * limites precisa tratar o caso: empresa sem plano não é empresa sem
     * limite.
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Get full formatted address
     */
    public function getFullAddressAttribute()
    {
        $parts = [];
        if ($this->street)
            $parts[] = $this->street;
        if ($this->number)
            $parts[] = $this->number;
        if ($this->district)
            $parts[] = $this->district;
        if ($this->city)
            $parts[] = "{$this->city}/{$this->state}";
        if ($this->zip)
            $parts[] = "CEP: {$this->zip}";

        return implode(', ', $parts);
    }
}
