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
        // Add soft deletes to projects
        Schema::table('projects', function (Blueprint $table) {
            $table->softDeletes();
            // Add composite indexes for common queries
            $table->index(['health_status', 'security_status']);
            $table->index(['manager_id', 'developer_id']);
        });

        // Add soft deletes to credentials
        Schema::table('credentials', function (Blueprint $table) {
            $table->softDeletes();
            $table->index(['project_id', 'type']);
        });

        // Add soft deletes to resources
        Schema::table('resources', function (Blueprint $table) {
            $table->softDeletes();
        });

        // Add soft deletes to todos
        Schema::table('todos', function (Blueprint $table) {
            $table->softDeletes();
            $table->index(['project_id', 'status']);
            $table->index(['project_id', 'priority']);
        });

        // Add last_login_at to users
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_login_at')->nullable();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropIndex(['health_status', 'security_status']);
            $table->dropIndex(['manager_id', 'developer_id']);
        });

        Schema::table('credentials', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropIndex(['project_id', 'type']);
        });

        Schema::table('resources', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('todos', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropIndex(['project_id', 'status']);
            $table->dropIndex(['project_id', 'priority']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_login_at');
            $table->dropSoftDeletes();
        });
    }
};
