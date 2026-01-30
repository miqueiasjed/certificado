<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('register_visa')->nullable()->after('license_business');
            $table->string('register_crea')->nullable()->after('register_visa');
            $table->string('operational_manager_name')->nullable()->after('ceatox_info');
            $table->string('technical_responsible_name')->nullable()->after('operational_manager_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'register_visa',
                'register_crea',
                'operational_manager_name',
                'technical_responsible_name'
            ]);
        });
    }
};
