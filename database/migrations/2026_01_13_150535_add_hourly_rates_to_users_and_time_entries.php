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
        // Add hourly_rate to time_entries (override rate for specific entry)
        // users.hourly_rate already exists
        if (!Schema::hasColumn('time_entries', 'hourly_rate')) {
            Schema::table('time_entries', function (Blueprint $table) {
                $table->decimal('hourly_rate', 10, 2)->nullable()->after('is_billable');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('time_entries', 'hourly_rate')) {
            Schema::table('time_entries', function (Blueprint $table) {
                $table->dropColumn('hourly_rate');
            });
        }
    }
};
