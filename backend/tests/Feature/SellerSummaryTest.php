<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PlacesOrders;
use Tests\TestCase;

class SellerSummaryTest extends TestCase
{
    use PlacesOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrders();
    }

    /** One review per customer per listing, so each review comes from a new customer. */
    private function review(int $rating): Review
    {
        $buyer = $this->customer();
        $order = $this->deliver($this->placeOrder(1, $buyer));
        $this->as($buyer)->postJson("/api/orders/{$order->id}/items/{$order->items->first()->id}/review", ['rating' => $rating])->assertCreated();

        return Review::latest('id')->first();
    }

    public function test_counts_only_this_stores_orders_listings_and_reviews(): void
    {
        $waiting = $this->placeOrder(1);
        $this->placeOrder(1);
        $this->deliver($this->placeOrder(1));
        // An online order that was never paid stays out of the store's sight and its counts.
        $this->placeOrder(1)->forceFill(['payment_method' => 'online', 'paid_at' => null])->save();
        Product::factory()->create(['seller_profile_id' => $this->seller->id, 'category_id' => $this->category()->id, 'status' => 'rejected']);

        // Another store's orders and listings never count.
        $other = $this->approvedSeller();
        $otherSofa = Product::factory()->published()->create(['seller_profile_id' => $other->id, 'category_id' => $this->category()->id, 'price_santim' => 500_000, 'stock_quantity' => 5]);
        $this->placeOrder(1, null, $otherSofa);

        $this->as($this->seller->user)->getJson('/api/seller/summary')->assertOk()
            ->assertJsonPath('data.store.slug', $this->seller->slug)
            ->assertJsonPath('data.store.status', 'approved')
            ->assertJsonPath('data.orders.pending_confirmation', 2)
            ->assertJsonPath('data.orders.delivered', 1)
            ->assertJsonPath('data.products.published', 1)
            ->assertJsonPath('data.products.rejected', 1)
            ->assertJsonPath('data.delivered_sales_santim', 1_030_000)
            ->assertJsonPath('data.balance.balance_santim', (int) $this->seller->fresh()->ledgerEntries()->sum('amount_santim'))
            ->assertJsonPath('data.reviews.count', 0)
            ->assertJsonPath('data.reviews.average', null);

        $this->as($other->user)->getJson('/api/seller/summary')->assertOk()
            ->assertJsonPath('data.orders.pending_confirmation', 1)
            ->assertJsonMissingPath('data.orders.delivered');
        $this->assertSame('pending_confirmation', $waiting->fresh()->status->value);
    }

    public function test_low_ratings_without_a_reply_from_the_last_thirty_days(): void
    {
        $this->review(5);
        $low = $this->review(1);
        $replied = $this->review(2);
        $old = $this->review(2);
        $hidden = $this->review(1);
        $replied->forceFill(['seller_reply' => 'Sorry, we fixed the legs.'])->save();
        $old->forceFill(['created_at' => now()->subDays(31)])->save();
        $hidden->forceFill(['is_hidden' => true])->save();

        $this->as($this->seller->user)->getJson('/api/seller/summary')->assertOk()
            ->assertJsonPath('data.reviews.unanswered', 3)
            ->assertJsonPath('data.reviews.low_unanswered', 1)
            ->assertJsonPath('data.reviews.count', 5);

        $this->postJson("/api/seller/reviews/{$low->id}/reply", ['reply' => 'We are sorry, call us.'])->assertOk();
        $this->getJson('/api/seller/summary')->assertJsonPath('data.reviews.low_unanswered', 0)
            ->assertJsonPath('data.reviews.unanswered', 2);
    }

    public function test_pending_stores_can_read_it_and_others_cannot(): void
    {
        $pending = $this->pendingSeller();
        $this->as($pending->user)->getJson('/api/seller/summary')->assertOk()
            ->assertJsonPath('data.store.status', 'pending')
            ->assertJsonPath('data.orders', [])
            ->assertJsonPath('data.reviews.low_unanswered', 0);

        $this->as($this->buyer)->getJson('/api/seller/summary')->assertForbidden();
        $this->as($this->admin())->getJson('/api/seller/summary')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/seller/summary')->assertUnauthorized();
    }
}
