<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Checkout;
use App\Models\Order;
use App\Models\Setting;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Customer side: place an order from the cart, list and view own orders, cancel early.
 */
class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

    public function checkout(Request $request): JsonResponse
    {
        $key = (string) $request->header('Idempotency-Key');
        if (! preg_match('/^[A-Za-z0-9-]{8,64}$/', $key)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Missing or invalid Idempotency-Key header.']);
        }

        $data = $request->validate([
            'address_id' => ['required', 'integer'],
            'payment_method' => ['required', Rule::in(['cod'])],
            'fulfillment' => ['nullable', 'array'],
            'fulfillment.*' => [Rule::in(['delivery', 'pickup'])],
            'expected_total_santim' => ['required', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        if ($data['payment_method'] === 'cod' && ! Setting::codEnabled()) {
            throw ValidationException::withMessages(['payment_method' => 'Cash on delivery is not available right now.']);
        }

        $user = $request->user();
        $address = $user->addresses()->with('area')->findOrFail($data['address_id']);
        if (! $address->area?->is_active) {
            throw ValidationException::withMessages(['address_id' => 'We do not deliver to this sub-city right now. Choose another address.']);
        }

        [$checkout, $created] = $this->orders->place(
            $user, $address, $key, $data['payment_method'], $data['fulfillment'] ?? [],
            $data['expected_total_santim'], isset($data['note']) ? trim($data['note']) ?: null : null,
        );

        return response()->json(['data' => $this->checkoutPayload($checkout)], $created ? 201 : 200);
    }

    public function index(Request $request)
    {
        $orders = Order::where('customer_id', $request->user()->id)
            ->with(['seller', 'items.product.primaryImage'])
            ->latest('id')
            ->paginate(15);

        return OrderResource::collection($orders);
    }

    public function show(Request $request, int $id): OrderResource
    {
        $order = Order::where('customer_id', $request->user()->id)->findOrFail($id);

        return self::detail($order, 'customer');
    }

    public function cancel(Request $request, int $id): OrderResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        Order::where('customer_id', $request->user()->id)->findOrFail($id);
        $order = $this->orders->transition($id, OrderStatus::Cancelled, $request->user(), 'customer', trim($data['reason']));

        return self::detail($order, 'customer');
    }

    public static function detail(Order $order, string $viewer): OrderResource
    {
        $order->load(['seller.area', 'customer', 'checkout', 'items.product.primaryImage', 'items.review', 'history']);

        return (new OrderResource($order))->as($viewer);
    }

    private function checkoutPayload(Checkout $checkout): array
    {
        $checkout->load(['orders.seller', 'orders.items']);

        return [
            'id' => $checkout->id,
            'grand_total_santim' => $checkout->grand_total_santim,
            'items_total_santim' => $checkout->items_total_santim,
            'delivery_total_santim' => $checkout->delivery_total_santim,
            'payment_method' => $checkout->payment_method,
            'orders' => $checkout->orders->map(fn (Order $o) => (new OrderResource($o))->as('customer')->resolve())->values(),
        ];
    }
}
