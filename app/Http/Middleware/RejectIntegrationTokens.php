<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps long-lived integration tokens off the REST API entirely.
 *
 * Integration tokens are minted for MCP clients (see
 * IntegrationTokenController) and are scoped by ability (mcp:read,
 * mcp:write, mcp:wp, mcp:wp-destructive) — but `auth:sanctum` only checks
 * that a token is valid, not what abilities it carries, and none of the 260
 * routes on this API apply Sanctum's `abilities` middleware. Without this
 * gate, a token minted with `['mcp:read']` and `expires_at: null` reaches
 * every REST endpoint the user's role can reach, including credential
 * reveal, credential export, and emergency recovery — silently
 * contradicting the "this token only grants the scopes you chose" claim the
 * minting UI makes.
 *
 * This is an allowlist in spirit even though it reads as a single
 * comparison: it rejects the one token type that is known to be
 * MCP-only ('integration') and lets everything else through, rather than
 * enumerating the types allowed on the REST API. A future token kind that
 * isn't 'session' and isn't 'integration' passes through here unaffected
 * until someone deliberately decides otherwise — the same allowlist
 * discipline AuthController::refresh() applies to the refreshable set.
 *
 * `/mcp` (routes/mcp.php) carries its own `auth:sanctum` and is registered
 * separately from `routes/api.php`, so it is never touched by this
 * middleware — integration tokens keep working there.
 */
class RejectIntegrationTokens
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        // A TransientToken (cookie/session-guard auth via Inertia) has no
        // `type` property at all — dereferencing it would emit an undefined
        // property warning. It is not a Sanctum PersonalAccessToken, so it
        // can never be an integration token; let it through untouched.
        if (! $token instanceof PersonalAccessToken) {
            return $next($request);
        }

        if ($token->type === 'integration') {
            return response()->json([
                'success' => false,
                'message' => 'Integration tokens can only be used against the MCP endpoint (/mcp). '
                    .'Use a session token for the REST API.',
                'code' => 'integration_token_not_permitted',
            ], 403);
        }

        return $next($request);
    }
}
