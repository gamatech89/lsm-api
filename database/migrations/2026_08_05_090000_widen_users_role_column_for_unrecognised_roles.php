<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Widen users.role from a closed enum to a plain string.
     *
     * Task 6 (integration tokens) added StoreIntegrationTokenRequest::FALLBACK_SCOPES
     * specifically so that a role this class has never heard of falls back to
     * read-only instead of inheriting a mid-tier role's privileges. That
     * defence is meant to survive real deployments — a future role added
     * without updating ROLE_SCOPES, a bad data import, a manual DB edit — but
     * ENUM('admin','manager','developer','viewer') makes the scenario
     * unreachable everywhere, including in tests: on MySQL the write is
     * rejected in strict mode (same class of failure as the todos.status
     * enum in 2026_07_31_090000); on SQLite it trips the CHECK constraint
     * enum() produces (SQLSTATE 23000). Application-level role assignment
     * already enforces the closed set via 'role' => 'required|in:admin,
     * manager,developer,viewer' in TeamController, independently of the
     * column type, so this does not change what a real user can be assigned
     * through the app — it only lets the column hold a value the app-level
     * gate didn't originate, which is exactly the scenario the fallback
     * exists to defend against.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY COLUMN role VARCHAR(255) NOT NULL DEFAULT 'viewer'");

            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('viewer')->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'manager', 'developer', 'viewer') NOT NULL DEFAULT 'viewer'");

            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['admin', 'manager', 'developer', 'viewer'])->default('viewer')->change();
        });
    }
};
