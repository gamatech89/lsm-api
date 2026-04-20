<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\User;
use App\Notifications\SslExpiringNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckSslExpiry extends Command
{
    protected $signature = 'sites:check-ssl-expiry
                            {--project= : Check specific project ID only}
                            {--days=30 : Warn when SSL expires within this many days}';

    protected $description = 'Send alerts for SSL certificates expiring within the warning window';

    public function handle(): int
    {
        $warningDays = (int) $this->option('days');

        $query = Project::whereNotNull('ssl_expires_at')
            ->whereNotNull('url')
            ->where('status', '!=', 'archived')
            ->whereDate('ssl_expires_at', '<=', now()->addDays($warningDays));

        if ($projectId = $this->option('project')) {
            $query->where('id', $projectId);
        }

        $projects = $query->get();

        if ($projects->isEmpty()) {
            $this->info('No SSL certificates expiring within the warning window.');
            return 0;
        }

        $notified = 0;

        foreach ($projects as $project) {
            $daysRemaining = (int) now()->startOfDay()->diffInDays($project->ssl_expires_at, false);

            // Negative means already expired
            if ($daysRemaining < 0) {
                $daysRemaining = 0;
            }

            if ($this->wasRecentlyNotified($project, $daysRemaining)) {
                continue;
            }

            foreach ($this->getProjectMembers($project) as $member) {
                $member->notify(new SslExpiringNotification(
                    $project,
                    $project->ssl_expires_at->toDateString(),
                    $daysRemaining,
                ));
            }

            $notified++;

            if ($this->getOutput()->isVerbose()) {
                $urgency = $daysRemaining <= 7 ? '🚨' : '⚠️';
                $this->line("{$urgency} {$project->name}: SSL expires in {$daysRemaining} day(s) ({$project->ssl_expires_at->toDateString()})");
            }
        }

        $this->info("SSL expiry check complete: {$notified} notification(s) sent out of {$projects->count()} expiring certificate(s).");

        return 0;
    }

    /**
     * Avoid spamming — critical certs (≤7 days) alert every 12 hours, others once a day.
     */
    protected function wasRecentlyNotified(Project $project, int $daysRemaining): bool
    {
        $cooldownHours = $daysRemaining <= 7 ? 12 : 24;

        return DB::table('notifications')
            ->where('type', SslExpiringNotification::class)
            ->where('data->project_id', $project->id)
            ->where('created_at', '>=', now()->subHours($cooldownHours))
            ->exists();
    }

    protected function getProjectMembers(Project $project)
    {
        $members = collect();

        foreach ($project->managers as $manager) {
            $members->push($manager);
        }
        if ($members->isEmpty() && $project->manager_id) {
            $members->push(User::find($project->manager_id));
        }
        if ($project->developer_id) {
            $members->push(User::find($project->developer_id));
        }
        foreach ($project->developers as $dev) {
            $members->push($dev);
        }
        $members = $members->merge(User::where('role', 'admin')->get());

        return $members->filter()->unique('id');
    }
}
