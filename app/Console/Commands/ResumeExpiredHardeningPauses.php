<?php

namespace App\Console\Commands;

use App\Models\ProjectHardeningPause;
use App\Models\User;
use App\Notifications\HardeningPauseOverdueNotification;
use App\Services\HardeningResponseMapper;
use App\Services\LsmService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Platform backstop for "pause for download". The plugin resumes on its own
 * on the first PHP load after pause_until; this command covers sites where
 * that did not happen and pauses whose response never reached the platform.
 */
class ResumeExpiredHardeningPauses extends Command
{
    protected $signature = 'hardening:resume-expired';

    protected $description = 'Re-enable the .htaccess archive rule on sites whose hardening pause has expired';

    private const RESUME_TIMEOUT = 60;

    public function handle(): int
    {
        $pauses = ProjectHardeningPause::open()
            ->where('paused_until', '<', now())
            ->with(['project', 'user'])
            ->orderBy('id')
            ->get();

        foreach ($pauses as $pause) {
            // One broken row must never block the rows behind it.
            try {
                $this->process($pause);
            } catch (\Throwable $e) {
                $this->error("Pause #{$pause->id}: {$e->getMessage()}");
                Log::error("hardening:resume-expired failed for pause #{$pause->id}: {$e->getMessage()}");
            }
        }

        $this->info("Processed {$pauses->count()} expired hardening pause(s).");

        return self::SUCCESS;
    }

    private function process(ProjectHardeningPause $pause): void
    {
        $project = $pause->project; // null when the project is trashed

        if (!$project || !LsmService::for($project)->isConfigured()) {
            $pause->update([
                'resumed_at' => now(),
                'note' => 'Closed without resume: project deleted or LSM not configured',
            ]);
            return;
        }

        // Always POST resume (idempotent on the site) — a status GET may be a
        // cached copy, and only this response proves the rule is back.
        $mapped = HardeningResponseMapper::map(
            LsmService::for($project)->hardeningRequest('POST', '/hardening/resume', [], self::RESUME_TIMEOUT)
        );

        // The schedule entry runs in the background, so the console output below
        // is discarded and this is the only trace of a resume that did not close
        // the row. Same outcome rule as the controller's audit line (Task 13).
        Log::info('hardening', [
            'user_id' => null, // platform backstop, no acting user
            'project_id' => $project->id,
            'action' => 'resume',
            'rule' => 'block_archives',
            'outcome' => HardeningResponseMapper::auditOutcome($mapped),
        ]);

        if (($mapped['body']['status']['rules']['block_archives']['state'] ?? null) === 'on') {
            $pause->update(['resumed_at' => now(), 'note' => 'Closed by backstop: archive rule is on']);
            $this->line("  {$project->name}: archive rule is on, pause closed");
            return;
        }

        if (in_array($mapped['outcome'], ['plugin_outdated', 'unauthorized'], true)) {
            // Retrying cannot fix an old plugin or a rejected key.
            $pause->update(['failed_at' => now(), 'note' => "Gave up: {$mapped['outcome']}"]);
        } elseif ($pause->created_at->lt(now()->subDay())) {
            $pause->update(['failed_at' => now(), 'note' => 'Gave up: not resumed within 24 h']);
        }

        $this->warn("  {$project->name}: not resumed ({$mapped['outcome']})");

        // One notification per row: when it is more than 30 minutes overdue, or
        // right away when the row was just given up on (nobody looks at it again).
        $due = $pause->failed_at !== null || $pause->paused_until->lt(now()->subMinutes(30));

        if ($due && $pause->overdue_notified_at === null) {
            // Claim the row atomically (write-ahead, like the pause row itself).
            // The queue is `sync`, so a mail transport error surfaces right
            // here; stamping afterwards would re-notify everyone on every
            // ten-minute run. The WHERE overdue_notified_at IS NULL makes the
            // claim itself the concurrency guard: if another run's command
            // already stamped this row between our SELECT and this UPDATE,
            // the affected-row count is 0 and we skip notifying.
            $claimedAt = now();
            $claimed = ProjectHardeningPause::whereKey($pause->id)
                ->whereNull('overdue_notified_at')
                ->update(['overdue_notified_at' => $claimedAt]) === 1;

            if ($claimed) {
                $pause->overdue_notified_at = $claimedAt;

                $recipients = User::where('role', 'admin')->orWhere('is_admin', true)->get();
                if ($pause->user) {
                    $recipients->push($pause->user);
                }

                foreach ($recipients->unique('id') as $recipient) {
                    try {
                        $recipient->notify(new HardeningPauseOverdueNotification($project, $pause));
                    } catch (\Throwable $e) {
                        Log::error("hardening:resume-expired could not notify user #{$recipient->id} about pause #{$pause->id}: {$e->getMessage()}");
                    }
                }
            }
        }
    }
}
