<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The CEO roadmap at wartung-ls.com/roadmap is a static page encrypted with a password
 * (the host can't password-protect folders). Admins and project managers who are logged
 * in to LSM get that password here, so the page opens for them without typing it.
 * Everyone else still types the password on the page.
 */
class RoadmapController extends Controller
{
    public function key(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || $user->isManager(), 403);

        $password = config('services.roadmap.password');
        abort_if(blank($password), 404);

        return response()
            ->json(['password' => $password])
            ->header('Cache-Control', 'no-store, private');
    }
}
