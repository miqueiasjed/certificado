<?php

namespace Database\Seeders;

use App\Models\NormativeReference;
use Illuminate\Database\Seeder;

/**
 * Referência normativa padrão da plataforma (Plano 24, Task 24.1).
 *
 * Grava a linha com `company_id` nulo, que é a que vale para todo tenant que
 * não cadastrou a própria referência. Idempotente: rodar de novo atualiza o
 * texto em vez de duplicar a linha.
 *
 * Este seeder **não** roda dentro de um tenant, de propósito: a linha da
 * plataforma é justamente a que não pertence a empresa nenhuma. Por isso ele
 * fica fora da lista de `DatabaseSeeder::run()`, que envolve tudo em
 * `TenantAtual::comTenant()`, e é chamado à parte
 * (`php artisan db:seed --class=NormativeReferenceSeeder`).
 *
 * Fonte do texto: RDC nº 622, de 9 de março de 2022 (Anvisa), publicada no DOU
 * de 16/03/2022, em vigor desde 1º de abril de 2022 (art. 25), que revogou a
 * RDC nº 52, de 22 de outubro de 2009, e a RDC nº 20, de 12 de maio de 2010
 * (art. 24).
 */
class NormativeReferenceSeeder extends Seeder
{
    public function run(): void
    {
        NormativeReference::query()->updateOrCreate(
            [
                'company_id' => null,
                'chave' => NormativeReference::CHAVE_PRINCIPAL,
            ],
            [
                'texto' => 'RDC nº 622, de 9 de março de 2022, da Anvisa',
                'texto_curto' => 'RDC nº 622/2022',
                'vigente_desde' => '2022-04-01',
                'ativo' => true,
            ]
        );
    }
}
