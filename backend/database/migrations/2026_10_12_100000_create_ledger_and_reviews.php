<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only money ledger between the platform and each seller.
        // Positive amounts increase what the seller owes the platform; negative amounts reduce it.
        Schema::create('seller_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_profile_id')->constrained()->restrictOnDelete();
            $table->string('type', 20); // commission | payment | adjustment
            $table->bigInteger('amount_santim');
            $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('method', 20)->nullable(); // payments only
            $table->string('reference', 100)->nullable(); // payments only, e.g. bank transfer number
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            // One commission charge per order, and a bank or mobile-money reference is recorded once.
            $table->unique(['order_id', 'type']);
            $table->unique(['seller_profile_id', 'reference']);
            $table->index(['seller_profile_id', 'id']);
        });

        // One review per delivered order line, written by that order's customer.
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('seller_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('users')->restrictOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('body')->nullable();
            $table->text('seller_reply')->nullable();
            $table->timestamp('seller_replied_at')->nullable();
            $table->boolean('is_hidden')->default(false);
            $table->string('hidden_reason', 500)->nullable();
            $table->timestamps();

            // One review per customer per listing, however many times they bought it. Quote items have no
            // listing (null), so they fall back to one per order line.
            $table->unique(['customer_id', 'product_id']);
            $table->index(['product_id', 'is_hidden', 'id']);
            $table->index(['seller_profile_id', 'is_hidden', 'id']);
        });

        // Rating totals, recomputed from visible reviews whenever one changes (never incremented blindly).
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('rating_count')->default(0);
            $table->unsignedInteger('rating_sum')->default(0);
        });
        Schema::table('seller_profiles', function (Blueprint $table) {
            $table->unsignedInteger('rating_count')->default(0);
            $table->unsignedInteger('rating_sum')->default(0);
        });

        // Commission on cash-on-delivery orders delivered before the ledger existed.
        DB::table('orders')
            ->where('status', 'delivered')->where('payment_method', 'cod')->where('commission_santim', '>', 0)
            ->orderBy('id')
            ->each(function ($order) {
                DB::table('seller_ledger_entries')->insertOrIgnore([
                    'seller_profile_id' => $order->seller_profile_id,
                    'type' => 'commission',
                    'amount_santim' => $order->commission_santim,
                    'order_id' => $order->id,
                    'created_at' => $order->delivered_at ?? now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('seller_profiles', fn (Blueprint $table) => $table->dropColumn(['rating_count', 'rating_sum']));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['rating_count', 'rating_sum']));
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('seller_ledger_entries');
    }
};
