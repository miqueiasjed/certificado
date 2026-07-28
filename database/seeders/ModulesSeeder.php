<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Module;
use App\Models\Plan;
use App\Support\CatalogoDeModulos;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Semeia o catálogo de módulos controláveis (Plano 6, Task 6.2) e garante o
 * plano interno do tenant fundador.
 *
 * Fonte única do catálogo: `CatalogoDeModulos::catalogo()`. Este seeder nunca
 * redeclara chave, nome ou `sempre_ativo` de módulo nenhum, no mesmo critério
 * já usado por `RolesAndPermissionsSeeder` com `SyncPermissions::catalogo()`.
 *
 * Idempotente por design: rodar duas vezes não duplica módulo, não duplica
 * vínculo em `plan_module` (o `updateOrCreate` casa pela chave/slug e o
 * `syncWithoutDetaching` não insere linha repetida) e não recria o plano
 * interno se ele já existir.
 */
class ModulesSeeder extends Seeder
{
    /**
     * Id do tenant fundador, o mesmo de `Company::current()` antes do Plano 4
     * e o mesmo marcado `is_internal = true` na Task 5.1.
     */
    private const TENANT_FUNDADOR = 1;

    private const SLUG_PLANO_INTERNO = 'interno';

    public function run(): void
    {
        $modulos = $this->sincronizarModulos();
        $this->avisarModulosForaDoCatalogo($modulos);

        $planoInterno = $this->planoInterno();
        $this->vincularModulosAoPlano($planoInterno, $modulos);
        $this->vincularTenantFundador($planoInterno);

        $this->command?->info(sprintf(
            'Catálogo de módulos sincronizado: %d módulo(s), plano "%s" com todos vinculados, tenant #%d no plano interno.',
            $modulos->count(),
            $planoInterno->nome,
            self::TENANT_FUNDADOR
        ));
    }

    /**
     * `updateOrCreate` por `chave`: cria o que falta e corrige nome ou
     * `sempre_ativo` do que já existe, sem nunca duplicar. `ordem` é gravada
     * pela posição no catálogo, que é a ordem de exibição.
     *
     * @return Collection<int, Module>
     */
    private function sincronizarModulos(): Collection
    {
        return collect(CatalogoDeModulos::catalogo())
            ->values()
            ->map(function (array $modulo, int $indice): Module {
                return Module::query()->updateOrCreate(
                    ['chave' => $modulo['chave']],
                    [
                        'nome' => $modulo['nome'],
                        'descricao' => $modulo['descricao'],
                        'sempre_ativo' => $modulo['sempre_ativo'],
                        'ordem' => $indice,
                    ]
                );
            });
    }

    /**
     * Avisa, sem apagar, sobre módulo que existe em `modules` mas saiu do
     * catálogo. Mesmo critério de `SyncPermissions::tratarOrfas`: remover
     * sozinho apagaria em cascata o vínculo de plano e de liberação pontual
     * do tenant em produção. Quem decide excluir é uma pessoa, olhando o
     * aviso, não o seeder.
     *
     * @param  Collection<int, Module>  $modulosDoCatalogo
     */
    private function avisarModulosForaDoCatalogo(Collection $modulosDoCatalogo): void
    {
        $chavesCatalogo = $modulosDoCatalogo->pluck('chave')->all();

        $orfaos = Module::query()
            ->whereNotIn('chave', $chavesCatalogo)
            ->pluck('chave');

        if ($orfaos->isEmpty()) {
            return;
        }

        $this->command?->warn(sprintf(
            'Módulo(s) no banco fora do catálogo (mantido, não apagado — avalie remover manualmente): %s',
            $orfaos->implode(', ')
        ));
    }

    /**
     * Plano interno do tenant fundador: valor zero, os quatro limites nulos
     * (ilimitado, no critério já documentado em `Plan`) e `ativo = false`
     * porque não é plano à venda. Ele existe para que o cliente atual não
     * perceba a chegada dos planos comerciais, não para aparecer na vitrine
     * nem na lista de planos de um tenant novo (`TenantController::create`
     * usa `Plan::ativos()`).
     *
     * `updateOrCreate` pelo `slug`: se o plano já existir (rodada anterior
     * deste seeder, ou criado à mão), só corrige os campos, nunca duplica.
     */
    private function planoInterno(): Plan
    {
        return Plan::query()->updateOrCreate(
            ['slug' => self::SLUG_PLANO_INTERNO],
            [
                'nome' => 'Interno',
                'valor' => 0,
                'limite_usuarios' => null,
                'limite_clientes' => null,
                'limite_os_mes' => null,
                'limite_armazenamento_mb' => null,
                'ativo' => false,
                'descricao' => 'Plano interno do tenant fundador. Libera todos os módulos, sem cobrança, e não aparece na vitrine de planos.',
            ]
        );
    }

    /**
     * Vincula todos os módulos do catálogo ao plano interno. `syncWithoutDetaching`
     * pelo lado de `Module::plans()` (a relação já existe desde a Task 6.1;
     * `Plan` não precisa de uma relação `modules()` própria para este seeder):
     * o vínculo só soma, nunca remove um módulo que outro plano também libere.
     *
     * @param  Collection<int, Module>  $modulos
     */
    private function vincularModulosAoPlano(Plan $planoInterno, Collection $modulos): void
    {
        foreach ($modulos as $modulo) {
            $modulo->plans()->syncWithoutDetaching([$planoInterno->id]);
        }
    }

    /**
     * Garante que o tenant fundador está no plano interno. Idempotente: se já
     * estiver, não gera update nenhum.
     */
    private function vincularTenantFundador(Plan $planoInterno): void
    {
        $tenant = Company::find(self::TENANT_FUNDADOR);

        if ($tenant === null) {
            $this->command?->warn(sprintf(
                'Tenant fundador #%d não encontrado; plano interno criado, mas não há empresa para vincular.',
                self::TENANT_FUNDADOR
            ));

            return;
        }

        if ($tenant->plan_id !== $planoInterno->id) {
            $tenant->update(['plan_id' => $planoInterno->id]);
        }
    }
}
