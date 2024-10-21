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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('active_ingredient_id')->constrained('active_ingredients')->cascadeOnDelete();
            $table->foreignId('chemical_group_id')->constrained('chemical_groups')->cascadeOnDelete();
            $table->foreignId('antidote_id')->constrained('antidotes')->cascadeOnDelete();
            $table->foreignId('organ_registration_id')->constrained('organ_registrations')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
