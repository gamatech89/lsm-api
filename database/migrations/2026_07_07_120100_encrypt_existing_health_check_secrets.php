<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Backfill: encrypt any existing plaintext health_check_secret values and
 * populate the deterministic lookup hash.
 *
 * Idempotent — a value that already decrypts (i.e. is already encrypted) is
 * left as-is, only its hash is (re)computed. Safe to re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Encrypted ciphertext is far longer than the original ~32-char key and
        // overflows the legacy VARCHAR(255). Widen to TEXT first. MySQL only —
        // SQLite has no string-length limit, so its string column already fits.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `projects` MODIFY `health_check_secret` TEXT NULL');
        }

        DB::table('projects')
            ->whereNotNull('health_check_secret')
            ->where('health_check_secret', '!=', '')
            ->orderBy('id')
            ->chunkById(200, function ($projects) {
                foreach ($projects as $project) {
                    $stored = $project->health_check_secret;

                    // Determine the plaintext: decrypt if already encrypted,
                    // otherwise treat the stored value as legacy plaintext.
                    try {
                        $plaintext = Crypt::decryptString($stored);
                        $ciphertext = $stored; // already encrypted — keep it
                    } catch (\Throwable $e) {
                        $plaintext = $stored;
                        $ciphertext = Crypt::encryptString($stored);
                    }

                    DB::table('projects')
                        ->where('id', $project->id)
                        ->update([
                            'health_check_secret' => $ciphertext,
                            'health_check_secret_hash' => hash('sha256', $plaintext),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // No safe automatic down-migration: decrypting back to plaintext would
        // re-expose secrets. Leave values encrypted. Hashes are dropped by the
        // companion column migration's rollback.
    }
};
