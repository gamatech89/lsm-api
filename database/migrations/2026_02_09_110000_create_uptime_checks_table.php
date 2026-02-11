<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Uptime Checks Table
 * 
 * Stores historical uptime check records for calculating real uptime percentages.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('uptime_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            
            // Status: 'up', 'down', 'error', 'timeout'
            $table->string('status', 20);
            
            // HTTP status code (200, 404, 500, etc.) - null if connection error
            $table->smallInteger('http_status')->nullable();
            
            // Response time in milliseconds
            $table->integer('response_time_ms')->nullable();
            
            // Error message if any
            $table->string('error_message')->nullable();
            
            // When the check was performed
            $table->timestamp('checked_at');
            
            // Indexes for efficient querying
            $table->index(['project_id', 'checked_at']);
            $table->index(['project_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('uptime_checks');
    }
};
