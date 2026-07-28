<?php

namespace Database\Seeders;

use App\Models\ActiveIngredient;
use App\Models\Antidote;
use App\Models\BaitType;
use App\Models\ChemicalGroup;
use App\Models\Company;
use App\Models\EventType;
use App\Models\OrganRegistration;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceType;
use App\Support\CatalogoInicialDoTenant;
use App\Support\TenantAtual;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Aplica `App\Support\CatalogoInicialDoTenant` a um tenant. É o cadastro de
 * apoio e regulatório com que toda empresa nova precisa chegar operável
 * (Plano 8): sem ele, a primeira ordem de serviço ou o primeiro certificado
 * não tem tipo de evento, tipo de serviço ou princípio ativo para escolher.
 *
 * Idempotente por `firstOrCreate` pela chave natural de cada cadastro (`name`
 * ou `record`, conforme o model). Rodar duas vezes não duplica nada.
 *
 * Quem chama isto
 * ----------------
 * - Manual/teste: `php artisan db:seed --class=CatalogoInicialSeeder`, sem
 *   nenhum outro tenant fixado antes. Neste caso o alvo é resolvido em
 *   {@see empresaAlvo()}: a empresa mais recente do banco, por ser o caso de
 *   uso natural de rodar este comando à mão (semear o tenant que acabou de
 *   ser criado). Sem nenhuma empresa cadastrada, a execução falha alto:
 *   catálogo sem empresa para receber os registros é bug, não silêncio.
 * - `ProvisionamentoService` (Task 8.3): chama isto de dentro de um
 *   `TenantAtual::comTenant($empresa->id, ...)` que ele mesmo já abriu. Como
 *   `comTenant()` aninha e restaura sozinho (ver `TenantAtual`), este seeder
 *   detecta o tenant já fixado e usa exatamente ele, sem tentar adivinhar a
 *   empresa mais recente por cima da decisão de quem chamou.
 *
 * Cadastros regulatórios são cópia, não referência
 * -------------------------------------------------
 * Cada `firstOrCreate` grava uma linha nova, presa ao `company_id` do tenant
 * corrente (preenchido sozinho por `BelongsToCompany`). A partir daí a
 * empresa edita o próprio cadastro sem afetar nenhuma outra: nenhum registro
 * do tenant 1 (ou de qualquer tenant já existente) é lido, movido ou alterado
 * por este seeder.
 *
 * `unidades()` não é aplicado
 * ----------------------------
 * `CatalogoInicialDoTenant::unidades()` existe para documentar a lista fixa de
 * unidades de medida, mas não há tabela nem Eloquent model para ela hoje (ver
 * o docblock da classe). Os outros nove grupos, sim, viram registro no banco.
 */
class CatalogoInicialSeeder extends Seeder
{
    public function run(): void
    {
        $empresa = $this->empresaAlvo();

        TenantAtual::comTenant($empresa->id, function () use ($empresa): void {
            $resumo = $this->aplicarCatalogo();

            $this->command?->info(sprintf(
                'Catálogo inicial aplicado à empresa #%d (%s): %s.',
                $empresa->id,
                $empresa->name !== null && $empresa->name !== '' ? $empresa->name : '(sem nome)',
                collect($resumo)->map(fn (int $quantidade, string $cadastro): string => "{$cadastro}={$quantidade}")->implode(', ')
            ));
        });
    }

    /**
     * Aplica os nove cadastros com model próprio, na ordem em que
     * `CatalogoInicialDoTenant` os declara. Grupos químicos e antídotos são
     * semeados antes de princípios ativos de propósito: são o metadado
     * documental que `principiosAtivos()` referencia por nome (ver docblock
     * da classe sobre a ausência de FK real entre essas tabelas hoje).
     *
     * `produtos` vem por último, e depende dessa ordem de verdade, não por
     * documentação: cada produto amarra por FK um princípio ativo, um grupo
     * químico, um antídoto e um registro em órgão, e todos os quatro precisam
     * já existir no tenant quando `semearProdutos()` roda.
     *
     * @return array<string, int> quantidade de itens do catálogo aplicados por cadastro
     */
    private function aplicarCatalogo(): array
    {
        return [
            'tipos_de_evento' => $this->semearPorNome(EventType::class, CatalogoInicialDoTenant::tiposDeEvento()),
            'tipos_de_isca' => $this->semearPorNome(BaitType::class, CatalogoInicialDoTenant::tiposDeIsca()),
            'tipos_de_servico' => $this->semearPorNome(ServiceType::class, CatalogoInicialDoTenant::tiposDeServico()),
            'servicos' => $this->semearPorNome(Service::class, CatalogoInicialDoTenant::servicos()),
            'grupos_quimicos' => $this->semearPorNome(ChemicalGroup::class, CatalogoInicialDoTenant::gruposQuimicos()),
            'antidotos' => $this->semearPorNome(Antidote::class, CatalogoInicialDoTenant::antidotos()),
            'principios_ativos' => $this->semearPrincipiosAtivos(),
            'registros_em_orgao' => $this->semearRegistrosEmOrgao(),
            'produtos' => $this->semearProdutos(),
        ];
    }

    /**
     * `firstOrCreate` genérico pela chave natural `name`, usado pelos
     * cadastros cujo array do catálogo já corresponde 1:1 aos atributos do
     * model (`EventType`, `BaitType`, `ServiceType`, `ChemicalGroup`,
     * `Antidote`).
     *
     * @param  class-string  $model
     * @param  array<int, array<string, mixed>>  $itens
     */
    private function semearPorNome(string $model, array $itens): int
    {
        foreach ($itens as $item) {
            $model::query()->firstOrCreate(['name' => $item['name']], $item);
        }

        return count($itens);
    }

    /**
     * `ActiveIngredient` só tem `name` no schema atual (ver docblock da
     * classe): `grupo_quimico` e `antidoto` do catálogo ficam de fora do
     * `firstOrCreate` porque a tabela não tem coluna para eles, não porque a
     * informação deixou de importar — ela permanece disponível em
     * `CatalogoInicialDoTenant::principiosAtivos()` para quem precisar dela.
     */
    private function semearPrincipiosAtivos(): int
    {
        $principios = CatalogoInicialDoTenant::principiosAtivos();

        foreach ($principios as $principio) {
            ActiveIngredient::query()->firstOrCreate(['name' => $principio['name']]);
        }

        return count($principios);
    }

    /**
     * `OrganRegistration` usa `record` como chave natural, não `name`.
     */
    private function semearRegistrosEmOrgao(): int
    {
        $registros = CatalogoInicialDoTenant::registrosEmOrgao();

        foreach ($registros as $registro) {
            OrganRegistration::query()->firstOrCreate(['record' => $registro['record']]);
        }

        return count($registros);
    }

    /**
     * `Product` é o único cadastro do catálogo com chave estrangeira de
     * verdade: o produto amarra princípio ativo, grupo químico, antídoto e
     * registro em órgão. O catálogo declara essas referências pela chave
     * natural, e é aqui que elas viram id, sempre dentro do tenant corrente
     * (o escopo global de cada model já filtra por `company_id`, então nunca
     * se aponta para a linha de outra empresa).
     *
     * Referência não encontrada vira `null` na coluna, e não exceção: as
     * quatro FKs são opcionais em `products`, e um produto sem antídoto é
     * melhor do que um provisionamento que falha inteiro porque alguém editou
     * o nome de um cadastro no catálogo sem atualizar a lista de produtos.
     */
    private function semearProdutos(): int
    {
        $produtos = CatalogoInicialDoTenant::produtos();

        foreach ($produtos as $produto) {
            Product::query()->firstOrCreate(['name' => $produto['name']], [
                'active_ingredient_id' => ActiveIngredient::query()->where('name', $produto['principio_ativo'])->value('id'),
                'chemical_group_id' => ChemicalGroup::query()->where('name', $produto['grupo_quimico'])->value('id'),
                'antidote_id' => Antidote::query()->where('name', $produto['antidoto'])->value('id'),
                'organ_registration_id' => OrganRegistration::query()->where('record', $produto['registro_em_orgao'])->value('id'),
            ]);
        }

        return count($produtos);
    }

    /**
     * Tenant a receber o catálogo: o já fixado em `TenantAtual`, quando quem
     * chamou este seeder já abriu um `comTenant()` (caso do
     * `ProvisionamentoService`); senão, a empresa mais recente do banco, que é
     * o alvo natural de `php artisan db:seed --class=CatalogoInicialSeeder`
     * rodado à mão logo depois de criar um tenant de teste.
     */
    private function empresaAlvo(): Company
    {
        if (TenantAtual::definido()) {
            $empresa = Company::query()->find(TenantAtual::id());

            if ($empresa !== null) {
                return $empresa;
            }
        }

        $empresa = Company::query()->orderByDesc('id')->first();

        if ($empresa === null) {
            throw new RuntimeException(
                'Nenhuma empresa cadastrada para aplicar o catálogo inicial. '
                .'Crie o tenant antes de rodar CatalogoInicialSeeder.'
            );
        }

        $this->command?->warn(sprintf(
            'Nenhum tenant fixado explicitamente. Aplicando o catálogo inicial na empresa mais recente: #%d.',
            $empresa->id
        ));

        return $empresa;
    }
}
