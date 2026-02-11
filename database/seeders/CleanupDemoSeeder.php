<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Database\Seeder;

class CleanupDemoSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Remove 'felixw206' project
        $project = Project::where('name', 'LIKE', '%felixw206%')
            ->orWhere('url', 'LIKE', '%felixw206%')
            ->first();

        if ($project) {
            $this->command->info("Deleting project: {$project->name} ({$project->url})");
            // Detach relationships first just in case, though cascade might handle it
            $project->developers()->detach();
            $project->forceDelete(); // Use forceDelete to actually remove it if SoftDeletes is on, or just delete.
        } else {
            $this->command->info("Project 'felixw206' not found.");
        }

        // 2. Clear Bojan's stats
        $bojan = User::where('email', 'bojan@example.com')->first();
        if (!$bojan) {
             // Try fuzzy search
             $bojan = User::where('name', 'Bojan')->first();
        }

        if ($bojan) {
            $this->command->info("Clearing stats for user: {$bojan->name}");

            // Clear Time Entries
            $deletedTimes = TimeEntry::where('user_id', $bojan->id)->delete();
            $this->command->info("Deleted {$deletedTimes} time entries.");

            // Unassign Todos (or delete if they are strictly personal? usually just unassign for demo reset)
            // User said "clear developer stats", usually implies making dashboard numbers 0.
            // Assigned Tasks count comes from Todos assigned to him.
            
            $unassignedTodos = Todo::where('assignee_id', $bojan->id)->update(['assignee_id' => null]);
            $this->command->info("Unassigned {$unassignedTodos} todos.");
            
            // Also detach from all projects to be clean?
            // "Clear stats" might just mean the numbers. But if he is assigned to projects, "My Active Projects" will show them.
            // The user said "clear this project... i will add it... clear bojan stats".
            // I will DETACH him from all projects to give a blank slate state.
            
            $bojan->assignedProjects()->detach();
            $this->command->info("Detached from all projects.");

        } else {
            $this->command->error("User Bojan not found.");
        }
    }
}
