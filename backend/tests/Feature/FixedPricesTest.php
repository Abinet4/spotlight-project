<?php

namespace Tests\Feature;

use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Quote;
use App\Models\SupportTicket;
use App\Services\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\MakesSupportData;
use Tests\Concerns\PlacesOrders;
use Tests\TestCase;

/**
 * Every price on Betoch is the listing's price: no "negotiable" listings and no price offers.
 */
class FixedPricesTest extends TestCase
{
    use MakesSupportData, PlacesOrders, RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_31_100000_fixed_prices_only.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrders();
    }

    public function test_open_offers_are_withdrawn_and_leave_carts_while_ordered_ones_stay(): void
    {
        $this->artisan('migrate:rollback', ['--path' => [self::MIGRATION]])->assertSuccessful();
        $this->assertTrue(Schema::hasColumn('products', 'price_negotiable'));
        DB::table('products')->where('id', $this->sofa->id)->update(['price_negotiable' => true]);

        $chat = $this->oldConversation($this->buyer, $this->seller, $this->sofa);
        $ticket = $this->askSupport($this->buyer, ['topic' => 'price'])->assertCreated()->json('data.id');
        $pending = $this->oldQuote($chat);
        $declined = $this->oldQuote($chat, ['title' => 'Declined']);
        $acceptedOnly = $this->sentOffer($ticket, $this->seller);
        $ordered = $this->sentOffer($ticket, $this->seller, ['title' => 'Ordered']);
        $orderCancelled = $this->sentOffer($ticket, $this->seller, ['title' => 'Ordered, then cancelled']);
        Quote::whereKey($declined->id)->update(['status' => 'declined']);
        Quote::whereKey([$acceptedOnly->id, $ordered->id, $orderCancelled->id])->update(['status' => 'accepted']);
        $this->placeOrder(1)->items()->first()->forceFill(['quote_id' => $ordered->id])->save();
        $cancelled = $this->placeOrder(1);
        $cancelled->items()->first()->forceFill(['quote_id' => $orderCancelled->id])->save();
        $cancelled->forceFill(['status' => 'cancelled'])->save();

        // One cart line from an offer, one from a listing.
        $this->as($this->buyer)->postJson('/api/cart/items', ['product_slug' => $this->sofa->slug])->assertCreated();
        CartItem::forceCreate(['user_id' => $this->buyer->id, 'quote_id' => $acceptedOnly->id, 'quantity' => 2]);

        $this->artisan('migrate', ['--path' => self::MIGRATION])->assertSuccessful();

        $this->assertFalse(Schema::hasColumn('products', 'price_negotiable'));
        $status = fn (Quote $q) => DB::table('quotes')->where('id', $q->id)->value('status');
        $this->assertSame('withdrawn', $status($pending));
        $this->assertSame('declined', $status($declined));
        $this->assertSame('withdrawn', $status($acceptedOnly));
        $this->assertSame('accepted', $status($ordered), 'an offer that was ordered stays as it was');
        $this->assertSame('withdrawn', $status($orderCancelled), 'its only order was cancelled, so it was never bought');
        $this->assertSame([$this->sofa->id], CartItem::pluck('product_id')->all());

        // The listing, the old order and the inquiry still read; the offer can no longer be ordered.
        $this->getJson("/api/products/{$this->sofa->slug}")->assertOk()->assertJsonMissingPath('data.price_negotiable');
        $this->getJson('/api/orders/'.OrderItem::where('quote_id', $ordered->id)->value('order_id'))->assertOk()
            ->assertJsonPath('data.items.0.quote_id', $ordered->id);
        $this->getJson("/api/support/tickets/{$ticket}")->assertOk()->assertJsonPath('data.offers.2.status', 'withdrawn');

        $this->artisan('migrate:rollback', ['--path' => [self::MIGRATION]])->assertSuccessful();
        $this->assertFalse((bool) DB::table('products')->where('id', $this->sofa->id)->value('price_negotiable'));
        $this->artisan('migrate', ['--path' => self::MIGRATION])->assertSuccessful();
    }

    public function test_offers_can_no_longer_be_sent_answered_or_ordered(): void
    {
        $admin = $this->admin();
        $ticket = SupportTicket::findOrFail($this->askSupport($this->buyer, ['topic' => 'price'])->json('data.id'));
        $offer = $this->sentOffer($ticket->id, $this->seller);
        $request = $this->actAs($admin)->postJson('/api/admin/support/requests', [
            'support_ticket_id' => $ticket->id, 'seller_profile_id' => $this->seller->id, 'topic' => 'price',
            'subject' => 'Price check', 'body' => 'Is the listed price still right?',
        ])->assertCreated()->json('data.id');

        // A page opened before the change still sends a proposal: refused, with a reason.
        $this->actAs($this->seller->user)->postJson("/api/seller/requests/{$request}/messages", [
            'body' => 'We can do 40,000.', 'proposal' => ['price' => '40000', 'valid_until' => now()->addDay()->toDateString()],
        ])->assertUnprocessable()->assertJsonValidationErrors(['proposal' => 'listing price']);
        $this->postJson("/api/seller/requests/{$request}/messages", ['body' => 'Yes, it is.'])->assertCreated()
            ->assertJsonPath('data.messages.1.proposal', null);

        foreach ([
            [$this->buyer, "/api/quotes/{$offer->id}/accept"], [$this->buyer, "/api/quotes/{$offer->id}/decline"],
            [$this->buyer, "/api/quotes/{$offer->id}/add-to-cart"], [$this->seller->user, "/api/seller/quotes/{$offer->id}/withdraw"],
            [$admin, "/api/admin/support/offers/{$offer->id}/withdraw"], [$admin, "/api/admin/support/requests/{$request}/proposals/1/send"],
        ] as [$who, $url]) {
            $this->actAs($who)->postJson($url)->assertNotFound();
        }
        $this->assertSame('pending', $offer->fresh()->status->value);
        $this->assertSame(0, CartItem::count());

        // Old clients that still send the negotiable flag are not refused; it is simply not stored.
        $this->actAs($this->seller->user)->putJson("/api/seller/products/{$this->sofa->id}", ['price' => '12000', 'price_negotiable' => true])
            ->assertOk()->assertJsonPath('data.price_santim', 1_200_000)->assertJsonMissingPath('data.price_negotiable');
    }

    public function test_items_ordered_from_an_offer_are_not_put_back_in_the_cart(): void
    {
        config(['marketplace.payments.gateway' => 'fake']);
        $this->app->forgetInstance(PaymentGateway::class);
        $chat = $this->oldConversation($this->buyer, $this->seller, $this->sofa);
        $quote = $this->oldQuote($chat);

        $this->as($this->buyer)->postJson('/api/cart/items', ['product_slug' => $this->sofa->slug, 'quantity' => 2])->assertCreated();
        $expected = $this->getJson('/api/cart?address_id='.$this->address->id)->json('data.grand_total_santim');
        $this->postJson('/api/checkout', ['address_id' => $this->address->id, 'payment_method' => 'online', 'expected_total_santim' => $expected],
            ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $order = Order::sole();
        // A second line, as an online order holding an accepted offer looked before offers were removed.
        $offerLine = $order->items()->first()->replicate()->forceFill(['quote_id' => $quote->id, 'reserved_quantity' => 0]);
        $offerLine->save();

        $this->postJson("/api/checkouts/{$order->checkout_id}/cancel")->assertOk();
        $this->assertSame([[$this->sofa->id, null, 2]], CartItem::get()->map(fn ($i) => [$i->product_id, $i->quote_id, $i->quantity])->all());
    }
}
