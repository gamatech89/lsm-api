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
        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            
            // Backup type and status
            $table->enum('type', ['manual', 'scheduled', 'pre_update'])->default('manual');
            $table->enum('status', ['pending', 'in_progress', 'completed', 'failed'])->default('pending');
            
            // What's included
            $table->boolean('includes_database')->default(true);
            $table->boolean('includes_files')->default(true);
            $table->boolean('includes_uploads')->default(true);
            
            // File info
            $table->string('file_path')->nullable();
            $table->bigInteger('file_size')->nullable(); // in bytes
            $table->string('checksum')->nullable(); // for integrity verification
            
            // Status tracking
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error_message')->nullable();
            
            // Metadata
            $table->json('metadata')->nullable(); // WP version, PHP version, etc at time of backup
            
            $table->timestamps();
            
            // Indexes
            $table->index(['project_id', 'status']);
            $table->index(['project_id', 'type']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('backups');
    }
};
