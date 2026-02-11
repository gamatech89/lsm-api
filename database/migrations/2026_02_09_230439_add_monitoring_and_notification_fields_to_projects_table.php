<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // Monitoring toggles (per-project)
            $table->boolean('ssl_alerts_enabled')->default(true)->after('uptime_monitoring_enabled');
            $table->boolean('domain_alerts_enabled')->default(true)->after('ssl_alerts_enabled');

            // Notification preferences (JSON blob for flexible config)
            $table->json('notification_preferences')->nullable()->after('domain_alerts_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn([
                'ssl_alerts_enabled',
                'domain_alerts_enabled',
                'notification_preferences',
            ]);
        });
    }
};
