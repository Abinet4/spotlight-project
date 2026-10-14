<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Map pins for stores and delivery addresses, so delivery can be priced by distance; the floor
 * and lift of an address, for the carrying fee; and its door, stair and lift widths, for the
 * "will it fit" check. All new columns are nullable: existing rows keep working as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['addresses', 'seller_profiles', 'checkouts'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->decimal('latitude', 9, 6)->nullable();
                $t->decimal('longitude', 9, 6)->nullable();
            });
        }
        Schema::table('addresses', function (Blueprint $t) {
            $t->unsignedTinyInteger('floor')->nullable();
            $t->boolean('has_lift')->nullable();
            $t->unsignedSmallInteger('door_width_cm')->nullable();
            $t->unsignedSmallInteger('stair_width_cm')->nullable();
            $t->unsignedSmallInteger('lift_door_width_cm')->nullable();
        });
        Schema::table('checkouts', function (Blueprint $t) {
            $t->unsignedTinyInteger('floor')->nullable();
            $t->boolean('has_lift')->nullable();
        });
        Schema::table('orders', function (Blueprint $t) {
            // Distance the delivery fee was worked out from, and whether it was a road distance.
            $t->unsignedInteger('delivery_distance_m')->nullable()->after('delivery_fee_santim');
            $t->boolean('delivery_distance_estimated')->nullable()->after('delivery_distance_m');
            // Part of the delivery fee for carrying up floors without a lift.
            $t->unsignedBigInteger('carry_fee_santim')->nullable()->after('delivery_distance_estimated');
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['delivery_distance_m', 'delivery_distance_estimated', 'carry_fee_santim']));
        Schema::table('checkouts', fn (Blueprint $t) => $t->dropColumn(['floor', 'has_lift']));
        Schema::table('addresses', fn (Blueprint $t) => $t->dropColumn(['floor', 'has_lift', 'door_width_cm', 'stair_width_cm', 'lift_door_width_cm']));
        foreach (['addresses', 'seller_profiles', 'checkouts'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['latitude', 'longitude']));
        }
    }
};
