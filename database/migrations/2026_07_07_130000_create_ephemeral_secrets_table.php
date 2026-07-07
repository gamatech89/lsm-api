<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ephemeral_secrets', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title')->nullable();
            $table->text('payload')->nullable();          // encrypted JSON, nulled on burn
            $table->string('access_password')->nullable(); // bcrypt hash
            $table->timestamp('expires_at');
            $table->timestamp('viewed_at')->nullable();
            $table->string('last_viewed_ip', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ephemeral_secrets');
    }
};
