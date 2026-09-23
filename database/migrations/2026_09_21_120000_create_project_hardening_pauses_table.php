<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_hardening_pauses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // dateTime, not timestamp: on MySQL/MariaDB without explicit_defaults_for_timestamp
            // the first NOT NULL timestamp column silently gets ON UPDATE CURRENT_TIMESTAMP,
            // and this row is updated several times after paused_until is set.
            $table->dateTime('paused_until');
            $table->timestamp('resumed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('overdue_notified_at')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['resumed_at', 'paused_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_hardening_pauses');
    }
};
