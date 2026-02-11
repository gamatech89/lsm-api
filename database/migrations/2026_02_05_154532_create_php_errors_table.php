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
        Schema::create('php_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            
            // Error details
            $table->enum('type', ['fatal', 'warning', 'notice', 'deprecated', 'parse'])->default('notice');
            $table->text('message');
            $table->string('file')->nullable();
            $table->integer('line')->nullable();
            
            // Error hash for grouping similar errors
            $table->string('error_hash', 64);
            
            // Occurrence tracking
            $table->integer('count')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            
            // Additional context
            $table->string('wordpress_version')->nullable();
            $table->string('php_version')->nullable();
            $table->string('plugin_slug')->nullable(); // if error is from a plugin
            $table->string('theme_slug')->nullable();  // if error is from a theme
            
            // Status
            $table->boolean('is_resolved')->default(false);
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            
            $table->timestamps();
            
            // Indexes
            $table->unique(['project_id', 'error_hash']);
            $table->index(['project_id', 'type']);
            $table->index(['project_id', 'is_resolved']);
            $table->index('last_seen_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('php_errors');
    }
};
