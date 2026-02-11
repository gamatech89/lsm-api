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
        Schema::create('project_billing', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->enum('billing_type', ['hourly', 'fixed', 'monthly'])->default('hourly');
            $table->decimal('hourly_rate', 10, 2)->nullable(); // €/hour
            $table->decimal('fixed_price', 10, 2)->nullable(); // Fixed project price
            $table->decimal('monthly_retainer', 10, 2)->nullable(); // Monthly maintenance
            $table->string('currency', 3)->default('EUR');
            $table->integer('estimated_hours')->nullable();
            $table->text('billing_notes')->nullable();
            $table->timestamps();
            
            $table->unique('project_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_billing');
    }
};
