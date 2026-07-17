<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE projects MODIFY COLUMN health_status ENUM('online', 'down_error', 'updating', 'confirming_down') NOT NULL DEFAULT 'online'");

            return;
        }

        // On SQLite the original enum() produced a varchar with a CHECK
        // constraint that rejects 'confirming_down'. Rebuilding the column as
        // a plain string drops that constraint.
        Schema::table('projects', function (Blueprint $table) {
            $table->string('health_status')->default('online')->change();
        });
    }

    public function down(): void
    {
        // Reset any 'confirming_down' rows to 'online' before shrinking the enum
        DB::statement("UPDATE projects SET health_status = 'online' WHERE health_status = 'confirming_down'");

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE projects MODIFY COLUMN health_status ENUM('online', 'down_error', 'updating') NOT NULL DEFAULT 'online'");
        }
    }
};
