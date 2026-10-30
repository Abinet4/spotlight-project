<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prices stay fixed, with two ways to pay less:
 * - a store puts a listing on sale: a percent off, until an end date or until it is taken off;
 * - Betoch makes coupon codes customers enter at checkout. Betoch pays the coupon, not the store:
 *   the store is credited the amount when the order is delivered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedTinyInteger('discount_percent')->nullable()->after('price_santim');
            $table->timestamp('discount_ends_at')->nullable()->after('discount_percent');
        });

        Schema::table('order_items', function (Blueprint $table) {
            // The price before the store's sale, kept only when the item was bought on sale.
            $table->unsignedBigInteger('list_unit_price_santim')->nullable()->after('unit_price_santim');
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('description', 160)->nullable();
            $table->unsignedTinyInteger('percent_off')->nullable();
            $table->unsignedBigInteger('amount_off_santim')->nullable();
            // Percent coupons: the most one checkout can take off.
            $table->unsignedBigInteger('max_discount_santim')->nullable();
            $table->unsignedBigInteger('min_items_santim')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('max_uses_per_customer')->nullable()->default(1);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('checkouts', function (Blueprint $table) {
            $table->foreignId('coupon_id')->nullable()->after('grand_total_santim')->constrained()->restrictOnDelete();
            $table->string('coupon_code', 32)->nullable()->after('coupon_id');
            $table->unsignedBigInteger('coupon_discount_santim')->default(0)->after('coupon_code');
        });

        Schema::table('orders', function (Blueprint $table) {
            // This store's share of the checkout's coupon: taken off the total, paid to the store by Betoch.
            $table->unsignedBigInteger('coupon_discount_santim')->default(0)->after('vat_santim');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('coupon_discount_santim');
        });
        Schema::table('checkouts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('coupon_id');
            $table->dropColumn(['coupon_code', 'coupon_discount_santim']);
        });
        Schema::dropIfExists('coupons');
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('list_unit_price_santim');
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['discount_percent', 'discount_ends_at']);
        });
    }
};
