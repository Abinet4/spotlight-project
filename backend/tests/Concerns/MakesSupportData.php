<?php

namespace Tests\Concerns;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Product;
use App\Models\Quote;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/**
 * Support inquiries the way customers, admins and stores create them through the API, and
 * customer-store chats the way the app wrote them before support messaging existed.
 */
trait MakesSupportData
{
    protected function actAs(User $user): static
    {
        Sanctum::actingAs($user->fresh());

        return $this;
    }

    protected function askSupport(User $customer, array $payload = []): TestResponse
    {
        return $this->actAs($customer)->postJson('/api/support/tickets', array_merge([
            'topic' => 'product',
            'body' => 'Is this sofa still available in grey?',
        ], $payload));
    }

    /**
     * An offer Betoch sent inside a support inquiry, written the way the app stored them before
     * price offers were removed.
     */
    protected function sentOffer(int $ticketId, SellerProfile $store, array $overrides = []): Quote
    {
        $quote = new Quote(array_merge([
            'title' => 'Built-in wardrobe', 'description' => 'Walnut, 240 cm, installed on site.',
            'price_santim' => 4_400_000, 'quantity' => 2, 'delivery_fee_santim' => null, 'production_days' => 25,
            'valid_until' => now()->addDays(5)->toDateString(),
        ], $overrides));
        $quote->support_ticket_id = $ticketId;
        $quote->seller_profile_id = $store->id;
        $quote->save();

        return $quote;
    }

    /**
     * A conversation from before support messaging, with messages from both sides.
     */
    protected function oldConversation(User $customer, SellerProfile $store, ?Product $product = null, string $status = 'open'): Conversation
    {
        $conversation = new Conversation;
        $conversation->customer_id = $customer->id;
        $conversation->seller_profile_id = $store->id;
        $conversation->product_id = $product?->id;
        $conversation->type = 'question';
        $conversation->subject = $product?->name ?? 'Question';
        $conversation->status = $status;
        $conversation->save();

        foreach ([[$customer, 'Do you have it in grey?'], [$store->user, 'Yes, two left. Call me on 0911 000 000.']] as [$sender, $body]) {
            $message = new Message(['body' => $body]);
            $message->conversation_id = $conversation->id;
            $message->sender_id = $sender->id;
            $message->save();
            $conversation->forceFill(['last_message_id' => $message->id, 'last_message_at' => $message->created_at])->save();
        }

        return $conversation;
    }

    protected function oldQuote(Conversation $conversation, array $overrides = []): Quote
    {
        $quote = new Quote(array_merge([
            'product_id' => $conversation->product_id, 'title' => 'Custom sofa', 'description' => 'Green velvet, 240 cm.',
            'price_santim' => 4_500_000, 'quantity' => 1, 'delivery_fee_santim' => null, 'production_days' => 21,
            'valid_until' => now()->addDays(7)->toDateString(),
        ], $overrides));
        $quote->conversation_id = $conversation->id;
        $quote->seller_profile_id = $conversation->seller_profile_id;
        $quote->save();

        return $quote;
    }
}
