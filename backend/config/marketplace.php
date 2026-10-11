<?php

/*
|--------------------------------------------------------------------------
| Marketplace configuration
|--------------------------------------------------------------------------
|
| Business settings that are expected to change before launch live here and
| are read from the environment, so nothing is a hardcoded business decision.
| Values that administrators will edit at runtime (commission rates, delivery
| zones) move to database-backed settings in a later milestone.
|
*/

return [

    // Temporary brand name. Change MARKETPLACE_NAME once the name is final.
    'name' => env('MARKETPLACE_NAME', 'Betoch Furniture'),

    'currency' => 'ETB',

    // Default city slug used for new sellers and listings.
    'default_city' => env('MARKETPLACE_DEFAULT_CITY', 'addis-ababa'),

    'catalog' => [
        'per_page_default' => 24,
        'per_page_max' => 48,
    ],

    'products' => [
        // Max number of images per listing.
        'max_images' => (int) env('MARKETPLACE_MAX_PRODUCT_IMAGES', 8),
        // Max image size in kilobytes.
        'max_image_kb' => (int) env('MARKETPLACE_MAX_IMAGE_KB', 5120),
        // Allowed image extensions (validated against file content, not just the name).
        'image_mimes' => ['jpg', 'jpeg', 'png', 'webp'],
        // When true, editing descriptive fields of a published listing sends it back to moderation.
        'reapprove_on_edit' => (bool) env('MARKETPLACE_REAPPROVE_ON_EDIT', true),
    ],

    'contact' => [
        // Public store phone numbers let buyers skip the platform (and its commission). Set to false
        // once in-app inquiries are the main contact route.
        'show_seller_phone' => (bool) env('MARKETPLACE_SHOW_SELLER_PHONE', true),
    ],

    // Default only: administrators change the live rate in Admin > Settings. Each order stores
    // the rate used when it was placed, so changes never rewrite past orders.
    'commission' => [
        'default_rate_percent' => (float) env('MARKETPLACE_DEFAULT_COMMISSION_PERCENT', 10),
        'max_rate_percent' => 50,
    ],

    'payments' => [
        // Cash on delivery. Online payment providers are added in a later milestone.
        'cod_enabled' => (bool) env('MARKETPLACE_COD_ENABLED', true),
    ],

    'cart' => [
        'max_lines' => 30,
        'max_quantity' => 99,
    ],

    // Days after acceptance a customer still has to order an accepted quote, if its own validity is shorter.
    'quotes' => [
        'order_grace_days' => 7,
    ],

];
