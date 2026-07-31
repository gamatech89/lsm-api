<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Re-backfill the project_manager / project_developer pivot tables from
     * the legacy projects.manager_id / projects.developer_id columns.
     *
     * Drift insurance: the original backfills (2026_01_01_105205 for
     * developers, 2026_02_12_100001 for managers) only migrated rows that
     * existed when they ran. Some production projects have since had the
     * legacy columns set without a matching pivot row being created, leaving
     * users who can view a project (view() accepts legacy OR pivot) unable to
     * pass the pivot-only policy checks. This migration is idempotent: it
     * only inserts pairs that are not already present, respecting the
     * unique(project_id, user_id) constraints on both pivot tables.
     */
    public function up(): void
    {
        // Re-backfill legacy manager_id -> project_manager pivot
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

        // Re-backfill legacy developer_id -> project_developer pivot
        $projects = DB::table('projects')
            ->whereNotNull('developer_id')
            ->select('id', 'developer_id')
            ->get();

        foreach ($projects as $project) {
            // Check if this relationship already exists in the pivot table
            $exists = DB::table('project_developer')
                ->where('project_id', $project->id)
                ->where('user_id', $project->developer_id)
                ->exists();

            if (!$exists) {
                DB::table('project_developer')->insert([
                    'project_id' => $project->id,
                    'user_id' => $project->developer_id,
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
        // No-op: this is a data reconciliation. We cannot tell which pivot
        // rows were inserted here versus created legitimately before or after,
        // so there is nothing safe to reverse.
    }
};
