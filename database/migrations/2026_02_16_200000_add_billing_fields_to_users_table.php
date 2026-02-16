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
        Schema::table('users', function (Blueprint $table) {
            $table->string('billing_company_name')->nullable()->after('hourly_rate');
            $table->text('billing_address')->nullable()->after('billing_company_name');
            $table->string('billing_tax_id')->nullable()->after('billing_address');
            $table->string('invoice_prefix', 10)->nullable()->after('billing_tax_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'billing_company_name',
                'billing_address',
                'billing_tax_id',
                'invoice_prefix',
            ]);
        });
    }
};
