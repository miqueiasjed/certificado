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
        Schema::table('certificate_product', function (Blueprint $table) {
            $table->decimal('quantity', 10, 2)->nullable()->after('product_id');
            $table->string('unit')->nullable()->after('quantity'); // kg, L, mL, g, mg, unidade
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('certificate_product', function (Blueprint $table) {
            $table->dropColumn(['quantity', 'unit']);
        });
    }
};
