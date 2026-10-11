<?php

use App\Http\Controllers\Api\AddressController;
use App\Http\Controllers\Api\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Api\Admin\DashboardController;
use App\Http\Controllers\Api\Admin\DeliveryZoneController;
use App\Http\Controllers\Api\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Api\Admin\ProductModerationController;
use App\Http\Controllers\Api\Admin\SellerController as AdminSellerController;
use App\Http\Controllers\Api\Admin\SettingsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\DeliveryController;
use App\Http\Controllers\Api\FavoriteController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\QuoteController;
use App\Http\Controllers\Api\Seller\OrderController as SellerOrderController;
use App\Http\Controllers\Api\Seller\ProductController as SellerProductController;
use App\Http\Controllers\Api\Seller\ProfileController as SellerProfileController;
use App\Models\Setting;
use Illuminate\Support\Facades\Route;

Route::get('/config', fn () => response()->json([
    'name' => config('marketplace.name'),
    'currency' => config('marketplace.currency'),
    'max_product_images' => config('marketplace.products.max_images'),
    'max_image_kb' => config('marketplace.products.max_image_kb'),
    'show_seller_phone' => config('marketplace.contact.show_seller_phone'),
    'cod_enabled' => Setting::codEnabled(),
]));

// Public catalog
Route::middleware('throttle:catalog')->group(function () {
    Route::get('/categories', [CatalogController::class, 'categories']);
    Route::get('/areas', [CatalogController::class, 'areas']);
    Route::get('/products', [CatalogController::class, 'products']);
    Route::get('/products/{slug}', [CatalogController::class, 'product']);
    Route::get('/sellers/{slug}', [CatalogController::class, 'seller']);
    Route::get('/delivery-zones', [DeliveryController::class, 'zones']);
    Route::get('/products/{slug}/delivery-estimate', [DeliveryController::class, 'estimate']);
});

// Authentication
Route::prefix('auth')->group(function () {
    Route::middleware('throttle:register')->group(function () {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/register-seller', [AuthController::class, 'registerSeller']);
    });
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

// Any signed-in, active user
Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('/favorites', [FavoriteController::class, 'index']);
    Route::post('/favorites/{product}', [FavoriteController::class, 'store']);
    Route::delete('/favorites/{product}', [FavoriteController::class, 'destroy']);
});

// Customers only
Route::middleware(['auth:sanctum', 'role:customer'])->group(function () {
    Route::get('/addresses', [AddressController::class, 'index']);
    Route::post('/addresses', [AddressController::class, 'store']);
    Route::put('/addresses/{id}', [AddressController::class, 'update'])->whereNumber('id');
    Route::post('/addresses/{id}/default', [AddressController::class, 'setDefault'])->whereNumber('id');
    Route::delete('/addresses/{id}', [AddressController::class, 'destroy'])->whereNumber('id');

    Route::post('/conversations', [ConversationController::class, 'store'])->middleware('throttle:inquiries');
    Route::post('/quotes/{id}/accept', [QuoteController::class, 'accept'])->whereNumber('id');
    Route::post('/quotes/{id}/decline', [QuoteController::class, 'decline'])->whereNumber('id');

    Route::get('/cart', [CartController::class, 'show']);
    Route::get('/cart/count', [CartController::class, 'count']);
    Route::post('/cart/items', [CartController::class, 'add']);
    Route::put('/cart/items/{id}', [CartController::class, 'update'])->whereNumber('id');
    Route::delete('/cart/items/{id}', [CartController::class, 'remove'])->whereNumber('id');
    Route::post('/quotes/{id}/add-to-cart', [CartController::class, 'addQuote'])->whereNumber('id');

    Route::post('/checkout', [OrderController::class, 'checkout'])->middleware('throttle:checkout');
    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/{id}', [OrderController::class, 'show'])->whereNumber('id');
    Route::post('/orders/{id}/cancel', [OrderController::class, 'cancel'])->whereNumber('id');
});

// Inquiries: customers and sellers each see only their own side
Route::middleware(['auth:sanctum', 'role:customer,seller'])->group(function () {
    Route::get('/conversations', [ConversationController::class, 'index']);
    Route::get('/conversations/unread-count', [ConversationController::class, 'unreadCount']);
    Route::get('/conversations/{id}', [ConversationController::class, 'show'])->whereNumber('id');
    Route::post('/conversations/{id}/messages', [ConversationController::class, 'reply'])->whereNumber('id')->middleware('throttle:messages');
    Route::post('/conversations/{id}/close', [ConversationController::class, 'close'])->whereNumber('id');
});

// Seller area
Route::prefix('seller')->middleware(['auth:sanctum', 'role:seller'])->group(function () {
    Route::get('/profile', [SellerProfileController::class, 'show']);
    Route::put('/profile', [SellerProfileController::class, 'update']);

    Route::get('/products', [SellerProductController::class, 'index']);
    Route::post('/products', [SellerProductController::class, 'store'])->middleware('throttle:uploads');
    Route::get('/products/{id}', [SellerProductController::class, 'show'])->whereNumber('id');
    Route::put('/products/{id}', [SellerProductController::class, 'update'])->whereNumber('id');
    Route::delete('/products/{id}', [SellerProductController::class, 'destroy'])->whereNumber('id');
    Route::post('/products/{id}/submit', [SellerProductController::class, 'submit'])->whereNumber('id');
    Route::post('/products/{id}/archive', [SellerProductController::class, 'archive'])->whereNumber('id');
    Route::post('/products/{id}/images', [SellerProductController::class, 'uploadImages'])->whereNumber('id')->middleware('throttle:uploads');
    Route::delete('/products/{id}/images/{imageId}', [SellerProductController::class, 'deleteImage'])->whereNumber(['id', 'imageId']);

    Route::post('/conversations/{id}/quotes', [QuoteController::class, 'store'])->whereNumber('id')->middleware('throttle:messages');
    Route::post('/quotes/{id}/withdraw', [QuoteController::class, 'withdraw'])->whereNumber('id');

    Route::get('/orders', [SellerOrderController::class, 'index']);
    Route::get('/orders/summary', [SellerOrderController::class, 'summary']);
    Route::get('/orders/{id}', [SellerOrderController::class, 'show'])->whereNumber('id');
    Route::post('/orders/{id}/confirm', [SellerOrderController::class, 'confirm'])->whereNumber('id');
    Route::post('/orders/{id}/status', [SellerOrderController::class, 'advance'])->whereNumber('id');
    Route::post('/orders/{id}/cancel', [SellerOrderController::class, 'cancel'])->whereNumber('id');
});

// Administration
Route::prefix('admin')->middleware(['auth:sanctum', 'role:admin'])->group(function () {
    Route::get('/summary', [DashboardController::class, 'summary']);
    Route::get('/audit-logs', [DashboardController::class, 'auditLogs']);

    Route::get('/sellers', [AdminSellerController::class, 'index']);
    Route::get('/sellers/{seller}', [AdminSellerController::class, 'show']);
    Route::post('/sellers/{seller}/{action}', [AdminSellerController::class, 'review'])
        ->whereIn('action', ['approve', 'reject', 'suspend', 'reinstate']);

    Route::get('/products', [ProductModerationController::class, 'index']);
    Route::get('/products/{id}', [ProductModerationController::class, 'show'])->whereNumber('id');
    Route::post('/products/{id}/approve', [ProductModerationController::class, 'approve'])->whereNumber('id');
    Route::post('/products/{id}/reject', [ProductModerationController::class, 'reject'])->whereNumber('id');

    Route::get('/categories', [AdminCategoryController::class, 'index']);
    Route::post('/categories', [AdminCategoryController::class, 'store']);
    Route::put('/categories/{category}', [AdminCategoryController::class, 'update']);
    Route::delete('/categories/{category}', [AdminCategoryController::class, 'destroy']);

    Route::get('/delivery-zones', [DeliveryZoneController::class, 'index']);
    Route::post('/delivery-zones', [DeliveryZoneController::class, 'store']);
    Route::put('/delivery-zones/{zone}', [DeliveryZoneController::class, 'update']);
    Route::delete('/delivery-zones/{zone}', [DeliveryZoneController::class, 'destroy']);

    Route::get('/orders', [AdminOrderController::class, 'index']);
    Route::get('/orders/{id}', [AdminOrderController::class, 'show'])->whereNumber('id');
    Route::post('/orders/{id}/cancel', [AdminOrderController::class, 'cancel'])->whereNumber('id');

    Route::get('/settings', [SettingsController::class, 'show']);
    Route::put('/settings', [SettingsController::class, 'update']);
});
