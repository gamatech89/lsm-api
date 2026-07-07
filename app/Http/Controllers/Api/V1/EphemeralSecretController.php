<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\EphemeralSecret;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EphemeralSecretController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'username' => 'nullable|string|max:1000',
            'password' => 'nullable|string|max:5000',
            'url' => 'nullable|string|max:2000',
            'note' => 'nullable|string|max:10000',
            'expires_in_minutes' => 'required|integer|min:5|max:10080',
            'access_password' => 'nullable|string|min:4',
        ]);

        $fields = array_filter([
            'username' => $validated['username'] ?? null,
            'password' => $validated['password'] ?? null,
            'url' => $validated['url'] ?? null,
            'note' => $validated['note'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        if (empty($fields)) {
            return $this->errorResponse('At least one of username, password, url or note is required.', 422);
        }

        $secret = EphemeralSecret::create([
            'token' => Str::random(40),
            'created_by' => auth()->id(),
            'title' => $validated['title'] ?? null,
            'payload' => $fields,
            'access_password' => $validated['access_password'] ?? null,
            'expires_at' => now()->addMinutes($validated['expires_in_minutes']),
        ]);

        activity()->causedBy(auth()->user())->performedOn($secret)->log('created ephemeral secret');

        return $this->createdResponse([
            'link' => rtrim(config('app.frontend_url'), '/') . '/s/' . $secret->token,
            'expires_at' => $secret->expires_at,
        ]);
    }

    protected function unavailableReason(?EphemeralSecret $secret): string
    {
        if (! $secret) {
            return 'not_found';
        }
        if ($secret->isBurned()) {
            return 'viewed';
        }
        if ($secret->isExpired()) {
            return 'expired';
        }
        return 'not_found';
    }
}
