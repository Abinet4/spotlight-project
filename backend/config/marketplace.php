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

    // Placeholder only. The real rate becomes an admin setting in the payments milestone.
    'commission' => [
        'default_rate_percent' => (float) env('MARKETPLACE_DEFAULT_COMMISSION_PERCENT', 10),
    ],

];
