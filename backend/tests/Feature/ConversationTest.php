<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ConversationTest extends TestCase
{
    use RefreshDatabase;

    private SellerProfile $seller;

    private Product $product;

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        $this->seller = $this->approvedSeller();
        $this->product = Product::factory()->published()->create([
            'seller_profile_id' => $this->seller->id,
            'category_id' => $this->category()->id,
            'price_negotiable' => true,
            'custom_order_available' => false,
        ]);
        $this->buyer = $this->customer();
    }

    private function ask(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/conversations', array_merge([
            'product_slug' => $this->product->slug,
            'type' => 'question',
            'body' => 'Is this sofa still available in grey?',
        ], $overrides));
    }

    private function as(User $user): static
    {
        Sanctum::actingAs($user->fresh());

        return $this;
    }

    public function test_customer_starts_inquiry_and_seller_sees_it_unread(): void
    {
        $id = $this->as($this->buyer)->ask()->assertCreated()
            ->assertJsonPath('data.subject', $this->product->name)
            ->assertJsonPath('data.viewer_side', 'customer')
            ->assertJsonPath('data.unread', false)
            ->assertJsonPath('data.product.slug', $this->product->slug)
            ->assertJsonPath('data.seller.store_name', $this->seller->store_name)
            ->assertJsonCount(1, 'data.messages')
            ->assertJsonPath('data.messages.0.is_mine', true)
            ->json('data.id');

        $this->as($this->seller->user);
        $this->getJson('/api/conversations/unread-count')->assertOk()->assertJsonPath('data.unread', 1);
        $this->getJson('/api/conversations')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.unread', true)
            ->assertJsonPath('data.0.customer.name', $this->buyer->name)
            ->assertJsonMissingPath('data.0.customer.email')
            ->assertJsonPath('data.0.last_message.body', 'Is this sofa still available in grey?')
            ->assertJsonPath('data.0.last_message.is_mine', false);

        // Opening the thread marks it read for the seller only.
        $this->getJson("/api/conversations/$id")->assertOk()->assertJsonPath('data.viewer_side', 'seller')
            ->assertJsonPath('data.messages.0.is_mine', false);
        $this->getJson('/api/conversations/unread-count')->assertJsonPath('data.unread', 0);

        $this->postJson("/api/conversations/$id/messages", ['body' => 'Yes, 4 in stock.'])->assertCreated()
            ->assertJsonPath('data.is_mine', true);
        $this->getJson('/api/conversations/unread-count')->assertJsonPath('data.unread', 0);

        $this->as($this->buyer);
        $this->getJson('/api/conversations/unread-count')->assertJsonPath('data.unread', 1);
        $this->getJson("/api/conversations/$id")->assertJsonCount(2, 'data.messages');
        $this->getJson('/api/conversations/unread-count')->assertJsonPath('data.unread', 0);
    }

    public function test_messages_in_the_same_second_are_never_missed(): void
    {
        $this->freezeSecond();
        $id = $this->as($this->buyer)->ask()->json('data.id');

        $this->as($this->seller->user)->getJson("/api/conversations/$id")->assertOk();
        // Same second as the read above: a time-based unread check would miss this.
        $this->as($this->buyer)->postJson("/api/conversations/$id/messages", ['body' => 'One more thing'])->assertCreated();

        $this->as($this->seller->user)->getJson('/api/conversations/unread-count')->assertJsonPath('data.unread', 1);
    }

    public function test_second_inquiry_about_same_listing_joins_open_thread(): void
    {
        $this->as($this->buyer);
        $first = $this->ask()->assertCreated()->json('data.id');
        $second = $this->ask(['body' => 'Also, what about delivery to Bole?'])->assertOk()->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, Conversation::count());
        $this->getJson("/api/conversations/$first")->assertJsonCount(2, 'data.messages');

        // After closing, a new inquiry starts a fresh thread.
        $this->postJson("/api/conversations/$first/close")->assertOk()->assertJsonPath('data.status', 'closed');
        $third = $this->ask()->assertCreated()->json('data.id');
        $this->assertNotSame($first, $third);
    }

    public function test_store_level_inquiry_without_a_listing(): void
    {
        $this->as($this->buyer)->ask(['product_slug' => null, 'seller_slug' => $this->seller->slug])
            ->assertCreated()
            ->assertJsonPath('data.product', null)
            ->assertJsonPath('data.subject', 'Question');

        // A store-level thread and a listing thread with the same store are separate.
        $this->ask()->assertCreated();
        $this->assertSame(2, Conversation::count());
    }

    public function test_inquiry_type_rules(): void
    {
        $this->as($this->buyer);

        $this->ask(['type' => 'bogus'])->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->ask(['product_slug' => null])->assertUnprocessable()->assertJsonValidationErrors(['product_slug', 'seller_slug']);

        $this->product->update(['price_negotiable' => false]);
        $this->ask(['type' => 'negotiation'])->assertUnprocessable()->assertJsonValidationErrors('type');

        $this->ask(['type' => 'custom_request'])->assertUnprocessable()->assertJsonValidationErrors('type');

        $storeOnly = ['product_slug' => null, 'seller_slug' => $this->seller->slug];
        $this->ask([...$storeOnly, 'type' => 'availability'])->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->seller->update(['offers_custom_orders' => false]);
        $this->ask([...$storeOnly, 'type' => 'custom_request'])->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->seller->update(['offers_custom_orders' => true]);
        $this->ask([...$storeOnly, 'type' => 'custom_request', 'subject' => 'Built-in wardrobe 2.4m'])->assertCreated()
            ->assertJsonPath('data.subject', 'Built-in wardrobe 2.4m')
            ->assertJsonPath('data.type', 'custom_request');

        $this->assertSame(1, Conversation::count());
    }

    public function test_message_body_validation(): void
    {
        $this->as($this->buyer);
        $this->ask(['body' => ''])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->ask(['body' => str_repeat('a', 2001)])->assertUnprocessable()->assertJsonValidationErrors('body');
        $id = $this->ask(['body' => '  hello there  '])->assertCreated()->assertJsonPath('data.messages.0.body', 'hello there')->json('data.id');

        $this->postJson("/api/conversations/$id/messages", ['body' => '   '])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->postJson("/api/conversations/$id/messages", ['body' => ['array']])->assertUnprocessable();

        // Markup is stored as text; the frontend renders it escaped.
        $this->postJson("/api/conversations/$id/messages", ['body' => '<script>alert(1)</script>'])->assertCreated()
            ->assertJsonPath('data.body', '<script>alert(1)</script>');
    }

    public function test_cannot_contact_hidden_listings_or_stores(): void
    {
        $this->as($this->buyer);

        $draft = Product::factory()->create(['seller_profile_id' => $this->seller->id, 'category_id' => $this->category()->id]);
        $this->ask(['product_slug' => $draft->slug])->assertUnprocessable()->assertJsonValidationErrors('product_slug');
        $this->ask(['product_slug' => 'does-not-exist'])->assertUnprocessable();

        $pending = $this->pendingSeller();
        $this->ask(['product_slug' => null, 'seller_slug' => $pending->slug])->assertUnprocessable()->assertJsonValidationErrors('seller_slug');

        $this->seller->forceFill(['status' => 'suspended'])->save();
        $this->ask()->assertUnprocessable()->assertJsonValidationErrors('product_slug');
        $this->assertSame(0, Conversation::count());
    }

    public function test_only_customers_can_start_inquiries(): void
    {
        $otherSeller = $this->approvedSeller();
        $this->as($otherSeller->user)->ask()->assertForbidden();
        $this->as($this->seller->user)->ask()->assertForbidden();
        $this->as($this->admin())->ask()->assertForbidden();

        Sanctum::actingAs(User::factory()->suspended()->create());
        $this->ask()->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/conversations', [])->assertUnauthorized();
        $this->getJson('/api/conversations')->assertUnauthorized();
    }

    public function test_outsiders_get_404_for_every_conversation_action(): void
    {
        $id = $this->as($this->buyer)->ask()->json('data.id');

        $outsiders = [$this->customer(), $this->approvedSeller()->user];
        foreach ($outsiders as $outsider) {
            $this->as($outsider);
            $this->getJson("/api/conversations/$id")->assertNotFound()->assertExactJson(['message' => 'Not found.']);
            $this->postJson("/api/conversations/$id/messages", ['body' => 'hi'])->assertNotFound();
            $this->postJson("/api/conversations/$id/close")->assertNotFound();
            $this->getJson('/api/conversations')->assertJsonCount(0, 'data');
            $this->getJson('/api/conversations/unread-count')->assertJsonPath('data.unread', 0);
        }

        $this->as($this->admin())->getJson("/api/conversations/$id")->assertForbidden();
        $this->assertSame(1, Conversation::find($id)->messages()->count());
    }

    public function test_closed_conversation_is_read_only_for_both_sides(): void
    {
        $id = $this->as($this->buyer)->ask()->json('data.id');
        $this->as($this->seller->user)->postJson("/api/conversations/$id/close")->assertOk();
        // Closing twice is harmless.
        $this->postJson("/api/conversations/$id/close")->assertOk()->assertJsonPath('data.status', 'closed');

        $this->postJson("/api/conversations/$id/messages", ['body' => 'hello'])->assertUnprocessable();
        $this->as($this->buyer)->postJson("/api/conversations/$id/messages", ['body' => 'hello'])->assertUnprocessable();
        $this->getJson("/api/conversations/$id")->assertOk();
    }

    public function test_suspended_store_keeps_history_but_nobody_can_write(): void
    {
        $id = $this->as($this->buyer)->ask()->json('data.id');
        $this->seller->forceFill(['status' => 'suspended'])->save();

        $this->as($this->buyer)->getJson("/api/conversations/$id")->assertOk();
        $this->postJson("/api/conversations/$id/messages", ['body' => 'hello?'])->assertUnprocessable()
            ->assertJsonPath('errors.body.0', 'This store is not accepting messages right now.');

        $this->as($this->seller->user)->getJson("/api/conversations/$id")->assertOk();
        $this->postJson("/api/conversations/$id/messages", ['body' => 'sorry'])->assertUnprocessable();
    }

    public function test_deleted_listing_still_shows_in_thread(): void
    {
        $id = $this->as($this->buyer)->ask()->json('data.id');
        $this->product->delete();

        $this->getJson("/api/conversations/$id")->assertOk()->assertJsonPath('data.product.name', $this->product->name);
    }

    public function test_inbox_filters_and_ordering(): void
    {
        $other = Product::factory()->published()->create(['seller_profile_id' => $this->seller->id, 'category_id' => $this->category()->id]);
        $this->as($this->buyer);
        $first = $this->ask()->json('data.id');
        $this->travel(1)->minutes();
        $second = $this->ask(['product_slug' => $other->slug])->json('data.id');

        $this->getJson('/api/conversations')->assertJsonPath('data.0.id', $second)->assertJsonPath('data.1.id', $first);

        $this->travel(1)->minutes();
        $this->as($this->seller->user)->postJson("/api/conversations/$first/messages", ['body' => 'reply'])->assertCreated();
        $this->postJson("/api/conversations/$second/close")->assertOk();

        $this->as($this->buyer);
        $this->getJson('/api/conversations')->assertJsonPath('data.0.id', $first);
        $this->getJson('/api/conversations?unread=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first);
        $this->getJson('/api/conversations?status=closed')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $second);
        $this->getJson('/api/conversations?status=nonsense')->assertUnprocessable();
    }

    public function test_inquiries_are_rate_limited(): void
    {
        $this->as($this->buyer);
        $products = Product::factory()->count(11)->published()->create(['seller_profile_id' => $this->seller->id, 'category_id' => $this->category()->id]);

        foreach ($products->take(10) as $product) {
            $this->ask(['product_slug' => $product->slug])->assertCreated();
        }
        $this->ask(['product_slug' => $products->last()->slug])->assertTooManyRequests();
    }
}
