<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Inserir os tipos de serviço padrão
        $serviceTypes = [
            [
                'name' => 'Dedetização',
                'slug' => 'dedetizacao',
                'description' => 'Controle de insetos em geral',
                'active' => true,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Desinsetização',
                'slug' => 'desinsetizacao',
                'description' => 'Controle específico de insetos',
                'active' => true,
                'sort_order' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Descupinização',
                'slug' => 'descupinizacao',
                'description' => 'Controle de cupins',
                'active' => true,
                'sort_order' => 3,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Desratização',
                'slug' => 'desratizacao',
                'description' => 'Controle de roedores',
                'active' => true,
                'sort_order' => 4,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Sanitização',
                'slug' => 'sanitizacao',
                'description' => 'Sanitização de ambientes',
                'active' => true,
                'sort_order' => 5,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        // Verificar se os dados já existem antes de inserir
        $existingCount = DB::table('service_types')->count();

        if ($existingCount === 0) {
            DB::table('service_types')->insert($serviceTypes);
        }

        // Migrar dados existentes das service_orders
        // Como a coluna service_type já foi removida na migration anterior,
        // vamos apenas definir um tipo padrão para ordens existentes
        $defaultServiceType = DB::table('service_types')->where('slug', 'dedetizacao')->first();

        if ($defaultServiceType) {
            DB::table('service_orders')
                ->whereNull('service_type_id')
                ->update(['service_type_id' => $defaultServiceType->id]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Migrar dados de volta para o ENUM
        $serviceOrders = DB::table('service_orders')
            ->join('service_types', 'service_orders.service_type_id', '=', 'service_types.id')
            ->select('service_orders.id', 'service_types.slug')
            ->get();

        foreach ($serviceOrders as $order) {
            DB::table('service_orders')
                ->where('id', $order->id)
                ->update(['service_type' => $order->slug]);
        }

        // Limpar a tabela service_types
        DB::table('service_types')->truncate();
    }
};
