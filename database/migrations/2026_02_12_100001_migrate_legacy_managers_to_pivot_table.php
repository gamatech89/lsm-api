<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Migrate legacy manager_id to the new project_manager pivot table.
     */
    public function up(): void
    {
        // Get all projects with a legacy manager_id
        $projects = DB::table('projects')
            ->whereNotNull('manager_id')
            ->select('id', 'manager_id')
            ->get();

        foreach ($projects as $project) {
            // Check if this relationship already exists in the pivot table
            $exists = DB::table('project_manager')
                ->where('project_id', $project->id)
                ->where('user_id', $project->manager_id)
                ->exists();

            if (!$exists) {
                DB::table('project_manager')->insert([
                    'project_id' => $project->id,
                    'user_id' => $project->manager_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // We don't want to lose data on rollback, so we'll just clear the pivot table
        // The legacy manager_id column is still intact
        DB::table('project_manager')->truncate();
    }
};
