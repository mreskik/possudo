<?php

use App\Http\Controllers\IndexController;
use App\Http\Controllers\OrderController;
use App\Models\TrOrder;
use Illuminate\Support\Facades\Route;

// Route::get('/', function () {
//     return 'ok';
// });
Route::get('/{any}', [IndexController::class, 'index'])->where('any', '.*');


//route order
// Route::get('/api/order', [OrderController::class, 'index']);
// Route::apiResource('api/order', OrderController::class);
