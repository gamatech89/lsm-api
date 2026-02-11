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
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value')->nullable();
            $table->string('group')->default('general')->index();
            $table->timestamps();
        });

        // Add uptime monitoring toggle to projects
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('uptime_monitoring_enabled')->default(true)->after('health_check_secret');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
        
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('uptime_monitoring_enabled');
        });
    }
};
