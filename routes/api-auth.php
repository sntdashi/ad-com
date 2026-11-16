<?php

use App\Http\Controllers\Api\V1\MobileAuthController;
use App\Http\Middleware\AuthenticateMobileToken;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::post('login', [MobileAuthController::class, 'login'])->middleware('throttle:mobile-login')->name('login');
    Route::post('register', [MobileAuthController::class, 'register'])->middleware('throttle:mobile-register')->name('register');
    Route::post('two-factor-challenge', [MobileAuthController::class, 'twoFactor'])->middleware('throttle:mobile-two-factor')->name('two-factor');
    Route::post('forgot-password', [MobileAuthController::class, 'forgotPassword'])->middleware('throttle:mobile-recovery')->name('forgot-password');
    Route::get('email/verify/{id}/{hash}', [MobileAuthController::class, 'verifyEmail'])
        ->middleware(['signed', 'throttle:30,1'])->whereNumber('id')->where('hash', '[a-f0-9]{40}')->name('verification.verify');

    Route::middleware(AuthenticateMobileToken::class)->group(function (): void {
        Route::post('logout', [MobileAuthController::class, 'logout'])->name('logout');
        Route::post('email/verification-notification', [MobileAuthController::class, 'resendVerification'])
            ->middleware('throttle:mobile-verification')->name('verification.send');
    });
});

Route::get('me', [MobileAuthController::class, 'me'])->middleware(AuthenticateMobileToken::class)->name('me');
