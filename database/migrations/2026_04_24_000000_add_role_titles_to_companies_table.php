<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('operational_manager_title')->nullable()->default('Gerente Operacional')->after('operational_manager_name');
            $table->string('technical_responsible_title')->nullable()->default('Responsável Técnico')->after('technical_responsible_name');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['operational_manager_title', 'technical_responsible_title']);
        });
    }
};
