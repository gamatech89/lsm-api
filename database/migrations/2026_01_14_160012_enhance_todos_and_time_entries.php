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
        Schema::table('time_entries', function (Blueprint $table) {
            $table->foreignId('todo_id')->nullable()->constrained('todos')->nullOnDelete();
        });

        Schema::table('todos', function (Blueprint $table) {
            $table->integer('estimated_minutes')->nullable();
        });

        Schema::table('maintenance_reports', function (Blueprint $table) {
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropForeign(['todo_id']);
            $table->dropColumn('todo_id');
        });

        Schema::table('todos', function (Blueprint $table) {
            $table->dropColumn('estimated_minutes');
        });

        Schema::table('maintenance_reports', function (Blueprint $table) {
            $table->dropForeign(['invoice_id']);
            $table->dropColumn('invoice_id');
        });
    }
};
