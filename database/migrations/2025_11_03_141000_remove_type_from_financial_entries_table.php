<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('financial_entries', 'type')) {
            return;
        }

        DB::table('financial_entries')
            ->where('type', 'withdrawal')
            ->where(function ($query) {
                $query->whereNull('source')
                    ->orWhere('source', 'manual');
            })
            ->update(['source' => 'manual_withdrawal']);

        Schema::table('financial_entries', function (Blueprint $table) {
            $table->dropIndex('financial_entries_type_entry_date_index');
            $table->dropColumn('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('financial_entries', 'type')) {
            return;
        }

        Schema::table('financial_entries', function (Blueprint $table) {
            $table->string('type')->nullable()->after('id');
        });

        DB::table('financial_entries')
            ->where('source', 'work_order')
            ->update(['type' => 'payment']);

        DB::table('financial_entries')
            ->where('source', 'manual')
            ->update(['type' => 'manual']);

        DB::table('financial_entries')
            ->whereIn('source', ['payment_reopen', 'manual_withdrawal'])
            ->update(['type' => 'withdrawal']);

        Schema::table('financial_entries', function (Blueprint $table) {
            $table->index(['type', 'entry_date']);
        });
    }
};
