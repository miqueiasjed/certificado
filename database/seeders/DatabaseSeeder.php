<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use App\Support\TenantAtual;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Semeia o banco dentro de um tenant explícito.
     *
     * Seeder roda sem usuário autenticado, então `TenantAtual::id()` devolveria
     * `null` e todo registro criado aqui nasceria sem empresa resolvida,
     * dependendo do `DEFAULT 1` da coluna para não estourar. Fixar a empresa
     * antes de semear troca esse acidente por uma decisão: o dado de exemplo
     * pertence à empresa fundadora, e continuará pertencendo depois que a
     * Task 4.8 ligar o escopo global nos models.
     *
     * Os seeders chamados por `call()` herdam o tenant, porque `comTenant()`
     * vale para toda a pilha de chamada. Rodar um seeder de domínio sozinho
     * (`php artisan db:seed --class=ClientSeeder`) não passa por aqui e volta a
     * ficar sem tenant: nesse caso, informe a empresa na chamada.
     */
    public function run(): void
    {
        $empresa = $this->empresaFundadora();

        TenantAtual::comTenant($empresa->id, function (): void {
            // Criar usuário administrador
            User::create([
                'name' => 'Administrador',
                'email' => 'admin@admin.com',
                'password' => Hash::make('12345678'),
                'email_verified_at' => now(),
            ]);

            // Executar seeders específicos
            $this->call([
                RolesAndPermissionsSeeder::class,
                TechnicianSeeder::class,
                ServiceSeeder::class,
                ClientSeeder::class,
                WorkOrderSeeder::class,
            ]);
        });
    }

    /**
     * Empresa mais antiga do banco, criando uma quando não existe nenhuma.
     *
     * Em banco novo a migration de fundação do tenant já deixou a empresa 1
     * pronta; o `create()` aqui só cobre o banco em que ela foi removida à mão.
     */
    private function empresaFundadora(): Company
    {
        $empresa = Company::query()->orderBy('id')->first();

        if ($empresa !== null) {
            return $empresa;
        }

        $this->command?->warn('Nenhuma empresa cadastrada. Criando a empresa fundadora para semear o banco.');

        return Company::create(['name' => 'Empresa fundadora']);
    }
}
