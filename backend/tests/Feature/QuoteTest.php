<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Product;
use App\Models\Quote;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QuoteTest extends TestCase
{
    use RefreshDatabase;

    private SellerProfile $seller;

    private User $buyer;

    private int $conversationId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        $this->seller = $this->approvedSeller();
        $this->seller->forceFill(['offers_custom_orders' => true])->save();
        $product = Product::factory()->published()->create([
            'seller_profile_id' => $this->seller->id,
            'category_id' => $this->category()->id,
            'custom_order_available' => true,
        ]);
        $this->buyer = $this->customer();

        $this->as($this->buyer);
        $this->conversationId = $this->postJson('/api/conversations', [
            'product_slug' => $product->slug,
            'type' => 'custom_request',
            'body' => 'Can you make this sofa 240cm wide in green velvet?',
        ])->assertCreated()->json('data.id');
    }

    private function as(User $user): static
    {
        Sanctum::actingAs($user->fresh());

        return $this;
    }

    private function sendQuote(array $overrides = [], ?int $conversationId = null): TestResponse
    {
        return $this->postJson('/api/seller/conversations/'.($conversationId ?? $this->conversationId).'/quotes', array_merge([
            'title' => 'Custom 240cm velvet sofa',
            'description' => 'Green velvet, 240cm wide, hardwood frame.',
            'price' => '45000.50',
            'quantity' => 2,
            'delivery_fee' => '600',
            'production_days' => 21,
            'valid_until' => now()->addDays(7)->toDateString(),
        ], $overrides));
    }

    public function test_seller_sends_quote_and_customer_accepts(): void
    {
        $quoteId = $this->as($this->seller->user)->sendQuote()->assertCreated()
            ->assertJsonPath('data.price', '45000.50')
            ->assertJsonPath('data.price_santim', 4500050)
            ->assertJsonPath('data.delivery_fee', '600.00')
            ->assertJsonPath('data.total_santim', 4500050 * 2 + 60000)
            ->assertJsonPath('data.status', 'pending')
            ->json('data.id');

        $this->as($this->buyer);
        $this->getJson('/api/conversations/unread-count')->assertJsonPath('data.unread', 1);
        $this->getJson("/api/conversations/{$this->conversationId}")
            ->assertJsonPath('data.quotes.0.id', $quoteId)
            ->assertJsonPath('data.messages.1.quote_id', $quoteId)
            ->assertJsonPath('data.messages.1.body', 'Sent a quote: Custom 240cm velvet sofa for ETB 90601.00 in total.');

        $this->postJson("/api/quotes/$quoteId/accept")->assertOk()->assertJsonPath('data.status', 'accepted');
        $this->assertNotNull(Quote::find($quoteId)->responded_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'quote.accepted', 'subject_id' => $quoteId]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'quote.sent', 'subject_id' => $quoteId]);

        // A decided quote cannot change again, from either side.
        $this->postJson("/api/quotes/$quoteId/accept")->assertUnprocessable();
        $this->postJson("/api/quotes/$quoteId/decline")->assertUnprocessable();
        $this->as($this->seller->user)->postJson("/api/seller/quotes/$quoteId/withdraw")->assertUnprocessable();
        $this->getJson('/api/conversations/unread-count')->assertJsonPath('data.unread', 1);
    }

    public function test_new_quote_supersedes_the_pending_one(): void
    {
        $this->as($this->seller->user);
        $old = $this->sendQuote()->json('data.id');
        $new = $this->sendQuote(['price' => '43000'])->json('data.id');

        $this->assertSame('withdrawn', Quote::find($old)->status->value);
        $this->as($this->buyer)->postJson("/api/quotes/$old/accept")->assertUnprocessable()
            ->assertJsonPath('errors.quote.0', 'This quote is already withdrawn.');
        $this->postJson("/api/quotes/$new/accept")->assertOk();
    }

    public function test_customer_declines_and_seller_withdraws(): void
    {
        $this->as($this->seller->user);
        $first = $this->sendQuote()->json('data.id');
        $this->as($this->buyer)->postJson("/api/quotes/$first/decline")->assertOk()->assertJsonPath('data.status', 'declined');

        $second = $this->as($this->seller->user)->sendQuote()->json('data.id');
        $this->postJson("/api/seller/quotes/$second/withdraw")->assertOk()->assertJsonPath('data.status', 'withdrawn');
        $this->as($this->buyer)->postJson("/api/quotes/$second/accept")->assertUnprocessable();
    }

    public function test_expired_quote_cannot_be_accepted_but_is_valid_through_its_last_day(): void
    {
        $this->as($this->seller->user);
        $id = $this->sendQuote(['valid_until' => now()->toDateString()])->assertCreated()->json('data.id');

        $this->travelTo(now()->endOfDay()->subMinute());
        $this->as($this->buyer)->getJson("/api/conversations/{$this->conversationId}")->assertJsonPath('data.quotes.0.status', 'pending');

        $this->travelTo(now()->addDay()->startOfDay()->addMinute());
        $this->getJson("/api/conversations/{$this->conversationId}")->assertJsonPath('data.quotes.0.status', 'expired');
        $this->postJson("/api/quotes/$id/accept")->assertUnprocessable()->assertJsonPath('errors.quote.0', 'This quote is already expired.');
        // Stored status stays pending; expiry is derived.
        $this->assertSame('pending', Quote::find($id)->status->value);
    }

    public function test_quote_validation(): void
    {
        $this->as($this->seller->user);
        $this->sendQuote(['price' => '0.99'])->assertUnprocessable()->assertJsonValidationErrors('price');
        $this->sendQuote(['price' => '-5'])->assertUnprocessable()->assertJsonValidationErrors('price');
        $this->sendQuote(['price' => '12.345'])->assertUnprocessable()->assertJsonValidationErrors('price');
        $this->sendQuote(['price' => '1e5'])->assertUnprocessable()->assertJsonValidationErrors('price');
        $this->sendQuote(['quantity' => 0])->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->sendQuote(['quantity' => 1001])->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->sendQuote(['delivery_fee' => 'free'])->assertUnprocessable()->assertJsonValidationErrors('delivery_fee');
        $this->sendQuote(['valid_until' => now()->subDay()->toDateString()])->assertUnprocessable()->assertJsonValidationErrors('valid_until');
        $this->sendQuote(['valid_until' => now()->addDays(61)->toDateString()])->assertUnprocessable()->assertJsonValidationErrors('valid_until');
        $this->sendQuote(['valid_until' => 'next week'])->assertUnprocessable()->assertJsonValidationErrors('valid_until');
        $this->sendQuote(['title' => '', 'description' => 'short'])->assertUnprocessable()->assertJsonValidationErrors(['title', 'description']);
        $this->assertSame(0, Quote::count());

        // Optional fields may be left out; the largest allowed values do not overflow.
        $this->sendQuote(['delivery_fee' => null, 'production_days' => null, 'quantity' => 1000, 'price' => '9999999999.99'])
            ->assertCreated()->assertJsonPath('data.total_santim', 999999999999 * 1000)->assertJsonPath('data.delivery_fee', null);
    }

    public function test_only_the_conversation_seller_can_quote(): void
    {
        $other = $this->approvedSeller();
        $this->as($other->user)->sendQuote()->assertNotFound();
        $this->as($this->buyer)->sendQuote()->assertForbidden();
        $this->as($this->admin())->sendQuote()->assertForbidden();
        $this->assertSame(0, Quote::count());
    }

    public function test_only_the_conversation_customer_can_respond(): void
    {
        $id = $this->as($this->seller->user)->sendQuote()->json('data.id');

        $this->as($this->customer())->postJson("/api/quotes/$id/accept")->assertNotFound();
        $this->postJson("/api/quotes/$id/decline")->assertNotFound();
        $this->as($this->seller->user)->postJson("/api/quotes/$id/accept")->assertForbidden();
        $this->as($this->approvedSeller()->user)->postJson("/api/seller/quotes/$id/withdraw")->assertNotFound();
        $this->as($this->buyer)->postJson("/api/seller/quotes/$id/withdraw")->assertForbidden();
        $this->postJson('/api/quotes/999999/accept')->assertNotFound();

        $this->assertSame('pending', Quote::find($id)->status->value);
    }

    public function test_no_quotes_or_acceptance_once_closed_or_suspended(): void
    {
        $id = $this->as($this->seller->user)->sendQuote()->json('data.id');

        // Store suspended: the customer cannot accept, but can still decline.
        $this->seller->forceFill(['status' => 'suspended'])->save();
        $this->as($this->buyer)->postJson("/api/quotes/$id/accept")->assertUnprocessable();
        $this->as($this->seller->user)->sendQuote()->assertUnprocessable();
        $this->seller->forceFill(['status' => 'approved'])->save();

        // Customer closes: the pending quote is declined and no new quotes can arrive.
        $this->as($this->buyer)->postJson("/api/conversations/{$this->conversationId}/close")->assertOk()
            ->assertJsonPath('data.quotes.0.status', 'declined');
        $this->as($this->seller->user)->sendQuote()->assertUnprocessable();
        $this->as($this->buyer)->postJson("/api/quotes/$id/accept")->assertUnprocessable();
    }

    public function test_seller_closing_withdraws_pending_quote(): void
    {
        $this->as($this->seller->user);
        $id = $this->sendQuote()->json('data.id');
        $this->postJson("/api/conversations/{$this->conversationId}/close")->assertOk();
        $this->assertSame('withdrawn', Quote::find($id)->status->value);
        $this->assertSame('closed', Conversation::find($this->conversationId)->status);
    }
}
