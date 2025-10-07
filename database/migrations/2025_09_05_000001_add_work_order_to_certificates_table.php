<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            if (!Schema::hasColumn('certificates', 'work_order_id')) {
                $table->foreignId('work_order_id')->nullable()->after('client_id')->constrained('work_orders')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            if (Schema::hasColumn('certificates', 'work_order_id')) {
                $table->dropForeign(['work_order_id']);
                $table->dropColumn('work_order_id');
            }
        });
    }
};












