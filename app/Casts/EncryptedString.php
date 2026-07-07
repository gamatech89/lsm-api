<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Encrypts a string attribute at rest, decrypting transparently on read.
 *
 * Unlike Laravel's built-in `encrypted` cast, this gracefully returns any value
 * that is not valid ciphertext as-is. That makes it safe to enable on a column
 * that still contains legacy plaintext during the migration window — reads of
 * un-migrated rows keep working instead of throwing DecryptException.
 */
class EncryptedString implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return $value === '' ? '' : null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Throwable $e) {
            // Legacy plaintext (or a value encrypted with a different key) — return raw.
            return $value;
        }
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return Crypt::encryptString((string) $value);
    }
}
