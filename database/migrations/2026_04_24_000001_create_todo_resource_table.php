<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('todo_resource', function (Blueprint $table) {
            $table->foreignId('todo_id')->constrained()->cascadeOnDelete();
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['todo_id', 'resource_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('todo_resource');
    }
};
