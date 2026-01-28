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
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            // Fields for prospective clients (when client_id is null)
            $table->string('prospect_name')->nullable();
            $table->string('prospect_phone')->nullable();
            $table->string('prospect_address')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // Creator/Seller

            $table->enum('status', ['draft', 'sent', 'negotiating', 'approved', 'refused', 'expired', 'converted'])->default('draft');
            $table->date('date');
            $table->date('validity_date')->nullable();
            $table->enum('priority', ['urgent', 'normal'])->default('normal');

            $table->enum('channel', ['WhatsApp', 'Phone', 'Site', 'InPerson', 'Referral', 'Other'])->nullable();

            $table->json('target_pests')->nullable(); // Arrays
            $table->enum('environment_type', ['residential', 'commercial', 'industrial', 'food', 'hospital', 'school', 'condo', 'other'])->nullable();
            $table->json('areas_to_treat')->nullable(); // Arrays or text? JSON is better for multi-select

            $table->string('size')->nullable();
            $table->string('rooms')->nullable();
            $table->enum('infestation_level', ['low', 'medium', 'high'])->nullable();
            $table->text('restrictions')->nullable();
            $table->text('observations')->nullable();

            $table->integer('labor_technicians')->default(1);
            $table->string('labor_hours')->nullable(); // Estimated hours

            $table->decimal('discount', 10, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->text('payment_conditions')->nullable(); // Installments, interest, etc.

            $table->string('execution_deadline')->nullable(); // D+X
            $table->string('warranty_info')->nullable();
            $table->string('reapplication_rule')->nullable();
            $table->string('recurrent_plan')->nullable();

            $table->text('loss_reason')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('budgets');
    }
};
