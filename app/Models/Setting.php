<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Dynamic settings storage.
 * 
 * Settings are stored in the database and cached for performance.
 * They override static config values from .env files.
 */
class Setting extends Model
{
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['key', 'value', 'group'];

    protected $casts = [
        'value' => 'json',
    ];

    /**
     * Cache TTL in seconds (1 hour).
     */
    private const CACHE_TTL = 3600;

    /**
     * Get a setting value.
     *
     * @param string $key The setting key (e.g., 'uptime.interval')
     * @param mixed $default Default value if not found
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        try {
            $cacheKey = "setting:{$key}";

            return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($key, $default) {
                $setting = static::find($key);
                return $setting ? $setting->value : $default;
            });
        } catch (\Exception $e) {
            // Table might not exist yet (during migrations)
            return $default;
        }
    }

    /**
     * Set a setting value.
     *
     * @param string $key The setting key
     * @param mixed $value The value to store
     * @param string $group Optional group for organization
     * @return static|null
     */
    public static function set(string $key, mixed $value, string $group = 'general'): ?static
    {
        try {
            $setting = static::updateOrCreate(
                ['key' => $key],
                ['value' => $value, 'group' => $group]
            );

            // Clear cache
            Cache::forget("setting:{$key}");
            Cache::forget('settings:all');

            return $setting;
        } catch (\Exception $e) {
            // Table might not exist yet
            return null;
        }
    }

    /**
     * Get all settings, optionally filtered by group.
     *
     * @param string|null $group
     * @return array
     */
    public static function getAll(?string $group = null): array
    {
        try {
            $cacheKey = $group ? "settings:group:{$group}" : 'settings:all';

            return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($group) {
                $query = static::query();
                
                if ($group) {
                    $query->where('group', $group);
                }

                return $query->pluck('value', 'key')->toArray();
            });
        } catch (\Exception $e) {
            // Table might not exist yet
            return [];
        }
    }

    /**
     * Set multiple settings at once.
     *
     * @param array $settings Key-value pairs
     * @param string $group
     */
    public static function setMany(array $settings, string $group = 'general'): void
    {
        foreach ($settings as $key => $value) {
            static::set($key, $value, $group);
        }
    }

    /**
     * Get a setting with fallback to config value.
     * This allows database settings to override .env config.
     *
     * @param string $key Setting key (e.g., 'uptime.interval')
     * @param string|null $configKey Config key if different (e.g., 'uptime.check_interval')
     * @return mixed
     */
    public static function getOrConfig(string $key, ?string $configKey = null): mixed
    {
        $configKey = $configKey ?? $key;
        
        try {
            $dbValue = static::get($key);
            
            if ($dbValue !== null) {
                return $dbValue;
            }
        } catch (\Exception $e) {
            // Table might not exist yet
        }

        return config($configKey);
    }

    /**
     * Clear all settings cache.
     */
    public static function clearCache(): void
    {
        Cache::forget('settings:all');
        
        // Clear individual setting caches
        foreach (static::pluck('key') as $key) {
            Cache::forget("setting:{$key}");
        }
    }
}
