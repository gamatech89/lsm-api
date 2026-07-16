<?php
// app/Http/Middleware/AuthenticateLsmPlugin.php

namespace App\Http\Middleware;

use App\Models\Project;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates requests from the Landeseiten Maintenance WP plugin.
 *
 * The plugin sends the per-site API key in the X-LSM-Key header. The key is
 * matched against projects.health_check_secret_hash (SHA-256), the same
 * mechanism the legacy support-ticket webhook uses. The resolved project is
 * exposed as the 'lsm_project' request attribute.
 */
class AuthenticateLsmPlugin
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) $request->header('X-LSM-Key', '');

        if ($key === '') {
            return response()->json(['success' => false, 'message' => 'Missing API key'], 401);
        }

        $project = Project::where('health_check_secret_hash', hash('sha256', $key))->first();

        if (!$project) {
            return response()->json(['success' => false, 'message' => 'Invalid API key'], 401);
        }

        $request->attributes->set('lsm_project', $project);

        return $next($request);
    }
}
