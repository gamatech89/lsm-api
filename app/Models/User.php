<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Notifications\ResetPasswordNotification;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, SoftDeletes, HasApiTokens;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_admin',
        'last_login_at',
        'hourly_rate',
        'billing_company_name',
        'billing_address',
        'billing_tax_id',
        'invoice_prefix',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
        'two_factor_email_enabled',
    ];

    /**
     * Default attribute values
     */
    protected $attributes = [
        'hourly_rate' => 22.50,
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_login_at' => 'datetime',
            'is_admin' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_email_enabled' => 'boolean',
        ];
    }

    /**
     * Check if user is an admin.
     * Returns true for admin role OR any user with the is_admin flag.
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin' || $this->is_admin;
    }

    /**
     * Whether this user is required to set up 2FA before they can proceed.
     * Enforced roles are configurable (config('auth.mfa_enforced_roles')),
     * defaulting to admins. Either TOTP or email 2FA counts as enrolled.
     */
    public function mustEnrollTwoFactor(): bool
    {
        $enforcedRoles = config('auth.mfa_enforced_roles', ['admin']);

        if (! in_array($this->role, $enforcedRoles, true)) {
            return false;
        }

        return $this->two_factor_confirmed_at === null && ! $this->two_factor_email_enabled;
    }

    /**
     * Check if user is a manager.
     */
    public function isManager(): bool
    {
        return $this->role === 'manager';
    }

    /**
     * Check if user is a developer.
     */
    public function isDeveloper(): bool
    {
        return $this->role === 'developer';
    }

    /**
     * Get the projects managed by the user.
     */
    public function managedProjects(): HasMany
    {
        return $this->hasMany(Project::class, 'manager_id');
    }

    /**
     * Get the projects developed by the user (legacy single developer field).
     */
    public function developedProjects(): HasMany
    {
        return $this->hasMany(Project::class, 'developer_id');
    }

    /**
     * Get all projects where this user is assigned as a developer (many-to-many).
     */
    public function assignedProjects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_developer')
            ->withTimestamps();
    }

    /**
     * Get the tags assigned to this user.
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->withTimestamps();
    }

    /**
     * Send the password reset notification with a frontend URL.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    /**
     * Long-lived tokens minted for external integrations (MCP clients).
     * Deliberately excludes session tokens so the management UI can never
     * list or revoke the caller's own login.
     */
    public function integrationTokens(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->tokens()
            ->where('type', 'integration')
            ->orderByDesc('created_at');
    }
}
