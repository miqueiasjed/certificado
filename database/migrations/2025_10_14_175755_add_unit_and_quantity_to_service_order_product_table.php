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
        // Verificar se a tabela existe
        if (!Schema::hasTable('service_order_product')) {
            Schema::create('service_order_product', function (Blueprint $table) {
                $table->id();
                $table->foreignId('service_order_id')->constrained('service_orders')->onDelete('cascade');
                $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
                $table->decimal('quantity', 10, 2)->nullable();
                $table->string('unit')->nullable(); // kg, L, mL, g, mg, unidade
                $table->timestamps();
            });
        } else {
            Schema::table('service_order_product', function (Blueprint $table) {
                if (!Schema::hasColumn('service_order_product', 'quantity')) {
                    $table->decimal('quantity', 10, 2)->nullable()->after('product_id');
                }
                if (!Schema::hasColumn('service_order_product', 'unit')) {
                    $table->string('unit')->nullable()->after('quantity');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('service_order_product')) {
            Schema::table('service_order_product', function (Blueprint $table) {
                if (Schema::hasColumn('service_order_product', 'quantity')) {
                    $table->dropColumn('quantity');
                }
                if (Schema::hasColumn('service_order_product', 'unit')) {
                    $table->dropColumn('unit');
                }
            });
        }
    }
};
