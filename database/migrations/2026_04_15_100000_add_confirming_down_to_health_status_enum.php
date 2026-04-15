<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add 'confirming_down' to the health_status enum on the projects table.
     *
     * The uptime checker uses a confirm-before-alert pattern: the first failure
     * sets health_status to 'confirming_down', and only a second consecutive
     * failure triggers a 'down_error' notification.  This value was never added
     * to the enum, causing MySQL truncation warnings (SQLSTATE[01000] / 1265)
     * that bubble up as false "site down" notifications.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE projects MODIFY COLUMN health_status ENUM('online', 'down_error', 'updating', 'confirming_down') NOT NULL DEFAULT 'online'");
    }

    public function down(): void
    {
        // Reset any 'confirming_down' rows to 'online' before shrinking the enum
        DB::statement("UPDATE projects SET health_status = 'online' WHERE health_status = 'confirming_down'");
        DB::statement("ALTER TABLE projects MODIFY COLUMN health_status ENUM('online', 'down_error', 'updating') NOT NULL DEFAULT 'online'");
    }
};
