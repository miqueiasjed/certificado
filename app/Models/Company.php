<?php

namespace App\Models;

use App\Support\TenantAtual;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

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

        // Identidade visual do portal do cliente (Plano 15, Task 15.6).
        // Nenhum formulário grava aqui ainda: ver o docblock da migration
        // `add_cores_de_marca_to_companies_table`.
        'cor_primaria',
        'cor_destaque',

        // Identificador estável na URL da página pública de agendamento
        // (Plano 16, Task 16.1). Nasce nulo e só é preenchido quando o
        // tenant ativa a página pública: ver o docblock da migration
        // `add_slug_publico_to_companies_table`.
        'slug_publico',

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
     * Identidade visual e dados de contato deste tenant, para o portal do
     * cliente (Plano 15, Task 15.6).
     *
     * Duas chamadoras: o compartilhamento Inertia de toda página autenticada
     * do portal (`HandleInertiaRequests`, prop `empresa`) e a tela pública de
     * definir senha, que já resolve o tenant pelo token antes mesmo do login
     * (`PortalAuthController::showDefinirSenha()`).
     *
     * `cor_primaria`/`cor_destaque` saem exatamente como estão no banco, sem
     * validação de formato aqui: quem decide se é hexadecimal válido e cai no
     * verde padrão do sistema é o frontend
     * (`resources/js/utils/corDoPortal.js`), a única camada que de fato
     * interpola o valor em CSS. Validar aqui não reduziria risco nenhum, e só
     * duplicaria a regra.
     *
     * @return array{nome: string, logo_url: string|null, cor_primaria: string|null, cor_destaque: string|null, telefone: string|null, email: string|null}
     */
    public function brandingDoPortal(): array
    {
        return [
            'nome' => $this->name,
            'logo_url' => filled($this->logo_path) ? Storage::disk('public')->url($this->logo_path) : null,
            'cor_primaria' => $this->cor_primaria,
            'cor_destaque' => $this->cor_destaque,
            'telefone' => $this->phone,
            'email' => $this->email,
        ];
    }

    /**
     * Get full formatted address
     */
    public function getFullAddressAttribute()
    {
        $parts = [];
        if ($this->street) {
            $parts[] = $this->street;
        }
        if ($this->number) {
            $parts[] = $this->number;
        }
        if ($this->district) {
            $parts[] = $this->district;
        }
        if ($this->city) {
            $parts[] = "{$this->city}/{$this->state}";
        }
        if ($this->zip) {
            $parts[] = "CEP: {$this->zip}";
        }

        return implode(', ', $parts);
    }
}
