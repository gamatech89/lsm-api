<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreIntegrationTokenRequest extends FormRequest
{
    /**
     * Every MCP ability, in the order the UI shows them.
     */
    public const SCOPES = ['mcp:read', 'mcp:write', 'mcp:wp', 'mcp:wp-destructive'];

    /**
     * Abilities a role may never mint. mcp:wp-destructive is gated at the tool
     * level to admins and managers (WpEmergencyTool, BulkWpActionTool,
     * WpRestoreBackupTool), so a developer's token carrying it would be a lie.
     */
    public const ROLE_SCOPES = [
        'admin' => ['mcp:read', 'mcp:write', 'mcp:wp', 'mcp:wp-destructive'],
        'manager' => ['mcp:read', 'mcp:write', 'mcp:wp', 'mcp:wp-destructive'],
        'developer' => ['mcp:read', 'mcp:write', 'mcp:wp'],
        'viewer' => ['mcp:read'],
    ];

    /**
     * What an unrecognised role may mint. Deliberately the narrowest set
     * rather than a mid-tier default: a role this class has never heard of
     * must not inherit another role's privileges by accident.
     */
    public const FALLBACK_SCOPES = ['mcp:read'];

    public const EXPIRY_OPTIONS = ['30d', '90d', '1y', 'never'];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in(self::SCOPES)],
            'expires_in' => ['required', Rule::in(self::EXPIRY_OPTIONS)],
            'password' => ['required', 'string'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $password = $this->input('password');

                // Step-up: a stolen session must not be enough to mint a
                // long-lived, portfolio-wide credential. Guard the type first:
                // after-callbacks still run when the base 'string' rule has
                // already failed, and Hash::check() hands a non-string straight
                // to password_verify(), which throws a TypeError rather than
                // returning false — an uncaught 500 on a credential endpoint.
                if (! is_string($password) || ! Hash::check($password, $this->user()->password)) {
                    $validator->errors()->add('password', 'The password is incorrect.');
                }

                $allowed = self::ROLE_SCOPES[$this->user()->role] ?? self::FALLBACK_SCOPES;

                foreach ((array) $this->input('scopes', []) as $scope) {
                    if (in_array($scope, self::SCOPES, true) && ! in_array($scope, $allowed, true)) {
                        $validator->errors()->add(
                            'scopes',
                            "Your role cannot issue a token with the scope {$scope}."
                        );
                    }
                }
            },
        ];
    }

    /**
     * Absolute expiry for the requested option, or null for 'never'.
     *
     * 'never' is spelled out explicitly rather than falling through to
     * 'default', so that an unrecognised value cannot silently collapse into
     * "no expiry" — EXPIRY_OPTIONS and this match are two lists that must be
     * edited together, and the failure direction if someone forgets must be
     * loud, not an immortal credential reaching a 60-site WordPress portfolio.
     */
    public function expiresAt(): ?\Illuminate\Support\Carbon
    {
        return match ($this->input('expires_in')) {
            '30d' => now()->addDays(30),
            '90d' => now()->addDays(90),
            '1y' => now()->addYear(),
            'never' => null,
            default => throw new \LogicException(
                'Unhandled expires_in value: '.var_export($this->input('expires_in'), true).
                '. EXPIRY_OPTIONS and expiresAt() must be updated together.'
            ),
        };
    }

    /**
     * Deduplicated, canonically ordered scopes.
     */
    public function scopes(): array
    {
        return array_values(array_intersect(self::SCOPES, (array) $this->input('scopes', [])));
    }
}
