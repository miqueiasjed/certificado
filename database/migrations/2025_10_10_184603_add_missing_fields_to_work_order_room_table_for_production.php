<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('work_order_room', function (Blueprint $table) {
            // Verificar se os campos já existem antes de adicionar
            if (!Schema::hasColumn('work_order_room', 'event_type')) {
                $table->string('event_type')->nullable()->after('observation');
            }
            
            if (!Schema::hasColumn('work_order_room', 'event_date')) {
                $table->date('event_date')->nullable()->after('event_type');
            }
            
            if (!Schema::hasColumn('work_order_room', 'event_description')) {
                $table->text('event_description')->nullable()->after('event_date');
            }
            
            if (!Schema::hasColumn('work_order_room', 'event_observations')) {
                $table->text('event_observations')->nullable()->after('event_description');
            }
            
            if (!Schema::hasColumn('work_order_room', 'device_id')) {
                $table->unsignedBigInteger('device_id')->nullable()->after('event_observations');
            }
            
            if (!Schema::hasColumn('work_order_room', 'pest_type')) {
                $table->string('pest_type')->nullable()->after('device_id');
            }
            
            if (!Schema::hasColumn('work_order_room', 'pest_sighting_date')) {
                $table->date('pest_sighting_date')->nullable()->after('pest_type');
            }
            
            if (!Schema::hasColumn('work_order_room', 'pest_location')) {
                $table->string('pest_location')->nullable()->after('pest_sighting_date');
            }
            
            if (!Schema::hasColumn('work_order_room', 'pest_quantity')) {
                $table->integer('pest_quantity')->nullable()->after('pest_location');
            }
            
            if (!Schema::hasColumn('work_order_room', 'pest_observation')) {
                $table->text('pest_observation')->nullable()->after('pest_quantity');
            }
        });

        // Adicionar foreign key para device_id se não existir
        if (Schema::hasColumn('work_order_room', 'device_id')) {
            try {
                Schema::table('work_order_room', function (Blueprint $table) {
                    $table->foreign('device_id')->references('id')->on('devices')->onDelete('set null');
                });
            } catch (Exception $e) {
                // Foreign key já existe, ignorar erro
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('work_order_room', function (Blueprint $table) {
            // Remover foreign key primeiro
            if (Schema::hasColumn('work_order_room', 'device_id')) {
                try {
                    $table->dropForeign(['device_id']);
                } catch (Exception $e) {
                    // Foreign key não existe, ignorar erro
                }
            }
            
            // Remover colunas se existirem
            $columns = [
                'event_type',
                'event_date', 
                'event_description',
                'event_observations',
                'device_id',
                'pest_type',
                'pest_sighting_date',
                'pest_location',
                'pest_quantity',
                'pest_observation'
            ];
            
            foreach ($columns as $column) {
                if (Schema::hasColumn('work_order_room', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};