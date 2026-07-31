<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * credentials.username has carried the 'encrypted' model cast since the
 * initial schema, but the column was created as VARCHAR(255). The encrypted
 * cast stores a base64-encoded JSON blob (~190-400+ chars depending on
 * plaintext length), so longer usernames — e.g. email addresses — overflow
 * the column with SQLSTATE[22001] "Data too long for column 'username'".
 * Same bug and same fix as 2026_07_07_120100_encrypt_existing_health_check_secrets
 * applied to projects.health_check_secret: widen to TEXT.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Ciphertext overflows the legacy VARCHAR(255). Widen to TEXT.
        // MySQL only — SQLite does not enforce VARCHAR length, so its string
        // column already fits any ciphertext; schema parity is restored on
        // mysql only.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `credentials` MODIFY `username` TEXT NULL');
        }
    }

    public function down(): void
    {
        // Best-effort rollback to the legacy width. Deliberately does NOT
        // truncate or rewrite data — ciphertexts longer than 255 chars simply
        // would not fit and would make this ALTER fail, which is preferable
        // to silently corrupting encrypted values.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `credentials` MODIFY `username` VARCHAR(255) NULL');
        }
    }
};
