<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            if (!Schema::hasColumn('certificates', 'execution_date')) {
                $table->date('execution_date')->nullable()->after('work_order_id');
            }
            if (!Schema::hasColumn('certificates', 'warranty')) {
                $table->date('warranty')->nullable()->after('execution_date');
            }
        });

        // Backfill a partir de issue_date / expiry_date se existirem
        if (Schema::hasColumn('certificates', 'issue_date')) {
            DB::statement('UPDATE certificates SET execution_date = COALESCE(execution_date, issue_date)');
        }
        if (Schema::hasColumn('certificates', 'expiry_date')) {
            DB::statement('UPDATE certificates SET warranty = COALESCE(warranty, expiry_date)');
        }
    }

    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            if (Schema::hasColumn('certificates', 'execution_date')) {
                $table->dropColumn('execution_date');
            }
            if (Schema::hasColumn('certificates', 'warranty')) {
                $table->dropColumn('warranty');
            }
        });
    }
};





















