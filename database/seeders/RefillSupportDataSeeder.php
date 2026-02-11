<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Database\Seeder;

class RefillSupportDataSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Find Bojan
        $bojan = User::where('email', 'bojan@example.com')->first();
        
        if (!$bojan) {
            $bojan = User::where('role', 'developer')->first();
            if ($bojan) {
                $this->command->warn("User 'bojan@example.com' not found, using '{$bojan->email}' instead.");
            } else {
                $this->command->error("No developers found. Please run RefillUsersSeeder first.");
                return;
            }
        }

        // 2. Find or Create Zeltraum Project
        $manager = User::where('role', 'manager')->first();
        
        $project = Project::firstOrCreate(
            ['name' => 'Zeltraum'],
            [
                'url' => 'https://zeltraum.de',
                'project_external_id' => Project::generateExternalId(),
                'health_status' => 'healthy',
                'manager_id' => $manager ? $manager->id : null,
            ]
        );

        // 3. Assign Bojan to Zeltraum
        if (!$project->developers()->where('user_id', $bojan->id)->exists()) {
            $project->developers()->attach($bojan->id);
            $this->command->info("Assigned {$bojan->name} to Zeltraum.");
        }

        // 4. Feed Support Data (Tickets)
        if ($project->supportTickets()->count() > 0) {
            $this->command->info('Project Zeltraum already has tickets. Checking for new ones...');
        }

        $tickets = [
            [
                'subject' => 'Contact Form Issue',
                'message' => 'The contact form on the home page is not sending emails correctly.',
                'type' => 'bug',
                'priority' => 'high',
                'status' => 'open',
                'client_name' => 'Hans Muller',
                'client_email' => 'hans@zeltraum.de',
                'problem_page' => '/contact',
            ],
            [
                'subject' => 'Update Hero Image',
                'message' => 'Please replace the hero image with the attached one.',
                'type' => 'content',
                'priority' => 'medium',
                'status' => 'in_progress',
                'client_name' => 'Julia Weber',
                'client_email' => 'julia@zeltraum.de',
                'problem_page' => '/',
            ],
            [
                'subject' => 'Mobile Menu Bug',
                'message' => 'The mobile menu does not close when clicking a link.',
                'type' => 'bug',
                'priority' => 'medium',
                'status' => 'open',
                'client_name' => 'Hans Muller',
                'client_email' => 'hans@zeltraum.de',
                'problem_page' => '/about',
            ],
                [
                'subject' => 'New Landing Page Request',
                'message' => 'We need a new landing page for the summer campaign.',
                'type' => 'feature',
                'priority' => 'low',
                'status' => 'open',
                'client_name' => 'Marketing Team',
                'client_email' => 'marketing@zeltraum.de',
                'problem_page' => 'n/a',
            ],
            [
                'subject' => 'Database Connection Error',
                'message' => 'Intermittent 500 errors appearing in the logs during checkout.',
                'type' => 'urgent', // Type 'urgent' is allowed
                'priority' => 'critical', // Priority 'urgent' is NOT allowed, must be 'critical'
                'status' => 'open',
                'client_name' => 'System Monitor',
                'client_email' => 'admin@zeltraum.de',
                'problem_page' => '/checkout',
            ],
        ];

        foreach ($tickets as $data) {
            // Check uniqueness
            if (!SupportTicket::where('project_id', $project->id)->where('subject', $data['subject'])->exists()) {
                SupportTicket::create(array_merge($data, [
                    'project_id' => $project->id,
                ]));
                $this->command->info("Created ticket: {$data['subject']}");
            }
        }
        
        $this->command->info('Support tickets seeding complete for Zeltraum.');

        // Always ensure Bojan is attached (duplicate check handled above but safe to double check)
        if (!$project->developers()->where('user_id', $bojan->id)->exists()) {
                $project->developers()->attach($bojan->id);
                $this->command->info("Re-assigned {$bojan->name} to Zeltraum.");
        }
    }
}
