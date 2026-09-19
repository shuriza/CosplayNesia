<?php

use App\Http\Controllers\AccountSecurityController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AvailabilityController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\EmailVerificationController;
use App\Http\Controllers\FavoriteController;
use App\Http\Controllers\FulfillmentController;
use App\Http\Controllers\FulfillmentMessageController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductReviewController;
use App\Http\Controllers\ProductReviewFeedController;
use App\Http\Controllers\ReadinessController;
use App\Http\Controllers\RentalBlockController;
use App\Http\Controllers\SellerReviewController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'home')->name('home');
Route::get('/ready', ReadinessController::class)->name('readiness');

Route::prefix('api')->group(function (): void {
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/{product}/availability', [AvailabilityController::class, 'show']);
    Route::get('/products/{product}/reviews', [ProductReviewFeedController::class, 'index']);
    Route::get('/me', [AuthController::class, 'show'])->middleware(['auth.session', 'account.session']);
    Route::get('/auth/verify-email/{user}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');
    Route::get('/auth/confirm-email/{user}/{token}', [EmailVerificationController::class, 'confirmChange'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('email-change.confirm');

    Route::middleware('guest')->prefix('auth')->group(function (): void {
        Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:6,1');
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
        Route::post('/forgot-password', [PasswordResetController::class, 'request'])->middleware('throttle:5,1');
        Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:5,1');
    });

    Route::middleware(['auth', 'auth.session', 'account.session'])->group(function (): void {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::patch('/me', [AuthController::class, 'updateProfile'])->middleware('throttle:10,1');
        Route::patch('/me/password', [AuthController::class, 'updatePassword'])->middleware('throttle:6,1');
        Route::get('/me/email-verification', [EmailVerificationController::class, 'notice']);
        Route::post('/me/email-verification', [EmailVerificationController::class, 'send'])->middleware('throttle:3,1');
        Route::get('/me/sessions', [AccountSecurityController::class, 'sessions']);
        Route::delete('/me/sessions/{session}', [AccountSecurityController::class, 'revokeSession']);
        Route::delete('/me/sessions', [AccountSecurityController::class, 'revokeAll']);
        Route::post('/me/legal-consent', [AccountSecurityController::class, 'acceptLegal']);
        Route::delete('/me', [AccountSecurityController::class, 'deactivate'])->middleware('throttle:3,1');

        Route::post('/products', [ProductController::class, 'store'])->middleware('transaction.ready');
        Route::patch('/products/{product}', [ProductController::class, 'update'])->middleware('transaction.ready');
        Route::delete('/products/{product}', [ProductController::class, 'destroy'])->middleware('transaction.ready');
        Route::get('/my-products', [ProductController::class, 'owned']);

        Route::get('/products/{product}/rental-blocks', [RentalBlockController::class, 'index']);
        Route::post('/products/{product}/rental-blocks', [RentalBlockController::class, 'store'])->middleware('transaction.ready');
        Route::delete('/products/{product}/rental-blocks/{block}', [RentalBlockController::class, 'destroy'])->middleware('transaction.ready');

        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::patch('/notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markRead'])
            ->whereNumber('notification');

        Route::post('/favorites', [FavoriteController::class, 'store']);
        Route::delete('/favorites/{product}', [FavoriteController::class, 'destroy']);

        Route::post('/checkout', [CheckoutController::class, 'store'])->middleware('transaction.ready');
        Route::get('/orders', [OrderController::class, 'index']);
        Route::get('/orders/{order}', [OrderController::class, 'show']);
        Route::post('/orders/{order}/items/{item}/review', [ProductReviewController::class, 'store']);
        Route::delete('/orders/{order}/items/{item}/rental', [OrderController::class, 'cancelRental']);
        Route::get('/seller/fulfillments', [FulfillmentController::class, 'index']);
        Route::get('/seller/fulfillments/{fulfillment}', [FulfillmentController::class, 'show']);
        Route::patch('/seller/fulfillments/{fulfillment}/status', [FulfillmentController::class, 'updateStatus']);
        Route::get('/seller/reviews', [SellerReviewController::class, 'index']);
        Route::patch('/seller/reviews/{review}/reply', [SellerReviewController::class, 'update']);
        Route::delete('/seller/reviews/{review}/reply', [SellerReviewController::class, 'destroy']);

        // Shared by both parties: participation is resolved per fulfillment, not per URL prefix.
        Route::get('/fulfillments/{fulfillment}/messages', [FulfillmentMessageController::class, 'index']);
        Route::post('/fulfillments/{fulfillment}/messages', [FulfillmentMessageController::class, 'store']);
        Route::patch('/fulfillments/{fulfillment}/messages/read', [FulfillmentMessageController::class, 'markRead']);
    });
});
