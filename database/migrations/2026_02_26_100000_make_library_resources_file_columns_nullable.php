<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('library_resources', function (Blueprint $table) {
            $table->string('file_name')->nullable()->default(null)->change();
            $table->string('file_path')->nullable()->default(null)->change();
            $table->unsignedBigInteger('file_size')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('library_resources', function (Blueprint $table) {
            $table->string('file_name')->nullable(false)->change();
            $table->string('file_path')->nullable(false)->change();
            $table->unsignedBigInteger('file_size')->nullable(false)->change();
        });
    }
};
