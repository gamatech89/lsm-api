<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CredentialResource;
use App\Models\Credential;
use App\Models\CredentialShareLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Gate;

use Illuminate\Support\Facades\Log;

class CredentialShareController extends Controller
{
    /**
     * Create a share link for a credential.
     */
    public function store(Request $request, Credential $credential): JsonResponse
    {
        Gate::authorize('manageCredentials', $credential->project);

        $validated = $request->validate([
            'expires_in_minutes' => 'required|integer|min:5|max:10080', // Max 1 week
            'max_views' => 'required|integer|min:1|max:50',
            'access_password' => 'nullable|string|min:4',
            'recipient_email' => 'nullable|email',
            'note' => 'nullable|string|max:500',
        ]);

        $token = Str::random(32);

        $shareLink = $credential->shareLinks()->create([
            'created_by' => auth()->id(),
            'token' => $token,
            'access_password' => $validated['access_password'] ?? null,
            'expires_at' => now()->addMinutes($validated['expires_in_minutes']),
            'max_views' => $validated['max_views'],
            'recipient_email' => $validated['recipient_email'] ?? null,
            'note' => $validated['note'] ?? null,
            // Default visibility settings
            'show_username' => true,
            'show_password' => true,
            'show_url' => true,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'link' => config('app.frontend_url', 'http://localhost:3000') . '/share/' . $token,
                'expires_at' => $shareLink->expires_at,
            ]
        ]);
    }

    public function show(string $token): JsonResponse
    {
        $link = CredentialShareLink::where('token', $token)->first();

        if (!$link || $link->expires_at <= now() || $link->view_count >= $link->max_views) {
            return response()->json([
                'success' => false,
                'message' => 'This link is invalid or has expired.'
            ], 404);
        }
        
        return response()->json([
            'success' => true,
            'data' => [
                'has_password' => !empty($link->access_password),
                'note' => $link->note,
                'expires_at' => $link->expires_at,
                'credential_title' => $link->credential->title, // Exposed title
                'credential_type' => $link->credential->type,
            ]
        ]);
    }

    /**
     * Access the shared credential data.
     */
    public function access(Request $request, string $token): JsonResponse
    {
        $link = CredentialShareLink::where('token', $token)
            ->where('expires_at', '>', now())
            ->first();

        if (!$link || $link->view_count >= $link->max_views) {
            return response()->json([
                'success' => false,
                'message' => 'This link is invalid or has expired.'
            ], 404);
        }

        // Check password if set
        if (!empty($link->access_password)) {
            if ($request->input('password') !== $link->access_password) {
                return response()->json([
                    'success' => false,
                    'message' => 'Incorrect password.'
                ], 403);
            }
        }

        // Increment view count
        $link->increment('view_count');
        $link->update([
            'last_viewed_at' => now(),
            'last_viewed_ip' => $request->ip()
        ]);

        // Log access
        $link->accessLogs()->create([
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'accessed_at' => now(),
        ]);

        // Return the full credential resource with password revealed
        return response()->json([
            'success' => true,
            'data' => CredentialResource::withPassword($link->credential)
        ]);
    }
}
