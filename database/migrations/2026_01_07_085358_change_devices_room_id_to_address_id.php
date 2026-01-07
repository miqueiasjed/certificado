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
        // Verificar se a coluna address_id já existe (para permitir re-execução)
        if (!Schema::hasColumn('devices', 'address_id')) {
            // Adicionar coluna address_id (nullable temporariamente para migração)
            Schema::table('devices', function (Blueprint $table) {
                $table->unsignedBigInteger('address_id')->nullable()->after('id');
            });
        }

        // Migrar dados: devices.address_id = devices.room.address_id
        // Apenas se room_id ainda existir
        if (Schema::hasColumn('devices', 'room_id')) {
            DB::statement('
                UPDATE devices 
                INNER JOIN rooms ON devices.room_id = rooms.id 
                SET devices.address_id = rooms.address_id
                WHERE devices.address_id IS NULL
            ');
        }

        // Tornar address_id obrigatório (apenas se ainda for nullable)
        if (Schema::hasColumn('devices', 'address_id')) {
            Schema::table('devices', function (Blueprint $table) {
                $table->unsignedBigInteger('address_id')->nullable(false)->change();
            });
        }

        // Remover foreign key de room_id primeiro (isso também remove o índice automaticamente)
        if (Schema::hasColumn('devices', 'room_id')) {
            // Verificar se existe foreign key antes de tentar remover
            $foreignKeys = DB::select("
                SELECT CONSTRAINT_NAME 
                FROM information_schema.KEY_COLUMN_USAGE 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'devices' 
                AND COLUMN_NAME = 'room_id' 
                AND REFERENCED_TABLE_NAME IS NOT NULL
            ");
            
            if (!empty($foreignKeys)) {
                Schema::table('devices', function (Blueprint $table) use ($foreignKeys) {
                    $constraintName = $foreignKeys[0]->CONSTRAINT_NAME;
                    // Remover foreign key usando o nome da constraint
                    $table->dropForeign($constraintName);
                });
            }

            // Remover índice antigo (se ainda existir)
            $indexes = DB::select("
                SHOW INDEX FROM devices WHERE Key_name = 'devices_room_id_active_index'
            ");
            if (!empty($indexes)) {
                Schema::table('devices', function (Blueprint $table) {
                    $table->dropIndex('devices_room_id_active_index');
                });
            }

            // Remover coluna room_id
            Schema::table('devices', function (Blueprint $table) {
                $table->dropColumn('room_id');
            });
        }

        // Adicionar foreign key para address_id (se ainda não existir)
        if (Schema::hasColumn('devices', 'address_id')) {
            $foreignKeys = DB::select("
                SELECT CONSTRAINT_NAME 
                FROM information_schema.KEY_COLUMN_USAGE 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'devices' 
                AND COLUMN_NAME = 'address_id' 
                AND REFERENCED_TABLE_NAME IS NOT NULL
            ");
            
            if (empty($foreignKeys)) {
                Schema::table('devices', function (Blueprint $table) {
                    $table->foreign('address_id')->references('id')->on('addresses')->onDelete('cascade');
                });
            }
        }

        // Adicionar novo índice (se ainda não existir)
        if (Schema::hasColumn('devices', 'address_id')) {
            $indexes = DB::select("
                SHOW INDEX FROM devices WHERE Key_name = 'devices_address_id_active_index'
            ");
            if (empty($indexes)) {
                Schema::table('devices', function (Blueprint $table) {
                    $table->index(['address_id', 'active'], 'devices_address_id_active_index');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Adicionar coluna room_id de volta (nullable temporariamente)
        Schema::table('devices', function (Blueprint $table) {
            $table->foreignId('room_id')->nullable()->after('id');
        });

        // Migrar dados de volta: devices.room_id = primeiro room do address
        // Nota: Esta migração reversa pode perder dados se houver múltiplos rooms por address
        DB::statement('
            UPDATE devices 
            INNER JOIN (
                SELECT rooms.id as room_id, rooms.address_id, 
                       ROW_NUMBER() OVER (PARTITION BY rooms.address_id ORDER BY rooms.id) as rn
                FROM rooms
            ) as first_rooms ON devices.address_id = first_rooms.address_id AND first_rooms.rn = 1
            SET devices.room_id = first_rooms.room_id
        ');

        // Tornar room_id obrigatório
        Schema::table('devices', function (Blueprint $table) {
            $table->foreignId('room_id')->nullable(false)->change();
        });

        // Remover índice novo
        Schema::table('devices', function (Blueprint $table) {
            $table->dropIndex(['address_id', 'active']);
        });

        // Remover foreign key e coluna address_id
        Schema::table('devices', function (Blueprint $table) {
            $table->dropForeign(['address_id']);
            $table->dropColumn('address_id');
        });

        // Adicionar foreign key para room_id
        Schema::table('devices', function (Blueprint $table) {
            $table->foreign('room_id')->references('id')->on('rooms')->onDelete('cascade');
        });

        // Adicionar índice antigo
        Schema::table('devices', function (Blueprint $table) {
            $table->index(['room_id', 'active']);
        });
    }
};
