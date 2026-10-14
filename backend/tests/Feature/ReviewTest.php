<?php

namespace Tests\Feature;

use App\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PlacesOrders;
use Tests\TestCase;

class ReviewTest extends TestCase
{
    use PlacesOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrders();
        $this->buyer->forceFill(['name' => 'Abebe Kebede Tesfaye'])->save();
    }

    private function reviewUrl($order): string
    {
        return "/api/orders/{$order->id}/items/{$order->items->first()->id}/review";
    }

    public function test_only_delivered_items_can_be_reviewed_once(): void
    {
        $order = $this->placeOrder(1);
        $this->as($this->buyer)->postJson($this->reviewUrl($order), ['rating' => 5])->assertUnprocessable()->assertJsonValidationErrors('rating');

        $order = $this->deliver($order);
        $this->as($this->buyer);
        $this->postJson($this->reviewUrl($order), ['rating' => 6])->assertUnprocessable();
        $this->postJson($this->reviewUrl($order), ['rating' => 0])->assertUnprocessable();
        $this->postJson($this->reviewUrl($order), ['rating' => 'five'])->assertUnprocessable();
        $this->postJson($this->reviewUrl($order), ['rating' => 4, 'body' => '  Solid frame, arrived on time.  '])->assertCreated()
            ->assertJsonPath('data.rating', 4)
            ->assertJsonPath('data.body', 'Solid frame, arrived on time.')
            ->assertJsonPath('data.reviewer', 'Abebe T.');
        $this->postJson($this->reviewUrl($order), ['rating' => 1])->assertUnprocessable()->assertJsonValidationErrors('rating');

        $this->getJson("/api/orders/{$order->id}")->assertJsonPath('data.items.0.review.rating', 4)
            ->assertJsonPath('data.review_until', fn ($v) => str_starts_with($v, $order->delivered_at->copy()->addDays(60)->toDateString()));
        $this->assertSame([1, 4], [$this->sofa->fresh()->rating_count, $this->sofa->fresh()->rating_sum]);
        $this->assertSame([1, 4], [$this->seller->fresh()->rating_count, $this->seller->fresh()->rating_sum]);
    }

    public function test_cancelled_orders_and_other_customers_cannot_review(): void
    {
        $order = $this->placeOrder(1);
        $this->as($this->buyer)->postJson("/api/orders/{$order->id}/cancel", ['reason' => 'Too slow'])->assertOk();
        $this->postJson($this->reviewUrl($order->fresh('items')), ['rating' => 1])->assertUnprocessable();

        $delivered = $this->deliver($this->placeOrder(1));
        $stranger = $this->customer();
        $this->as($stranger)->postJson($this->reviewUrl($delivered), ['rating' => 1])->assertNotFound();
        // An item id from another order is not reachable through this order.
        $this->as($this->buyer)->postJson("/api/orders/{$delivered->id}/items/{$order->items->first()->id}/review", ['rating' => 1])->assertNotFound();
        $this->as($this->seller->user)->postJson($this->reviewUrl($delivered), ['rating' => 5])->assertForbidden();
    }

    public function test_review_window_closes_after_sixty_days(): void
    {
        $order = $this->deliver($this->placeOrder(1));
        $this->as($this->buyer)->postJson($this->reviewUrl($order), ['rating' => 3])->assertCreated();
        $id = Review::first()->id;

        $this->travel(59)->days();
        $this->putJson("/api/reviews/$id", ['rating' => 2, 'body' => 'Legs got loose'])->assertOk()->assertJsonPath('data.rating', 2);
        $this->assertSame(2, $this->sofa->fresh()->rating_sum);

        $this->getJson("/api/orders/{$order->id}")->assertJsonPath('data.reviews_open', true);
        $this->travel(2)->days();
        $this->getJson("/api/orders/{$order->id}")->assertJsonPath('data.reviews_open', false);
        $this->putJson("/api/reviews/$id", ['rating' => 1])->assertUnprocessable();
        $this->as($this->customer())->putJson("/api/reviews/$id", ['rating' => 5])->assertNotFound();
    }

    public function test_public_listing_summary_and_privacy(): void
    {
        $order = $this->deliver($this->placeOrder(1));
        $this->as($this->buyer)->postJson($this->reviewUrl($order), ['rating' => 5, 'body' => 'Great'])->assertCreated();
        $second = $this->customer();
        $this->deliver($o2 = $this->placeOrder(1, $second));
        $this->as($second)->postJson($this->reviewUrl($o2), ['rating' => 2])->assertCreated();

        $this->app['auth']->forgetGuards();
        $res = $this->getJson("/api/products/{$this->sofa->slug}/reviews")->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('summary.count', 2)
            ->assertJsonPath('summary.average', 3.5)
            ->assertJsonPath('summary.breakdown.5', 1)
            ->assertJsonPath('summary.breakdown.2', 1)
            ->assertJsonPath('summary.breakdown.3', 0);
        $json = json_encode($res->json());
        $this->assertStringNotContainsString($this->buyer->email, $json);
        $this->assertStringNotContainsString('Kebede', $json);
        $this->assertArrayNotHasKey('is_hidden', $res->json('data.0'));

        $this->getJson("/api/sellers/{$this->seller->slug}/reviews")->assertOk()->assertJsonPath('summary.count', 2)->assertJsonPath('data.0.product.slug', $this->sofa->slug);
        $this->getJson("/api/products/{$this->sofa->slug}")->assertJsonPath('data.rating.average', 3.5)->assertJsonPath('data.rating.count', 2);
    }

    public function test_seller_replies_and_admin_hides(): void
    {
        $order = $this->deliver($this->placeOrder(1));
        $this->as($this->buyer)->postJson($this->reviewUrl($order), ['rating' => 1, 'body' => 'Call me on 0911 for a refund scam'])->assertCreated();
        $id = Review::first()->id;

        $this->as($this->seller->user)->getJson('/api/seller/reviews?unanswered=1')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/seller/reviews/$id/reply", ['reply' => 'Sorry, we have fixed the legs. Please message us.'])->assertOk()
            ->assertJsonPath('data.seller_reply', 'Sorry, we have fixed the legs. Please message us.');
        $this->getJson('/api/seller/reviews?unanswered=1')->assertJsonCount(0, 'data');
        $this->as($this->approvedSeller()->user)->postJson("/api/seller/reviews/$id/reply", ['reply' => 'Not mine'])->assertNotFound();

        $admin = $this->admin();
        $this->as($admin)->postJson("/api/admin/reviews/$id/hide")->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/admin/reviews/$id/hide", ['reason' => 'Contains a phone number'])->assertOk()
            ->assertJsonPath('data.is_hidden', true)
            ->assertJsonPath('data.customer_email', $this->buyer->email);
        $this->assertSame([0, 0], [$this->sofa->fresh()->rating_count, $this->seller->fresh()->rating_count]);
        $this->getJson('/api/admin/reviews?hidden=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.order_number', $order->number);

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/products/{$this->sofa->slug}/reviews")->assertJsonCount(0, 'data')->assertJsonPath('summary.average', null);
        $this->as($this->seller->user)->getJson('/api/seller/reviews')->assertJsonCount(0, 'data');
        // The author still sees their review, marked hidden.
        $this->as($this->buyer)->getJson("/api/orders/{$order->id}")->assertJsonPath('data.items.0.review.is_hidden', true);

        $this->as($admin)->postJson("/api/admin/reviews/$id/restore")->assertOk()->assertJsonPath('data.is_hidden', false);
        $this->assertSame([1, 1], [$this->sofa->fresh()->rating_count, $this->sofa->fresh()->rating_sum]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'review.hidden']);
        $this->as($this->buyer)->getJson('/api/admin/reviews')->assertForbidden();
    }

    public function test_reviews_of_hidden_listings_are_not_public(): void
    {
        $order = $this->deliver($this->placeOrder(1));
        $this->as($this->buyer)->postJson($this->reviewUrl($order), ['rating' => 5])->assertCreated();
        $this->sofa->forceFill(['status' => 'archived'])->save();
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/products/{$this->sofa->slug}/reviews")->assertNotFound();
    }

    public function test_one_review_per_listing_per_customer(): void
    {
        $first = $this->deliver($this->placeOrder(1));
        $second = $this->deliver($this->placeOrder(1));
        $this->as($this->buyer)->postJson($this->reviewUrl($first), ['rating' => 5])->assertCreated();
        $this->postJson($this->reviewUrl($second), ['rating' => 5])->assertUnprocessable()->assertJsonValidationErrors('rating');
        $this->assertSame(1, $this->sofa->fresh()->rating_count);
    }
}
