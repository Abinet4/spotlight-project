<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * VAT moves from inside a VAT-registered store's prices to a line added at checkout on every order.
 * Each order now keeps its VAT amount. Orders placed the old way keep their totals; their VAT amount
 * is the part that was inside the total.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('vat_santim')->default(0)->after('vat_rate_bp');
        });

        DB::table('orders')->where('vat_rate_bp', '>', 0)->orderBy('id')->select(['id', 'total_santim', 'vat_rate_bp'])
            ->chunkById(500, function ($orders) {
                foreach ($orders as $o) {
                    $base = 10000 + (int) $o->vat_rate_bp;
                    $total = (int) $o->total_santim;
                    $vat = intdiv($total, $base) * $o->vat_rate_bp + intdiv(($total % $base) * $o->vat_rate_bp + intdiv($base, 2), $base);
                    DB::table('orders')->where('id', $o->id)->update(['vat_santim' => $vat]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('vat_santim');
        });
    }
};
