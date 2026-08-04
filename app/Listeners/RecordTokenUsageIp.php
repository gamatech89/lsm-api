<?php

namespace App\Listeners;

use Illuminate\Http\Request;
use Laravel\Sanctum\Events\TokenAuthenticated;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Record the IP a token is being used from.
 *
 * TokenAuthenticated fires once per authenticated request, so this writes only
 * when the IP actually changed — otherwise every request would cost an UPDATE.
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
