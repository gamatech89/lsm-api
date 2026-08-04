<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            // 'session' (issued by login/2FA/refresh) or 'integration'
            // (issued by IntegrationTokenController). The controller filters on
            // this so revoking an integration never logs anybody out.
            $table->string('type', 20)->default('session')->after('abilities')->index();
            $table->string('created_from_ip', 45)->nullable()->after('type');
            $table->string('last_used_ip', 45)->nullable()->after('last_used_at');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropIndex(['type']);
            $table->dropColumn(['type', 'created_from_ip', 'last_used_ip']);
        });
    }
};
