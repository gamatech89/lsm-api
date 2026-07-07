<?php

namespace App\Console\Commands;

use App\Models\EphemeralSecret;
use Illuminate\Console\Command;

class PurgeEphemeralSecrets extends Command
{
    protected $signature = 'ephemeral-secrets:purge';

    protected $description = 'Delete expired or already-viewed ephemeral secrets past the 7-day retention window';

    public function handle(): int
    {
        $cutoff = now()->subDays(7);

        $count = EphemeralSecret::query()
            ->where(fn ($q) => $q->where('expires_at', '<', $cutoff)
                                  ->orWhere('viewed_at', '<', $cutoff))
            ->delete();

        $this->info("Purged {$count} ephemeral secret(s).");

        return self::SUCCESS;
    }
}
