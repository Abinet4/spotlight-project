<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Admin-editable business settings (commission rate, payment methods). Defaults live in config.
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->text('value');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // A customer's cart line: either a listing or an accepted quote (then product_id is null).
        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('quote_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->timestamps();

            $table->unique(['user_id', 'product_id']);
            $table->unique(['user_id', 'quote_id']);
        });

        // One customer payment covering one order per seller. The address is a snapshot.
        Schema::create('checkouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 64);
            $table->string('payment_method', 20);
            $table->string('recipient_name', 100);
            $table->string('phone', 32);
            $table->foreignId('area_id')->constrained()->restrictOnDelete();
            $table->string('area_name', 100);
            $table->string('address_line', 255);
            $table->string('landmark', 255)->nullable();
            $table->unsignedBigInteger('items_total_santim');
            $table->unsignedBigInteger('delivery_total_santim');
            $table->unsignedBigInteger('grand_total_santim');
            $table->timestamps();

            $table->unique(['customer_id', 'idempotency_key']);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->nullable()->unique();
            $table->foreignId('checkout_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('seller_profile_id')->constrained()->restrictOnDelete();
            // pending_confirmation | confirmed | processing | ready | dispatched | delivered | cancelled
            $table->string('status', 24)->default('pending_confirmation');
            // delivery | pickup
            $table->string('fulfillment', 10);
            $table->unsignedBigInteger('items_total_santim');
            // Null until the seller confirms the fee (no listing or zone fee applied).
            $table->unsignedBigInteger('delivery_fee_santim')->nullable();
            $table->unsignedBigInteger('total_santim');
            // Commission is charged on items only, at the rate in force when the order was placed.
            $table->unsignedInteger('commission_rate_bp');
            $table->unsignedBigInteger('commission_santim');
            $table->string('payment_method', 20);
            // unpaid | paid
            $table->string('payment_status', 10)->default('unpaid');
            $table->timestamp('paid_at')->nullable();
            $table->string('customer_note', 500)->nullable();
            $table->string('seller_note', 500)->nullable();
            $table->string('cancel_reason', 500)->nullable();
            // customer | seller | admin
            $table->string('cancelled_by', 10)->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['seller_profile_id', 'status']);
            $table->index(['customer_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        // Line snapshot: name and price are frozen at purchase time.
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('quote_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 160);
            $table->unsignedBigInteger('unit_price_santim');
            $table->unsignedSmallInteger('quantity');
            $table->unsignedBigInteger('line_total_santim');
            $table->string('stock_type', 20)->nullable();
            $table->unsignedSmallInteger('production_days')->nullable();
            // Stock taken from the listing at purchase; returned if the order is cancelled.
            $table->unsignedSmallInteger('reserved_quantity')->default(0);
            $table->timestamps();

            $table->index('quote_id');
        });

        Schema::create('order_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_role', 10);
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['order_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_history');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('checkouts');
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('settings');
    }
};
