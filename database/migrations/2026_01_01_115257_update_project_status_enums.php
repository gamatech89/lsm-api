<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This migration is skipped - the original enum values are kept.
     * The application code handles both old and new naming conventions.
     */
    public function up(): void
    {
        // SQLite doesn't support altering enums easily.
        // The app already supports both 'down_error'/'offline' and 'updating'/'maintenance'.
        // Skipping this migration to avoid constraint issues.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Nothing to revert
    }
};
