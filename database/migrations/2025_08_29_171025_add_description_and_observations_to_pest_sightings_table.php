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
        Schema::table('pest_sightings', function (Blueprint $table) {
            $table->text('description')->nullable()->after('location_description');
            $table->text('observations')->nullable()->after('description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pest_sightings', function (Blueprint $table) {
            $table->dropColumn(['description', 'observations']);
        });
    }
};
