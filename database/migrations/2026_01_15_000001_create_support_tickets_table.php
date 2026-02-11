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
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            
            // Unique ticket number (e.g., "ST-10001")
            $table->string('ticket_number')->unique();
            
            // Ticket details
            $table->enum('type', ['bug', 'content', 'design', 'feature', 'question', 'urgent'])->default('question');
            $table->string('subject');
            $table->text('message');
            
            // Client info (from WordPress)
            $table->string('client_email');
            $table->string('client_name')->nullable();
            $table->string('problem_page')->nullable();
            $table->string('site_url')->nullable();
            
            // Status tracking
            $table->enum('status', ['open', 'in_progress', 'resolved', 'closed'])->default('open');
            $table->enum('priority', ['low', 'medium', 'high', 'critical'])->default('medium');
            
            // Linked todo (when converted to task)
            $table->foreignId('todo_id')->nullable()->constrained()->nullOnDelete();
            
            // Read tracking (for unread badge)
            $table->timestamp('read_at')->nullable();
            
            // Resolution tracking
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes for common queries
            $table->index(['project_id', 'status']);
            $table->index(['project_id', 'read_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('support_tickets');
    }
};
