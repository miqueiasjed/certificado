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
        // Migrar dispositivos de eventos existentes para a tabela work_order_device
        $events = DB::table('work_order_device_events')
            ->select('work_order_id', 'device_id')
            ->distinct()
            ->get();

        foreach ($events as $event) {
            // Verificar se já existe na tabela work_order_device
            $exists = DB::table('work_order_device')
                ->where('work_order_id', $event->work_order_id)
                ->where('device_id', $event->device_id)
                ->exists();

            if (!$exists) {
                // Inserir dispositivo na OS
                DB::table('work_order_device')->insert([
                    'work_order_id' => $event->work_order_id,
                    'device_id' => $event->device_id,
                    'observation' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Não há necessidade de reverter, pois os dados originais permanecem
    }
};
