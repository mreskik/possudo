<?php

use App\Http\Controllers\OrderController;
use App\Models\TrOrder;
use Illuminate\Support\Facades\Route;

// Route::get('/', function () {
//     return 'ok';
// });

// bundle Vue kepisah per kanal -- public/pos/ (kasir, existing) & public/kiosk/ (self-order, baru).
// /kiosk/* eksplisit ke bundle kiosk; selain itu (termasuk root /) default ke bundle pos.
Route::get('/kiosk/{any?}', function () {
    return response()->file(public_path('kiosk/index.html'));
})->where('any', '.*');

Route::get('/{any?}', function () {
    return response()->file(public_path('pos/index.html'));
})->where('any', '.*');


//route order
// Route::get('/api/order', [OrderController::class, 'index']);
// Route::apiResource('api/order', OrderController::class);
