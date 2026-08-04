<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Bound every pre-existing token that has no expires_at.
 *
 * Until now config('sanctum.expiration') capped all tokens at 8 hours, so rows
 * were written with expires_at NULL and were expired by the global rule. That
 * rule is gone as of this commit, which would make every one of those rows
 * immortal. Give each the expiry it effectively already had.
 *
 * Deliberately loops in PHP rather than using SQL date arithmetic: the test
 * suite runs on SQLite and production on MySQL, and their interval syntax
 * differs. The table is small.
 */
return new class extends Migration
{
    public function up(): void
    {
        $minutes = (int) config('sanctum.session_expiration', 480);

        DB::table('personal_access_tokens')
            ->whereNull('expires_at')
            ->orderBy('id')
            ->chunkById(500, function ($tokens) use ($minutes) {
                foreach ($tokens as $token) {
                    $issuedAt = $token->created_at
                        ? Carbon::parse($token->created_at)
                        : Carbon::now();

                    DB::table('personal_access_tokens')
                        ->where('id', $token->id)
                        ->update(['expires_at' => $issuedAt->addMinutes($minutes)]);
                }
            });
    }

    public function down(): void
    {
        // Irreversible by design. Clearing expires_at would resurrect tokens
        // that are meant to be dead.
    }
};
