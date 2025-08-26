<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->string('name')->after('id');
            $table->string('category')->nullable()->after('description');
            $table->decimal('price', 10, 2)->nullable()->after('category');
            $table->boolean('is_active')->default(true)->after('price');
            $table->text('notes')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['name', 'category', 'price', 'is_active', 'notes']);
        });
    }
};
