<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Groups of areas sharing a default delivery fee and time estimate. Admin-managed.
        Schema::create('delivery_zones', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            // Default fee used when a listing does not set its own delivery fee.
            $table->unsignedBigInteger('fee_santim');
            $table->unsignedSmallInteger('min_days')->default(1);
            $table->unsignedSmallInteger('max_days')->default(3);
            $table->string('notes', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('areas', function (Blueprint $table) {
            // Null means no marketplace delivery estimate for this area (pickup or seller quote only).
            $table->foreignId('delivery_zone_id')->nullable()->after('city_id')->constrained()->nullOnDelete();
        });

        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label', 40)->nullable();
            $table->string('recipient_name', 100);
            $table->string('phone', 32);
            $table->foreignId('area_id')->constrained()->restrictOnDelete();
            $table->string('address_line', 255);
            $table->string('landmark', 255)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addresses');
        Schema::table('areas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delivery_zone_id');
        });
        Schema::dropIfExists('delivery_zones');
    }
};
