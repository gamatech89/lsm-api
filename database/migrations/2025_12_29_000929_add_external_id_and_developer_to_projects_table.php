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
        // Skip if columns already exist (they're in the base migration)
        if (Schema::hasColumn('projects', 'project_external_id')) {
            return;
        }
        
        Schema::table('projects', function (Blueprint $table) {
            $table->string('project_external_id')->nullable()->after('id');
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete()->after('security_status');
            $table->foreignId('developer_id')->nullable()->constrained('users')->nullOnDelete()->after('manager_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasColumn('projects', 'project_external_id')) {
            return;
        }
        
        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['manager_id']);
            $table->dropForeign(['developer_id']);
            $table->dropColumn(['project_external_id', 'manager_id', 'developer_id']);
        });
    }
};
