<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\SellerProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Suggestions shown under the search box while the customer types. */
class SearchSuggestTest extends TestCase
{
    use RefreshDatabase;

    private SellerProfile $seller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        $this->seller = $this->approvedSeller();
    }

    private function published(array $attrs = [], ?SellerProfile $seller = null): Product
    {
        return Product::factory()->published()->create(array_merge([
            'seller_profile_id' => ($seller ?? $this->seller)->id,
            'category_id' => $this->category()->id,
            'description' => 'Plain', 'material' => null, 'color' => null, 'stock_quantity' => 3,
        ], $attrs));
    }

    private function names(string $q): array
    {
        return collect($this->getJson('/api/search/suggest?q='.urlencode($q))->assertOk()->json('data'))->pluck('name')->all();
    }

    public function test_names_starting_with_the_text_come_first_then_word_starts_then_other_matches(): void
    {
        $this->published(['name' => 'Corner Sofa Bed']);
        $this->published(['name' => 'Sofa Table']);
        $this->published(['name' => 'Bigsofaset Lounge']);
        $this->published(['name' => 'Armchair', 'description' => 'Matches the sofa set']);
        $this->published(['name' => 'Dining Table']);

        $this->assertSame(['Sofa Table', 'Corner Sofa Bed', 'Bigsofaset Lounge', 'Armchair'], $this->names('sofa'));
        $this->assertSame(['Sofa Table', 'Corner Sofa Bed', 'Bigsofaset Lounge', 'Armchair'], $this->names('  SOFA '));
        // Every word must match, like the full search.
        $this->assertSame(['Corner Sofa Bed'], $this->names('sofa bed'));
    }

    public function test_shape_limit_and_in_stock_first(): void
    {
        $sold = $this->published(['name' => 'Chair Sold Out', 'stock_quantity' => 0]);
        foreach (range(1, 7) as $i) {
            $this->published(['name' => "Chair {$i}", 'price_santim' => 100_000 * $i]);
        }
        $res = $this->getJson('/api/search/suggest?q=chair')->assertOk()->assertJsonCount(6, 'data');
        $this->assertNotContains($sold->name, collect($res->json('data'))->pluck('name'));
        $this->assertSame(['slug', 'name', 'price_santim', 'image'], array_keys($res->json('data.0')));
        $this->assertSame('Chair 7', $res->json('data.0.name'));
        $this->assertSame(700_000, $res->json('data.0.price_santim'));
    }

    public function test_short_odd_or_missing_text_and_hidden_listings(): void
    {
        $this->published(['name' => 'Visible Shelf']);
        $this->published(['name' => 'Hidden Shelf'], SellerProfile::factory()->suspended()->create());
        $this->published(['name' => 'Inactive Shelf', 'category_id' => Category::factory()->create(['is_active' => false])->id]);
        Product::factory()->create(['seller_profile_id' => $this->seller->id, 'category_id' => $this->category()->id, 'status' => 'draft', 'name' => 'Draft Shelf']);
        $this->published(['name' => '100% Oak_Shelf']);

        $this->assertSame(['Visible Shelf'], array_values(array_filter($this->names('shelf'), fn ($n) => ! str_contains($n, 'Oak'))));
        $this->assertSame([], $this->names('s'));
        $this->assertSame([], $this->names('zzzz'));
        // LIKE wildcards are literal.
        $this->assertSame(['100% Oak_Shelf'], $this->names('0%'));
        $this->assertSame(['100% Oak_Shelf'], $this->names('k_s'));
        $this->assertSame([], $this->names('o_k%x'));
        $this->getJson('/api/search/suggest')->assertUnprocessable();
        $this->getJson('/api/search/suggest?q='.str_repeat('a', 101))->assertUnprocessable();
    }
}
