<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * LSM = Landeseiten Management Tables
     * This single migration creates all application-specific tables.
     */
    public function up(): void
    {
        // =====================================================
        // PROJECTS TABLE
        // =====================================================
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            
            // External ID from legacy system (e.g., "WV10062", "LP10314")
            // NOT unique because some external IDs are shared across related projects
            $table->string('project_external_id')->nullable()->index();
            
            // Basic info
            $table->string('name');
            $table->string('domain')->nullable();
            $table->string('url')->nullable();
            $table->string('client_email')->nullable();
            
            // Hosting information
            $table->string('hosting_provider')->nullable();
            $table->text('hosting_url')->nullable();
            $table->string('ssh_access')->nullable();
            
            // External links
            $table->text('drive_link')->nullable();
            $table->text('trello_link')->nullable();
            
            // Notes (supports markdown)
            $table->text('notes')->nullable();
            
            // Status tracking
            $table->enum('health_status', ['online', 'down_error', 'updating'])->default('online');
            $table->enum('security_status', ['secure', 'monitoring', 'compromised', 'hacked'])->default('secure');
            
            // Team assignments
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('developer_id')->nullable()->constrained('users')->nullOnDelete();
            
            $table->timestamps();
        });

        // =====================================================
        // CREDENTIALS TABLE
        // =====================================================
        Schema::create('credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            
            $table->string('title');
            $table->string('type'); // hosting, ssh, ftp, db, wp_admin, api, etc.
            $table->string('username')->nullable();
            $table->text('password')->nullable(); // Will be encrypted via model cast
            $table->text('url')->nullable();
            $table->json('metadata')->nullable(); // Additional flexible data
            
            $table->timestamps();
        });

        // =====================================================
        // RESOURCES TABLE (for file attachments & links)
        // =====================================================
        Schema::create('resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            
            $table->string('title');
            $table->string('type')->default('link'); // 'link' or 'file'
            $table->text('url')->nullable();
            $table->string('file_path')->nullable();
            
            $table->timestamps();
        });

        // =====================================================
        // TODOS TABLE
        // =====================================================
        Schema::create('todos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('priority', ['low', 'medium', 'high', 'critical'])->default('medium');
            $table->enum('status', ['pending', 'in_progress', 'completed', 'cancelled'])->default('pending');
            $table->date('due_date')->nullable();
            $table->string('file_path')->nullable();
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('todos');
        Schema::dropIfExists('resources');
        Schema::dropIfExists('credentials');
        Schema::dropIfExists('projects');
    }
};
