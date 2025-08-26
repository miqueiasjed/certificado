<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            // Adicionar novos campos
            $table->foreignId('product_id')->nullable()->after('client_id')->constrained()->onDelete('set null');
            $table->foreignId('technician_id')->nullable()->after('product_id')->constrained()->onDelete('set null');
            $table->string('certificate_number')->nullable()->after('technician_id');
            $table->enum('status', ['draft', 'issued', 'expired', 'cancelled'])->default('draft')->after('certificate_number');
            $table->date('issue_date')->nullable()->after('status');
            $table->date('expiry_date')->nullable()->after('issue_date');
            $table->text('notes')->nullable()->after('expiry_date');

            // Remover campos antigos se existirem
            if (Schema::hasColumn('certificates', 'date')) {
                $table->dropColumn('date');
            }
            if (Schema::hasColumn('certificates', 'assurance')) {
                $table->dropColumn('assurance');
            }
        });
    }

    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            // Remover novos campos
            $table->dropForeign(['product_id']);
            $table->dropForeign(['technician_id']);
            $table->dropColumn([
                'product_id',
                'technician_id',
                'certificate_number',
                'status',
                'issue_date',
                'expiry_date',
                'notes'
            ]);

            // Restaurar campos antigos
            $table->date('date')->nullable();
            $table->date('assurance')->nullable();
        });
    }
};
