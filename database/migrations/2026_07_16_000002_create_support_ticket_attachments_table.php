<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_ticket_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('support_ticket_message_id')->nullable()
                ->constrained('support_ticket_messages')->cascadeOnDelete();
            $table->string('filename');
            $table->string('path', 500);
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_attachments');
    }
};
