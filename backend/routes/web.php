<?php

use Illuminate\Support\Facades\Route;

// This server only answers the shop pages' API calls. The root just says so: no session, no cookie,
// and no framework page that names the Laravel version.
Route::get('/', fn () => response()->json(['name' => config('marketplace.name'), 'api' => url('/api')]))
    ->withoutMiddleware('web');
