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
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_technician')->default(false)->after('email_verified_at');
            $table->string('phone')->nullable()->after('is_technician');
            $table->string('cpf')->nullable()->after('phone');
            $table->text('address')->nullable()->after('cpf');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_technician', 'phone', 'cpf', 'address']);
        });
    }
};
