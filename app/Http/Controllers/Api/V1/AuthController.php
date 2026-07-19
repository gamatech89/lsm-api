<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\LoginRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use App\Models\User;
use App\Notifications\TwoFactorCodeNotification;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * Authentication Controller
 * 
 * Handles API authentication via Laravel Sanctum tokens.
 * Provides login, logout, and current user endpoints.
 */
class AuthController extends Controller
{
    /**
     * Authenticate user and return a Sanctum token.
     *
     * @param LoginRequest $request
     * @return JsonResponse
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return $this->errorResponse('Invalid credentials', 401);
        }

        if ($user->two_factor_confirmed_at) {
            $pendingToken = Str::random(64);
            Cache::put("2fa_pending:{$pendingToken}", $user->id, now()->addMinutes(10));

            return $this->successResponse([
                'two_factor_required' => true,
                'two_factor_token' => $pendingToken,
                'method' => 'totp',
            ]);
        }

        if ($user->two_factor_email_enabled) {
            $pendingToken = Str::random(64);
            Cache::put("2fa_pending:{$pendingToken}", $user->id, now()->addMinutes(10));

            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            Cache::put("2fa_email_code:{$pendingToken}", $code, now()->addMinutes(10));

            $user->notify(new TwoFactorCodeNotification($code));

            return $this->successResponse([
                'two_factor_required' => true,
                'two_factor_token' => $pendingToken,
                'method' => 'email',
            ]);
        }

        // Create a new token for the device
        $deviceName = $request->device_name ?? 'mobile-app';
        $token = $user->createToken($deviceName)->plainTextToken;

        return $this->successResponse([
            'user' => new UserResource($user),
            'token' => $token,
            'token_type' => 'Bearer',
        ], 'Login successful');
    }

    /**
     * Revoke the current access token (logout).
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function logout(Request $request): JsonResponse
    {
        // Revoke the token that was used to authenticate the current request
        $request->user()->currentAccessToken()->delete();

        return $this->successResponse(null, 'Logged out successfully');
    }

    /**
     * Revoke all tokens for the user (logout from all devices).
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function logoutAll(Request $request): JsonResponse
    {
        // Revoke all tokens for the user
        $request->user()->tokens()->delete();

        return $this->successResponse(null, 'Logged out from all devices');
    }

    /**
     * Get the currently authenticated user.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function user(Request $request): JsonResponse
    {
        return $this->successResponse(
            new UserResource($request->user())
        );
    }

    /**
     * Refresh the current token (issue a new one, revoke the old).
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();
        
        // Get the device name from the current token or use default
        $deviceName = $request->user()->currentAccessToken()->name ?? 'mobile-app';
        
        // Revoke current token
        $request->user()->currentAccessToken()->delete();
        
        // Create new token
        $token = $user->createToken($deviceName)->plainTextToken;

        return $this->successResponse([
            'token' => $token,
            'token_type' => 'Bearer',
        ], 'Token refreshed');
    }

    /**
     * Process a password reset using a token from the email link.
     * Public endpoint - no auth required.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                // Revoke all existing tokens for security
                $user->tokens()->delete();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return $this->successResponse(null, 'Password has been reset successfully');
        }

        return $this->errorResponse(
            __($status),
            422
        );
    }

    /**
     * Update the authenticated user's profile.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $request->user()->id,
            'billing_company_name' => 'sometimes|nullable|string|max:255',
            'billing_address' => 'sometimes|nullable|string|max:1000',
            'billing_tax_id' => 'sometimes|nullable|string|max:100',
        ]);

        $user = $request->user();
        $user->update($request->only([
            'name', 'email',
            'billing_company_name', 'billing_address', 'billing_tax_id',
        ]));

        return $this->successResponse(
            new UserResource($user->fresh()),
            'Profile updated successfully'
        );
    }

    /**
     * Change the authenticated user's password (self-service).
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function changePassword(Request $request)
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', PasswordRule::defaults(), 'confirmed'],
        ]);

        $user = $request->user();

        if (! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => __('The provided password does not match your current password.'),
            ]);
        }

        $user->update(['password' => Hash::make($validated['password'])]);

        // Revoke all other tokens for security, but keep the current session's
        // token valid so the user isn't logged out of the request they just made.
        $currentToken = $user->currentAccessToken();
        $tokensQuery = $user->tokens();
        if ($currentToken && ! $currentToken instanceof \Laravel\Sanctum\TransientToken) {
            $tokensQuery = $tokensQuery->where('id', '!=', $currentToken->id);
        }
        $tokensQuery->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Update the authenticated user's billing information.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function updateBilling(Request $request): JsonResponse
    {
        $request->validate([
            'billing_company_name' => 'nullable|string|max:255',
            'billing_address' => 'nullable|string|max:1000',
            'billing_tax_id' => 'nullable|string|max:100',
            'invoice_prefix' => 'nullable|string|max:10',
        ]);

        $user = $request->user();
        $user->update($request->only([
            'billing_company_name',
            'billing_address',
            'billing_tax_id',
            'invoice_prefix',
        ]));

        return $this->successResponse(
            new UserResource($user->fresh()),
            'Billing information updated successfully'
        );
    }
}

