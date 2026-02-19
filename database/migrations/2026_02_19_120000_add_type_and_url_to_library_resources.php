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
        Schema::table('library_resources', function (Blueprint $table) {
            $table->string('type')->default('file')->after('id'); // 'file' or 'link'
            $table->string('url')->nullable()->after('category');  // External URL for link-type resources
            $table->string('file_path')->nullable()->change();     // Make nullable (links don't have files)
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('library_resources', function (Blueprint $table) {
            $table->dropColumn(['type', 'url']);
            $table->string('file_path')->nullable(false)->change();
        });
    }
};
