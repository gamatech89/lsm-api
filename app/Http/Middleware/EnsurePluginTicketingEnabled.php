<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Kill switch for ticket intake from the WordPress plugin
 * (config('ticketing.plugin_intake_enabled'), PLUGIN_TICKETING_ENABLED).
 * Applied only to the routes that CREATE tickets or messages from a site;
 * reading existing tickets stays open so an old widget can still show them.
 *
 * 503 rather than 403 on purpose: nothing is wrong with the caller's key,
 * the service is switched off for now — the plugin shows "temporarily
 * disabled" and does not treat it as an auth problem.
 */
class EnsurePluginTicketingEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('ticketing.plugin_intake_enabled', true)) {
            return response()->json([
                'success' => false,
                'message' => 'Support ticket submission is temporarily disabled.',
            ], 503);
        }

        return $next($request);
    }
}
