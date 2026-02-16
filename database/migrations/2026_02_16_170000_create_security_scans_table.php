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
        Schema::create('security_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('scan_type')->default('full'); // full, quick
            $table->string('status')->default('pending'); // pending, running, completed, failed, partial
            $table->string('risk_level')->nullable(); // clean, low, medium, high, critical
            $table->integer('threats_found')->default(0);
            $table->integer('warnings_found')->default(0);
            $table->integer('files_scanned')->default(0);
            $table->decimal('duration_seconds', 8, 2)->nullable();
            $table->json('results')->nullable(); // Full scan results JSON
            $table->json('summary')->nullable(); // Compact summary
            $table->string('triggered_by')->default('scheduled'); // scheduled, manual
            $table->foreignId('triggered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'completed_at']);
            $table->index('risk_level');
        });

        // Add security scan metadata to projects table
        Schema::table('projects', function (Blueprint $table) {
            $table->timestamp('last_security_scan_at')->nullable()->after('domain_registrar');
            $table->string('last_security_scan_risk')->nullable()->after('last_security_scan_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('security_scans');

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['last_security_scan_at', 'last_security_scan_risk']);
        });
    }
};
