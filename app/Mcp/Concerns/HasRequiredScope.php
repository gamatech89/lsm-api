<?php

namespace App\Mcp\Concerns;

use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Response;

/**
 * Gate an MCP primitive on a token ability.
 *
 * shouldRegister() is called from Primitive::eligibleForRegistration(), so an
 * out-of-scope primitive never appears in tools/list, resources/list or
 * prompts/list. A client that cannot see a tool will not try it, reason about
 * it, or explain its absence — which is the point.
 *
 * That is ergonomics, not security. assertScope() at the top of handle() is the
 * boundary: a client that skips listing and calls the method directly is still
 * refused.
 *
 * Abilities intersect with the caller's role, they never widen it. Every tool
 * keeps its own Auth::user() role checks.
 */
trait HasRequiredScope
{
    /**
     * The token ability required to see and call this primitive.
     */
    protected string $requiredScope = 'mcp:read';

    public function shouldRegister(): bool
    {
        return $this->tokenHasRequiredScope();
    }

    /**
     * A wildcard token ('*') satisfies every scope, which is what keeps the web
     * app and any pre-existing token working unchanged.
     */
    protected function tokenHasRequiredScope(): bool
    {
        return Auth::user()?->tokenCan($this->requiredScope) ?? false;
    }

    /**
     * Returns null when the call is permitted, or the error to return when it
     * is not. Callers use: if ($denied = $this->assertScope()) return $denied;
     */
    protected function assertScope(): ?Response
    {
        if ($this->tokenHasRequiredScope()) {
            return null;
        }

        return Response::error(
            "This token lacks the required scope: {$this->requiredScope}. "
            . 'Create a token with that scope under Profil → API & Integrationen.'
        );
    }
}
