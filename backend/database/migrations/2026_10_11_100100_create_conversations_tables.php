<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A thread between one customer and one seller, optionally about one listing.
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('seller_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            // question | availability | negotiation | custom_request
            $table->string('type', 20);
            $table->string('subject', 160);
            // open | closed
            $table->string('status', 10)->default('open');
            $table->timestamp('last_message_at')->nullable();
            // Read state is tracked by message id, not time, so two messages in the same second are never missed.
            $table->unsignedBigInteger('last_message_id')->nullable();
            $table->unsignedBigInteger('customer_last_read_id')->nullable();
            $table->unsignedBigInteger('seller_last_read_id')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'last_message_at']);
            $table->index(['seller_profile_id', 'last_message_at']);
            $table->index(['customer_id', 'seller_profile_id', 'product_id', 'status'], 'conversations_lookup_index');
        });

        // A seller's priced offer inside a conversation (custom work or negotiated price).
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seller_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 160);
            $table->text('description');
            $table->unsignedBigInteger('price_santim');
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->unsignedBigInteger('delivery_fee_santim')->nullable();
            $table->unsignedSmallInteger('production_days')->nullable();
            $table->date('valid_until');
            // pending | accepted | declined | withdrawn (expired is derived from valid_until)
            $table->string('status', 12)->default('pending');
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'status']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->restrictOnDelete();
            $table->text('body');
            // Set when the message announces a quote or a response to one, so the UI can show the quote inline.
            $table->foreignId('quote_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('quotes');
        Schema::dropIfExists('conversations');
    }
};
