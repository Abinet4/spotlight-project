<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->string('name', 100);
            $table->string('slug', 120)->unique();
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('name', 160);
            $table->string('slug', 200)->unique();
            $table->text('description');

            // Money is stored as integer santim (1 ETB = 100 santim) to avoid float rounding.
            $table->unsignedBigInteger('price_santim');
            $table->boolean('price_negotiable')->default(false);

            // new | used | refurbished
            $table->string('condition', 20)->default('new');
            // in_stock | made_to_order
            $table->string('stock_type', 20)->default('in_stock');
            $table->unsignedInteger('stock_quantity')->default(0);
            // Estimated production time for made-to-order items.
            $table->unsignedSmallInteger('production_days')->nullable();
            $table->boolean('custom_order_available')->default(false);

            $table->unsignedSmallInteger('width_cm')->nullable();
            $table->unsignedSmallInteger('depth_cm')->nullable();
            $table->unsignedSmallInteger('height_cm')->nullable();
            $table->string('material', 100)->nullable()->index();
            $table->string('color', 60)->nullable()->index();

            $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('offers_delivery')->default(true);
            $table->boolean('pickup_available')->default(false);
            // Null means the seller quotes delivery after confirming the address.
            $table->unsignedBigInteger('delivery_fee_santim')->nullable();
            $table->string('delivery_notes', 500)->nullable();

            // draft | pending_review | published | rejected | archived
            $table->string('status', 20)->default('pending_review');
            $table->string('moderation_note', 500)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'category_id']);
            $table->index(['status', 'price_santim']);
            $table->index(['status', 'published_at']);
            $table->index(['seller_profile_id', 'status']);
        });

        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('alt', 160)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['product_id', 'sort_order']);
        });

        Schema::create('favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('favorites');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
    }
};
