<?php

namespace App\Listeners;

use Illuminate\Http\Request;
use Laravel\Sanctum\Events\TokenAuthenticated;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Record the IP a token is being used from.
 *
 * Sanctum's own Guard already writes to this row on every authenticated
 * request: it fires TokenAuthenticated and then unconditionally calls
 * updateLastUsedAt() on the same model instance (see
 * vendor/laravel/sanctum/src/Guard.php:77-79). So the IP-changed check below
 * does not avoid an UPDATE on the common case — Sanctum's own write already
 * happens regardless. It only avoids a *second*, redundant UPDATE from this
 * listener when the IP has not changed since last time.
 *
 * saveQuietly() is used so this housekeeping write doesn't fire model events.
 * Note it does not preserve the "has modified records" connection state that
 * Sanctum's own updateLastUsedAt() deliberately wraps itself in
 * (Guard.php:185-190) — on a read/write-split MySQL connection with `sticky`
 * enabled, a write from this listener can pin the rest of the request to the
 * write connection, the same way an unwrapped Sanctum save would.
 */
class RecordTokenUsageIp
{
    public function __construct(private Request $request) {}

    public function handle(TokenAuthenticated $event): void
    {
        $token = $event->token;

        // Cookie/session auth yields a TransientToken with nothing to persist.
        if (! $token instanceof PersonalAccessToken) {
            return;
        }

        $ip = $this->request->ip();

        if ($ip === null || $token->last_used_ip === $ip) {
            return;
        }

        $token->forceFill(['last_used_ip' => $ip])->saveQuietly();
    }
}
