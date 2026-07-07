<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // Deterministic sha256 of the plaintext secret, used for equality
            // lookups (e.g. the plugin webhook) now that the secret itself is
            // encrypted with a non-deterministic cipher.
            $table->string('health_check_secret_hash', 64)->nullable()->index()->after('health_check_secret');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex(['health_check_secret_hash']);
            $table->dropColumn('health_check_secret_hash');
        });
    }
};
