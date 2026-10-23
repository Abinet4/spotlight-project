<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VAT. A store registered for VAT charges it inside its prices; each order keeps the rate and the
 * store's VAT number from the moment it was placed, so a later change never rewrites old receipts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_profiles', function (Blueprint $table) {
            $table->boolean('vat_registered')->default(false)->after('tin_number');
            $table->string('vat_number', 32)->nullable()->after('vat_registered');
        });
        Schema::table('orders', function (Blueprint $table) {
            // 1500 = 15%. 0 for a store not registered for VAT.
            $table->unsignedSmallInteger('vat_rate_bp')->default(0)->after('commission_santim');
            $table->string('vat_number', 32)->nullable()->after('vat_rate_bp');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['vat_rate_bp', 'vat_number']);
        });
        Schema::table('seller_profiles', function (Blueprint $table) {
            $table->dropColumn(['vat_registered', 'vat_number']);
        });
    }
};
