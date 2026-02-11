<?php

namespace Database\Seeders;

use App\Models\TimeEntry;
use App\Models\Timesheet;
use App\Models\Project;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class TimeTrackingSeeder extends Seeder
{
    /**
     * Seed sample time entries for analytics demo.
     */
    public function run(): void
    {
        $this->command->info('Seeding time tracking data...');

        // Get users and projects
        $users = User::all();
        $projects = Project::take(10)->get();

        if ($users->isEmpty()) {
            $this->command->error('No users found. Run UserSeeder first.');
            return;
        }

        if ($projects->isEmpty()) {
            $this->command->error('No projects found. Run ProjectSeeder first.');
            return;
        }

        // Descriptions for variety
        $descriptions = [
            'Bug fixing and code review',
            'Feature development',
            'Client meeting and requirements',
            'Testing and QA',
            'Documentation update',
            'Database optimization',
            'API integration',
            'UI/UX improvements',
            'Security audit',
            'Performance optimization',
            'Code refactoring',
            'Sprint planning',
            'Deployment and monitoring',
            'Technical support',
        ];

        // Create entries for the past 6 weeks
        $endDate = Carbon::now();
        $startDate = Carbon::now()->subWeeks(6);

        $totalEntries = 0;

        foreach ($users as $user) {
            // Skip if user is not a developer/admin
            if (!in_array($user->role, ['admin', 'developer'])) {
                continue;
            }

            $currentDate = $startDate->copy();

            while ($currentDate->lte($endDate)) {
                // Skip weekends sometimes
                if ($currentDate->isWeekend() && rand(0, 10) > 3) {
                    $currentDate->addDay();
                    continue;
                }

                // 1-4 entries per day
                $entriesPerDay = rand(1, 4);

                // Get or create timesheet for this week
                $timesheet = Timesheet::getOrCreateForWeek($user->id, $currentDate);

                for ($i = 0; $i < $entriesPerDay; $i++) {
                    $project = $projects->random();

                    // Random start time between 8am and 4pm
                    $startHour = rand(8, 16);
                    $startMinute = rand(0, 59);
                    $startedAt = $currentDate->copy()->setTime($startHour, $startMinute);

                    // Random duration between 30 minutes and 3 hours
                    $durationMinutes = rand(30, 180);
                    $endedAt = $startedAt->copy()->addMinutes($durationMinutes);

                    TimeEntry::create([
                        'user_id' => $user->id,
                        'project_id' => $project->id,
                        'timesheet_id' => $timesheet->id,
                        'description' => $descriptions[array_rand($descriptions)],
                        'started_at' => $startedAt,
                        'ended_at' => $endedAt,
                        'duration_minutes' => $durationMinutes,
                        'is_billable' => rand(0, 10) > 2, // 80% billable
                        'status' => $this->getRandomStatus($currentDate),
                    ]);

                    $totalEntries++;
                }

                $currentDate->addDay();
            }

            // Update timesheet totals
            foreach (Timesheet::where('user_id', $user->id)->get() as $ts) {
                $ts->recalculateTotals();
            }
        }

        $this->command->info("Created {$totalEntries} time entries.");
    }

    private function getRandomStatus(Carbon $date): string
    {
        // Older entries are more likely to be approved
        $daysAgo = $date->diffInDays(Carbon::now());

        if ($daysAgo > 14) {
            return rand(0, 10) > 2 ? TimeEntry::STATUS_APPROVED : TimeEntry::STATUS_SUBMITTED;
        } elseif ($daysAgo > 7) {
            return rand(0, 10) > 3 ? TimeEntry::STATUS_SUBMITTED : TimeEntry::STATUS_DRAFT;
        } else {
            return TimeEntry::STATUS_DRAFT;
        }
    }
}
