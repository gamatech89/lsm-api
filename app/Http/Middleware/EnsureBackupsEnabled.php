<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Master switch for the backup feature (config('backup.enabled'),
 * BACKUP_ENABLED). Applied to every backup route except GET /backups/settings,
 * which stays open so the SPA can read the flag and hide its backup UI.
 *
 * 403 rather than 404 on purpose: the routes exist, the feature is switched
 * off — a client that hits this should show "disabled", not "not found".
 */
class EnsureBackupsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('backup.enabled', false)) {
            return response()->json([
                'success' => false,
                'message' => 'Backups are currently disabled.',
            ], 403);
        }

        return $next($request);
    }
}
