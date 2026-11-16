<?php

use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\OrderMessageController;
use App\Http\Controllers\Api\V1\OrderStatusController;
use App\Http\Controllers\Api\V1\ServiceController;
use App\Http\Controllers\Api\V1\TechnicianReviewController;
use App\Http\Middleware\AuthenticateMobileToken;
use App\Http\Middleware\EnsureMobileEmailVerified;
use Illuminate\Support\Facades\Route;

Route::get('services', [ServiceController::class, 'index'])->middleware('throttle:60,1')->name('services.index');
Route::middleware([AuthenticateMobileToken::class, EnsureMobileEmailVerified::class])->group(function (): void {
    Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
    Route::post('orders', [OrderController::class, 'store'])->middleware('throttle:10,1')->name('orders.store');
    Route::prefix('orders/{order}')->name('orders.')->middleware('can:view,order')->group(function (): void {
        Route::get('/', [OrderController::class, 'show'])->name('show');
        Route::get('messages', [OrderMessageController::class, 'index'])->name('messages.index');
        Route::post('messages', [OrderMessageController::class, 'store'])->middleware('throttle:30,1')->name('messages.store');
        Route::patch('status', [OrderStatusController::class, 'update'])->middleware('throttle:20,1')->name('status.update');
        Route::post('reviews', [TechnicianReviewController::class, 'store'])->middleware('throttle:10,1')->name('reviews.store');
    });
});
