<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add 'in_review' to the todos.status enum.
     *
     * Commit a6918d9 (Apr 22) taught TodoController to accept 'in_review'
     * (and to coerce developer 'completed' updates into it), but no migration
     * ever extended the enum. On production MySQL in strict mode, writing
     * 'in_review' into ENUM('pending','in_progress','completed','cancelled')
     * fails with a truncation error (SQLSTATE 01000/1265) that bubbles up as
     * a 500 whenever a developer moves a todo to review.
     *
     * The SQLite test schema never caught this: the CHECK constraint that
     * enum() produced was silently dropped when 2025_12_31_133355 added the
     * constrained assignee_id column (SQLite table rebuild), so the tests'
     * status column is already a plain varchar.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE todos MODIFY COLUMN status ENUM('pending', 'in_progress', 'in_review', 'completed', 'cancelled') NOT NULL DEFAULT 'pending'");

            return;
        }

        // On SQLite the original enum() produced a varchar with a CHECK
        // constraint that rejects 'in_review'. Rebuilding the column as a
        // plain string drops that constraint (a no-op on schemas where a
        // later table rebuild already dropped it).
        Schema::table('todos', function (Blueprint $table) {
            $table->string('status')->default('pending')->change();
        });
    }

    public function down(): void
    {
        // Reset any 'in_review' rows to 'in_progress' before shrinking the enum
        DB::statement("UPDATE todos SET status = 'in_progress' WHERE status = 'in_review'");

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE todos MODIFY COLUMN status ENUM('pending', 'in_progress', 'completed', 'cancelled') NOT NULL DEFAULT 'pending'");
        }
    }
};
