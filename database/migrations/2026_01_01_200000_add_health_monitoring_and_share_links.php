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
        // Add health monitoring fields to projects
        Schema::table('projects', function (Blueprint $table) {
            $table->integer('response_time_ms')->nullable()->after('security_status');
            $table->timestamp('last_health_check_at')->nullable()->after('response_time_ms');
            $table->string('ssl_status')->nullable()->after('last_health_check_at'); // valid, expiring_soon, expired, none
            $table->date('ssl_expires_at')->nullable()->after('ssl_status');
            $table->string('wp_version')->nullable()->after('ssl_expires_at');
            $table->string('php_version')->nullable()->after('wp_version');
            $table->integer('outdated_plugins_count')->default(0)->after('php_version');
            $table->string('health_check_secret')->nullable()->after('outdated_plugins_count');
            $table->json('last_health_details')->nullable()->after('health_check_secret');
        });

        // Create credential share links table
        Schema::create('credential_share_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credential_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            
            $table->string('token', 64)->unique(); // Secure random token
            $table->string('access_password')->nullable(); // Optional additional password
            
            $table->timestamp('expires_at');
            $table->integer('max_views')->default(1); // One-time view by default
            $table->integer('view_count')->default(0);
            
            $table->boolean('show_username')->default(true);
            $table->boolean('show_password')->default(true);
            $table->boolean('show_url')->default(true);
            
            $table->string('recipient_email')->nullable(); // Optional: who it's for
            $table->text('note')->nullable(); // Optional message
            
            $table->timestamp('last_viewed_at')->nullable();
            $table->string('last_viewed_ip')->nullable();
            
            $table->timestamps();
            
            $table->index(['token', 'expires_at']);
            $table->index('expires_at');
        });

        // Track share link access logs for audit
        Schema::create('credential_share_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('share_link_id')->constrained('credential_share_links')->cascadeOnDelete();
            
            $table->string('ip_address');
            $table->string('user_agent')->nullable();
            $table->string('country')->nullable(); // From IP geolocation
            $table->boolean('password_correct')->default(true);
            
            $table->timestamp('accessed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('credential_share_access_logs');
        Schema::dropIfExists('credential_share_links');
        
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn([
                'response_time_ms',
                'last_health_check_at',
                'ssl_status',
                'ssl_expires_at',
                'wp_version',
                'php_version',
                'outdated_plugins_count',
                'health_check_secret',
                'last_health_details',
            ]);
        });
    }
};
