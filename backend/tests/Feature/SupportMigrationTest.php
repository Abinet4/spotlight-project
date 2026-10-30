<?php

namespace Tests\Feature;

use App\Models\CartItem;
use App\Models\Message;
use App\Models\OrderItem;
use App\Models\Quote;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\MakesSupportData;
use Tests\Concerns\PlacesOrders;
use Tests\TestCase;

/**
 * The support migrations run on databases that already hold store chats, quotes, carts and orders.
 * Real migrations (no wrapping transaction), because SQLite rebuilds the quotes table to make
 * conversation_id optional, and a rebuild with foreign keys on would cascade into cart lines.
 */
class SupportMigrationTest extends TestCase
{
    use DatabaseMigrations, MakesSupportData, PlacesOrders;

    private const SUPPORT_MIGRATIONS = ['2026_10_18_100000_create_support_tables', '2026_10_18_100100_link_quotes_to_support'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrders();
    }

    /** Every row of the tables that point at quotes, as plain arrays. */
    private function snapshot(): array
    {
        return collect(['conversations', 'messages', 'quotes', 'cart_items', 'orders', 'order_items'])
            ->mapWithKeys(fn (string $t) => [$t => DB::table($t)->orderBy('id')->get()->map(fn ($r) => collect((array) $r)->except(['support_ticket_id', 'seller_request_id'])->all())->all()])
            ->all();
    }

    private function legacyData(): void
    {
        $chat = $this->oldConversation($this->buyer, $this->seller, $this->sofa);
        $ordered = $this->oldQuote($chat, ['price_santim' => 2_000_000]);
        $inCart = $this->oldQuote($chat, ['title' => 'Matching chair', 'price_santim' => 300_000]);
        $this->oldQuote($chat, ['title' => 'Still pending']);
        Message::where('conversation_id', $chat->id)->latest('id')->first()->forceFill(['quote_id' => $ordered->id])->save();
        Quote::whereKey([$ordered->id, $inCart->id])->update(['status' => 'accepted', 'responded_at' => now()]);

        // An order and a cart line from an accepted quote, as checkout wrote them while quotes could be ordered.
        $this->placeOrder(1)->items()->first()->forceFill(['quote_id' => $ordered->id])->save();
        $line = new CartItem;
        $line->user_id = $this->buyer->id;
        $line->quote_id = $inCart->id;
        $line->quantity = 1;
        $line->save();

        $this->assertSame(1, OrderItem::whereNotNull('quote_id')->count());
        $this->assertSame(1, CartItem::whereNotNull('quote_id')->count());
    }

    private function foreignKeyProblems(): array
    {
        return DB::getDriverName() === 'sqlite' ? DB::select('PRAGMA foreign_key_check') : [];
    }

    /** Only the support migrations: the ERP migrations sort after them but are not part of this. */
    private function rollBackSupport(): void
    {
        $this->assertSame(2, DB::table('migrations')->whereIn('migration', self::SUPPORT_MIGRATIONS)->count());
        Artisan::call('migrate:rollback', ['--path' => self::paths(self::SUPPORT_MIGRATIONS)]);
        $this->assertSame(0, DB::table('migrations')->whereIn('migration', self::SUPPORT_MIGRATIONS)->count());
    }

    private static function paths(array $migrations): array
    {
        return array_map(fn ($m) => "database/migrations/{$m}.php", $migrations);
    }

    public function test_store_chats_quotes_carts_and_orders_survive_both_ways(): void
    {
        // Chats, quotes, a cart line and an order of the kind written before support messaging.
        $this->legacyData();
        $before = $this->snapshot();

        $this->rollBackSupport();
        $this->assertFalse(Schema::hasTable('support_tickets'));
        $this->assertFalse(Schema::hasColumn('quotes', 'support_ticket_id'));
        $this->assertSame($before, $this->snapshot());
        $this->assertSame([], $this->foreignKeyProblems());

        Artisan::call('migrate');
        $this->assertTrue(Schema::hasColumn('quotes', 'support_ticket_id'));
        $this->assertSame($before, $this->snapshot());
        $this->assertSame([], $this->foreignKeyProblems());
        $this->assertSame(3, Quote::whereNotNull('conversation_id')->whereNull('support_ticket_id')->count());

        // The chat and the order still read. A quote line left in a cart is not a listing, so the cart skips it.
        $this->actAs($this->buyer)->getJson('/api/conversations/'.DB::table('conversations')->value('id'))->assertOk()
            ->assertJsonCount(3, 'data.quotes');
        $this->getJson('/api/orders/'.OrderItem::whereNotNull('quote_id')->value('order_id'))->assertOk();
        $this->getJson('/api/cart?address_id='.$this->address->id)->assertOk()->assertJsonPath('data.line_count', 0);
    }

    public function test_rollback_is_refused_once_an_offer_was_sent_through_support(): void
    {
        $ticket = $this->askSupport($this->buyer, ['topic' => 'price', 'seller_slug' => $this->seller->slug])->json('data.id');
        $id = $this->sentOffer($ticket, $this->seller)->id;

        try {
            Artisan::call('migrate:rollback', ['--path' => self::paths([self::SUPPORT_MIGRATIONS[1]])]);
            $this->fail('The rollback should have been refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('sent through support', $e->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('quotes', 'support_ticket_id'));
        $this->assertSame($ticket, Quote::find($id)->support_ticket_id);
        $this->assertSame(2, DB::table('migrations')->whereIn('migration', self::SUPPORT_MIGRATIONS)->count());

        // The test framework rolls every migration back afterwards; give the offer a chat so it can.
        Quote::whereKey($id)->update(['conversation_id' => $this->oldConversation($this->buyer, $this->seller)->id]);
    }

    public function test_after_rollback_a_quote_needs_a_conversation_again(): void
    {
        $this->rollBackSupport();
        $this->expectException(QueryException::class);
        DB::table('quotes')->insert([
            'seller_profile_id' => $this->seller->id, 'title' => 'x', 'description' => 'x', 'price_santim' => 100, 'quantity' => 1,
            'valid_until' => now()->toDateString(), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
