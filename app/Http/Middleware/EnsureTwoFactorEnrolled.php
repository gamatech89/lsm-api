<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Force-enrollment gate: users whose role requires MFA (config
 * auth.mfa_enforced_roles) are blocked from all but the enrollment-related
 * routes until they set up 2FA. They can still log in, view themselves, log
 * out, and complete 2FA setup.
 */
class EnsureTwoFactorEnrolled
{
    /**
     * Routes an un-enrolled user may still reach.
     */
    private const ALLOWED_ROUTES = [
        'api.v1.user',
        'api.v1.logout',
        'api.v1.logout-all',
        'api.v1.refresh',
        'api.v1.two-factor.enable',
        'api.v1.two-factor.confirm',
        'api.v1.two-factor.email.enable',
        'api.v1.two-factor.recovery-codes',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->mustEnrollTwoFactor()) {
            return $next($request);
        }

        if (in_array($request->route()?->getName(), self::ALLOWED_ROUTES, true)) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => 'Two-factor authentication setup is required before you can continue.',
            'code' => 'two_factor_setup_required',
        ], 403);
    }
}
