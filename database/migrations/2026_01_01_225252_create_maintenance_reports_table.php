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
        Schema::create('maintenance_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->date('report_date');
            $table->string('type')->default('monthly'); // monthly, weekly, ad-hoc
            $table->text('summary'); // Brief summary of work done
            $table->json('tasks_completed')->nullable(); // Array of completed tasks
            $table->json('updates_performed')->nullable(); // Plugin/theme/core updates
            $table->json('issues_found')->nullable(); // Issues discovered
            $table->json('issues_resolved')->nullable(); // Issues fixed
            $table->text('notes')->nullable(); // Additional notes
            $table->integer('time_spent_minutes')->nullable(); // Time tracking
            $table->timestamps();
            
            $table->index(['project_id', 'report_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('maintenance_reports');
    }
};
