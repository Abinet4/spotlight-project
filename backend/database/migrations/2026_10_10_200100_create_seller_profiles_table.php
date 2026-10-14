<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('store_name', 120);
            $table->string('slug', 140)->unique();
            $table->text('description')->nullable();
            // shop | manufacturer | workshop | individual
            $table->string('business_type', 20);
            $table->string('phone', 32);
            $table->string('tin_number', 32)->nullable();
            $table->foreignId('city_id')->constrained()->restrictOnDelete();
            $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();
            $table->string('address_line', 255)->nullable();
            $table->boolean('offers_custom_orders')->default(false);
            $table->boolean('offers_delivery')->default(true);
            $table->string('logo_path')->nullable();
            // pending | approved | rejected | suspended
            $table->string('status', 20)->default('pending')->index();
            $table->string('status_reason', 500)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_profiles');
    }
};
