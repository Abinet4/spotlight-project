<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Moving an existing marketplace to finished furniture only: made-to-order listings become in stock
 * with no units, custom-order flags go, and the Custom-Made category is hidden with its listings.
 */
class FinishedOnlyMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_24_200000_finished_furniture_only.php';

    public function test_existing_listings_are_moved_and_rollback_restores_the_columns(): void
    {
        $this->seedReferenceData();
        $this->artisan('migrate:rollback', ['--path' => [self::MIGRATION]])->assertSuccessful();
        $this->assertTrue(Schema::hasColumn('products', 'custom_order_available'));

        $seller = $this->approvedSeller();
        DB::table('seller_profiles')->where('id', $seller->id)->update(['offers_custom_orders' => true]);
        $custom = DB::table('categories')->insertGetId(['name' => 'Custom-Made Furniture', 'slug' => 'custom-made', 'is_active' => true, 'sort_order' => 70, 'created_at' => now(), 'updated_at' => now()]);
        $row = fn (array $over) => DB::table('products')->insertGetId($over + [
            'seller_profile_id' => $seller->id, 'category_id' => $this->category()->id, 'name' => 'Old listing', 'slug' => 'old-'.uniqid(),
            'description' => 'From before.', 'price_santim' => 100_000, 'condition' => 'new', 'stock_type' => 'in_stock',
            'stock_quantity' => 5, 'offers_delivery' => true, 'status' => 'published', 'published_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $inStock = $row(['custom_order_available' => true]);
        $madeToOrder = $row(['stock_type' => 'made_to_order', 'stock_quantity' => 0, 'production_days' => 21]);
        $inCustomCategory = $row(['category_id' => $custom]);

        $this->artisan('migrate', ['--path' => self::MIGRATION])->assertSuccessful();

        $this->assertFalse(Schema::hasColumn('products', 'custom_order_available'));
        $this->assertFalse(Schema::hasColumn('seller_profiles', 'offers_custom_orders'));
        $this->assertSame(0, DB::table('products')->where('stock_type', '!=', 'in_stock')->count());
        $this->assertSame(0, DB::table('products')->whereNotNull('production_days')->count());
        $this->assertSame(5, (int) DB::table('products')->where('id', $inStock)->value('stock_quantity'));
        $this->assertSame(0, (int) DB::table('products')->where('id', $madeToOrder)->value('stock_quantity'));
        $this->assertFalse((bool) DB::table('categories')->where('id', $custom)->value('is_active'));

        // The made-to-order listing shows as sold out; the one in the hidden category is not listed at all.
        $listed = collect($this->getJson('/api/products')->assertOk()->json('data'))->keyBy('id');
        $this->assertTrue($listed[$inStock]['is_available']);
        $this->assertFalse($listed[$madeToOrder]['is_available']);
        $this->assertFalse($listed->has($inCustomCategory));

        $this->artisan('migrate:rollback', ['--path' => [self::MIGRATION]])->assertSuccessful();
        $this->assertTrue(Schema::hasColumn('products', 'custom_order_available'));
        $this->assertTrue(Schema::hasColumn('seller_profiles', 'offers_custom_orders'));
        $this->assertTrue((bool) DB::table('categories')->where('id', $custom)->value('is_active'));
    }
}
