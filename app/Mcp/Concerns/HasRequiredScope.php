<?php

namespace App\Mcp\Concerns;

use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Response;

/**
 * Gate an MCP primitive on a token ability.
 *
 * shouldRegister() is the enforcement boundary, on both listing AND
 * invocation. Primitive::eligibleForRegistration() calls it, and every
 * dispatch path — CallTool (tools/call), ResolvesResources::resolveResource()
 * (resources/read), ResolvesPrompts::resolvePrompt() (prompts/get), and the
 * three list methods — all resolve primitives through
 * ServerContext::resolvePrimitives(), which filters on
 * eligibleForRegistration() before anything is invoked. An out-of-scope
 * primitive is therefore not just hidden from tools/list, resources/list and
 * prompts/list: the Server itself cannot reach its handle() at all through
 * any of those methods. It fails with "not found", never with our error
 * text.
 *
 * assertScope() at the top of handle() is a backstop, not the boundary — it
 * only fires for a caller that instantiates the primitive class directly and
 * invokes handle() itself, bypassing LsmServer/ServerContext entirely (e.g.
 * a future integration point, or a test that does exactly this). Through the
 * documented JSON-RPC methods it is unreachable, because shouldRegister()
 * already filtered the primitive out one layer up.
 *
 * Do not remove either check without re-reading
 * Laravel\Mcp\Server\ServerContext::resolvePrimitives() first — that is
 * where the actual filtering happens, and shouldRegister() is the load-bearing
 * one of the two. Removing assertScope() only weakens the backstop; removing
 * shouldRegister() (or this trait) removes the enforcement entirely.
 *
 * Abilities intersect with the caller's role, they never widen it. Every tool
 * keeps its own Auth::user() role checks.
 */
trait HasRequiredScope
{
    /**
     * The token ability required to see and call this primitive. Declared as
     * an abstract method, not a property: a property here would collide with
     * the same-named property on every consuming class under PHP's property
     * redeclaration rules the moment a scope other than the trait's own
     * default were assigned, which is a fatal error at class composition
     * time, not a test failure. A method makes a missing override a loud
     * "must implement abstract method" fatal instead of a silent read-only
     * gate.
     */
    abstract protected function requiredScope(): string;

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
        return Auth::user()?->tokenCan($this->requiredScope()) ?? false;
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
            "This token lacks the required scope: {$this->requiredScope()}. "
            . 'Create a token with that scope under Profil → API & Integrationen.'
        );
    }
}
