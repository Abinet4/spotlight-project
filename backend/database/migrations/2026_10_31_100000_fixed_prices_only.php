<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every price on Betoch is the listing's price: no "negotiable" listings and no price offers.
 * Offers still open (pending, or accepted but never ordered) are withdrawn and leave customers' carts.
 * Offers that became orders, and the messages they were sent in, stay as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('cart_items')->whereNotNull('quote_id')->delete();

        $ordered = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNotNull('order_items.quote_id')->where('orders.status', '!=', 'cancelled')
            ->pluck('order_items.quote_id')->unique()->all();
        DB::table('quotes')->where('status', 'pending')
            ->orWhere(fn ($q) => $q->where('status', 'accepted')->whereNotIn('id', $ordered))
            ->update(['status' => 'withdrawn', 'responded_at' => now(), 'updated_at' => now()]);

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('price_negotiable');
        });
    }

    /** Brings the column back (all off). Withdrawn offers stay withdrawn. */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('price_negotiable')->default(false)->after('price_santim');
        });
    }
};
