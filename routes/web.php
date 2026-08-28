<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\AvailabilityController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\FavoriteController;
use App\Http\Controllers\FulfillmentController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductReviewController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'home')->name('home');

Route::prefix('api')->group(function (): void {
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/{product}/availability', [AvailabilityController::class, 'show']);
    Route::get('/me', [AuthController::class, 'show'])->middleware('auth.session');

    Route::middleware('guest')->prefix('auth')->group(function (): void {
        Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:6,1');
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    });

    Route::middleware(['auth', 'auth.session'])->group(function (): void {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::patch('/me', [AuthController::class, 'updateProfile'])->middleware('throttle:10,1');
        Route::patch('/me/password', [AuthController::class, 'updatePassword'])->middleware('throttle:6,1');

        Route::post('/products', [ProductController::class, 'store']);
        Route::patch('/products/{product}', [ProductController::class, 'update']);
        Route::delete('/products/{product}', [ProductController::class, 'destroy']);
        Route::get('/my-products', [ProductController::class, 'owned']);

        Route::post('/favorites', [FavoriteController::class, 'store']);
        Route::delete('/favorites/{product}', [FavoriteController::class, 'destroy']);

        Route::post('/checkout', [CheckoutController::class, 'store']);
        Route::get('/orders', [OrderController::class, 'index']);
        Route::get('/orders/{order}', [OrderController::class, 'show']);
        Route::post('/orders/{order}/items/{item}/review', [ProductReviewController::class, 'store']);
        Route::delete('/orders/{order}/items/{item}/rental', [OrderController::class, 'cancelRental']);
        Route::get('/seller/fulfillments', [FulfillmentController::class, 'index']);
        Route::get('/seller/fulfillments/{fulfillment}', [FulfillmentController::class, 'show']);
        Route::patch('/seller/fulfillments/{fulfillment}/status', [FulfillmentController::class, 'updateStatus']);
    });
});
