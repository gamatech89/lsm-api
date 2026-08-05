<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\UserResource;
use App\Models\User;
use App\Notifications\TwoFactorCodeNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorController extends Controller
{
    public function __construct(private Google2FA $google2fa) {}

    /**
     * Generate a new TOTP secret and return the QR code URL for scanning.
     * Requires auth. Does not enable 2FA until confirmed.
     */
    public function enable(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->two_factor_confirmed_at) {
            return $this->errorResponse('Two-factor authentication is already enabled', 422);
        }

        $secret = $this->google2fa->generateSecretKey();
        $user->update(['two_factor_secret' => $secret]);

        $qrCodeUrl = $this->google2fa->getQRCodeUrl(
            config('app.name'),
            $user->email,
            $secret
        );

        return $this->successResponse([
            'secret' => $secret,
            'qr_code_url' => $qrCodeUrl,
        ]);
    }

    /**
     * Confirm 2FA by verifying the first TOTP code.
     * Sets two_factor_confirmed_at and returns recovery codes.
     */
    public function confirm(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string|digits:6',
        ]);

        $user = $request->user();

        if (!$user->two_factor_secret) {
            return $this->errorResponse('Please enable 2FA first', 422);
        }

        if ($user->two_factor_confirmed_at) {
            return $this->errorResponse('Two-factor authentication is already confirmed', 422);
        }

        if (!$this->google2fa->verifyKey($user->two_factor_secret, $request->code)) {
            return $this->errorResponse('Invalid verification code', 422);
        }

        $recoveryCodes = $this->generateRecoveryCodes();

        $user->update([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $recoveryCodes['hashed'],
        ]);

        return $this->successResponse([
            'recovery_codes' => $recoveryCodes['plain'],
        ], 'Two-factor authentication enabled');
    }

    /**
     * Disable 2FA. Requires current password confirmation.
     */
    public function disable(Request $request): JsonResponse
    {
        $request->validate([
            'password' => 'required|string',
        ]);

        $user = $request->user();

        if (!Hash::check($request->password, $user->password)) {
            return $this->errorResponse('Invalid password', 422);
        }

        if (!$user->two_factor_confirmed_at) {
            return $this->errorResponse('Two-factor authentication is not enabled', 422);
        }

        $user->update([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
        ]);

        return $this->successResponse(null, 'Two-factor authentication disabled');
    }

    /**
     * Verify TOTP code (or recovery code) after login and issue a Sanctum token.
     * Public endpoint — authenticated via short-lived cache token from login step.
     */
    public function verify(Request $request): JsonResponse
    {
        $request->validate([
            'two_factor_token' => 'required|string',
            'code' => 'required|string',
            'device_name' => 'nullable|string|max:255',
        ]);

        $cacheKey = "2fa_pending:{$request->two_factor_token}";
        $userId = Cache::get($cacheKey);

        if (!$userId) {
            return $this->errorResponse('Invalid or expired session. Please log in again.', 401);
        }

        $user = User::find($userId);

        // Reject only if the user has no 2FA method at all. Email-only users
        // have two_factor_email_enabled set but two_factor_confirmed_at null.
        if (!$user || (!$user->two_factor_confirmed_at && !$user->two_factor_email_enabled)) {
            Cache::forget($cacheKey);
            return $this->errorResponse('Invalid session', 401);
        }

        $valid = false;

        // Authenticator app (TOTP) or recovery code — only when a secret is set.
        if ($user->two_factor_secret) {
            $valid = $this->google2fa->verifyKey($user->two_factor_secret, $request->code)
                || $this->checkAndConsumeRecoveryCode($user, $request->code);
        }

        // Emailed one-time code.
        if (!$valid && $user->two_factor_email_enabled) {
            $valid = $this->checkEmailCode($request->two_factor_token, $request->code);
        }

        if (!$valid) {
            return $this->errorResponse('Invalid verification code', 422);
        }

        Cache::forget($cacheKey);

        $deviceName = $request->device_name ?? 'web-browser';
        $token = $user->createToken(
            $deviceName,
            ['*'],
            now()->addMinutes(config('sanctum.session_expiration', 480))
        )->plainTextToken;

        return $this->successResponse([
            'user' => new UserResource($user),
            'token' => $token,
            'token_type' => 'Bearer',
        ], 'Login successful');
    }

    /**
     * Enable email-only 2FA (no authenticator app required).
     */
    public function enableEmail(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->two_factor_email_enabled) {
            return $this->errorResponse('Email two-factor authentication is already enabled', 422);
        }

        $user->update(['two_factor_email_enabled' => true]);

        return $this->successResponse(null, 'Email two-factor authentication enabled');
    }

    /**
     * Disable email-only 2FA. Requires current password confirmation.
     */
    public function disableEmail(Request $request): JsonResponse
    {
        $request->validate([
            'password' => 'required|string',
        ]);

        $user = $request->user();

        if (!Hash::check($request->password, $user->password)) {
            return $this->errorResponse('Invalid password', 422);
        }

        if (!$user->two_factor_email_enabled) {
            return $this->errorResponse('Email two-factor authentication is not enabled', 422);
        }

        $user->update(['two_factor_email_enabled' => false]);

        return $this->successResponse(null, 'Email two-factor authentication disabled');
    }

    /**
     * Send a one-time email code as an alternative to TOTP.
     * Public endpoint — authenticated via the short-lived pending token from login.
     */
    public function sendEmailCode(Request $request): JsonResponse
    {
        $request->validate([
            'two_factor_token' => 'required|string',
        ]);

        $cacheKey = "2fa_pending:{$request->two_factor_token}";
        $userId = Cache::get($cacheKey);

        if (!$userId) {
            return $this->errorResponse('Invalid or expired session. Please log in again.', 401);
        }

        $user = User::find($userId);

        if (!$user || !$user->two_factor_confirmed_at) {
            Cache::forget($cacheKey);
            return $this->errorResponse('Invalid session', 401);
        }

        // Rate-limit: one email per minute per pending token
        $rateLimitKey = "2fa_email_sent:{$request->two_factor_token}";
        if (Cache::has($rateLimitKey)) {
            return $this->errorResponse('Please wait a moment before requesting another code.', 429);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Cache::put("2fa_email_code:{$request->two_factor_token}", $code, now()->addMinutes(10));
        Cache::put($rateLimitKey, true, now()->addMinute());

        $user->notify(new TwoFactorCodeNotification($code));

        return $this->successResponse(null, 'Verification code sent to ' . $user->email);
    }

    /**
     * Regenerate recovery codes. Invalidates existing ones.
     */
    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->two_factor_confirmed_at) {
            return $this->errorResponse('Two-factor authentication is not enabled', 422);
        }

        $recoveryCodes = $this->generateRecoveryCodes();
        $user->update(['two_factor_recovery_codes' => $recoveryCodes['hashed']]);

        return $this->successResponse([
            'recovery_codes' => $recoveryCodes['plain'],
        ], 'Recovery codes regenerated');
    }

    private function generateRecoveryCodes(): array
    {
        $plain = [];
        $hashed = [];

        for ($i = 0; $i < 8; $i++) {
            $code = strtolower(Str::random(4) . '-' . Str::random(4));
            $plain[] = $code;
            $hashed[] = bcrypt($code);
        }

        return ['plain' => $plain, 'hashed' => $hashed];
    }

    private function checkEmailCode(string $pendingToken, string $code): bool
    {
        $cacheKey = "2fa_email_code:{$pendingToken}";
        $stored = Cache::get($cacheKey);

        if ($stored && hash_equals($stored, $code)) {
            Cache::forget($cacheKey);
            return true;
        }

        return false;
    }

    private function checkAndConsumeRecoveryCode(User $user, string $code): bool
    {
        if (!$user->two_factor_recovery_codes) {
            return false;
        }

        $codes = $user->two_factor_recovery_codes;

        foreach ($codes as $index => $hashedCode) {
            if (Hash::check($code, $hashedCode)) {
                array_splice($codes, $index, 1);
                $user->update(['two_factor_recovery_codes' => $codes]);
                return true;
            }
        }

        return false;
    }
}
