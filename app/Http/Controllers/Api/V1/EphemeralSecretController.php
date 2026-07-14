<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\EphemeralSecret;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class EphemeralSecretController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'type' => 'nullable|string|max:50',
            'username' => 'nullable|string|max:1000',
            'password' => 'nullable|string|max:5000',
            'url' => 'nullable|string|max:2000',
            'hostname' => 'nullable|string|max:255',
            'port' => 'nullable|string|max:20',
            'database_name' => 'nullable|string|max:255',
            'note' => 'nullable|string|max:10000',
            'expires_in_minutes' => 'required|integer|min:5|max:10080',
            'access_password' => 'nullable|string|min:4',
        ]);

        $fields = array_filter([
            'type' => $validated['type'] ?? null,
            'username' => $validated['username'] ?? null,
            'password' => $validated['password'] ?? null,
            'url' => $validated['url'] ?? null,
            'hostname' => $validated['hostname'] ?? null,
            'port' => $validated['port'] ?? null,
            'database_name' => $validated['database_name'] ?? null,
            'note' => $validated['note'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        // Require at least one actual secret value — a type label alone is not enough.
        $secretKeys = ['username', 'password', 'url', 'hostname', 'port', 'database_name', 'note'];
        if (empty(array_intersect_key($fields, array_flip($secretKeys)))) {
            return $this->errorResponse('At least one of username, password, URL, host or note is required.', 422);
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

    public function show(string $token): JsonResponse
    {
        $secret = EphemeralSecret::where('token', $token)->first();

        if (! $secret || ! $secret->isAvailable()) {
            return response()->json([
                'available' => false,
                'reason' => $this->unavailableReason($secret),
            ], 404);
        }

        return response()->json([
            'available' => true,
            'title' => $secret->title,
            'has_password' => ! empty($secret->access_password),
            'expires_at' => $secret->expires_at,
        ]);
    }

    public function access(Request $request, string $token): JsonResponse
    {
        $data = null;

        $result = DB::transaction(function () use ($request, $token, &$data) {
            $secret = EphemeralSecret::where('token', $token)->lockForUpdate()->first();

            if (! $secret || ! $secret->isAvailable()) {
                return ['reason' => $this->unavailableReason($secret)];
            }

            if (! empty($secret->access_password)
                && ! Hash::check((string) $request->input('password'), $secret->access_password)) {
                return ['password_error' => true];
            }

            $data = $secret->payload;                 // decrypted array
            $secret->payload = null;                  // burn
            $secret->viewed_at = now();
            $secret->last_viewed_ip = $request->ip();
            $secret->save();

            return ['secret' => $secret];
        });

        if (! empty($result['password_error'])) {
            return response()->json(['available' => true, 'message' => 'Incorrect password.'], 403);
        }

        if (empty($result['secret'])) {
            return response()->json(['available' => false, 'reason' => $result['reason']], 404);
        }

        activity()->performedOn($result['secret'])
            ->withProperties(['ip' => $request->ip()])
            ->log('revealed ephemeral secret');

        return response()->json([
            'data' => array_merge(['title' => $result['secret']->title], $data),
            'revealed_once' => true,
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
