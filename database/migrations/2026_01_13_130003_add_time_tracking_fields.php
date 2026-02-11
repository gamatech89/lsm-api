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
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('hourly_rate', 10, 2)->default(0)->after('role');
            $table->boolean('default_billable')->default(true)->after('hourly_rate');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('is_maintenance')->default(false)->after('security_status');
            $table->integer('estimated_hours')->nullable()->after('is_maintenance');
            $table->integer('tracked_minutes')->default(0)->after('estimated_hours');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['hourly_rate', 'default_billable']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['is_maintenance', 'estimated_hours', 'tracked_minutes']);
        });
    }
};
