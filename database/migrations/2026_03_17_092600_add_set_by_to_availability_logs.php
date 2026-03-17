<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('availability_logs', function (Blueprint $table) {
            $table->foreignId('set_by_user_id')
                ->nullable()
                ->after('user_id')
                ->constrained('users')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('availability_logs', function (Blueprint $table) {
            $table->dropForeign(['set_by_user_id']);
            $table->dropColumn('set_by_user_id');
        });
    }
};
