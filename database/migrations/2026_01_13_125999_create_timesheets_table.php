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
        Schema::create('timesheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->integer('week_number'); // ISO week number (1-53)
            $table->integer('year');
            $table->date('week_start'); // Monday of the week
            $table->date('week_end'); // Sunday of the week
            $table->enum('status', ['open', 'submitted', 'approved', 'rejected', 'paid'])->default('open');
            $table->integer('total_minutes')->default(0);
            $table->integer('total_billable_minutes')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            
            // Each user can only have one timesheet per week
            $table->unique(['user_id', 'week_number', 'year']);
            $table->index(['status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('timesheets');
    }
};
