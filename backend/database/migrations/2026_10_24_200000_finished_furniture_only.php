<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Betoch sells finished furniture only: no custom orders and no made-to-order listings.
 * Made-to-order listings become in stock with 0 units (they kept no stock), so they show out of stock
 * until the store enters how many it has. The Custom-Made category is hidden, which hides its listings
 * until the store moves them to another category. Old orders, quotes and inquiries are left as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('products')->where('stock_type', 'made_to_order')
            ->update(['stock_type' => 'in_stock', 'stock_quantity' => 0, 'updated_at' => now()]);
        DB::table('products')->whereNotNull('production_days')->update(['production_days' => null]);
        DB::table('categories')->where('slug', 'custom-made')->update(['is_active' => false, 'updated_at' => now()]);

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('custom_order_available');
        });
        Schema::table('seller_profiles', function (Blueprint $table) {
            $table->dropColumn('offers_custom_orders');
        });
    }

    /**
     * Brings the columns back (all off) and shows the category again. Which listings were made to order
     * is not recorded, so they stay in stock.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('custom_order_available')->default(false)->after('production_days');
        });
        Schema::table('seller_profiles', function (Blueprint $table) {
            $table->boolean('offers_custom_orders')->default(false)->after('address_line');
        });
        DB::table('categories')->where('slug', 'custom-made')->update(['is_active' => true, 'updated_at' => now()]);
    }
};
