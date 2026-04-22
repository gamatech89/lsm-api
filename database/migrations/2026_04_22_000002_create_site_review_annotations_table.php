<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_review_annotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained('site_reviews')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('site_review_annotations')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('todo_id')->nullable()->constrained('todos')->nullOnDelete();
            // Pin position (null on replies)
            $table->decimal('x_percent', 5, 2)->nullable();
            $table->decimal('y_percent', 5, 2)->nullable();
            $table->unsignedInteger('scroll_y')->nullable();
            $table->text('comment');
            $table->enum('author_type', ['internal', 'client'])->default('internal');
            $table->string('author_name')->nullable();
            $table->string('author_email')->nullable();
            $table->string('screenshot_path')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_review_annotations');
    }
};
