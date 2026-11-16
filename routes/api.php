<?php

use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\TechnicianPerformanceController;
use App\Http\Controllers\AssistantActionController;
use App\Http\Controllers\AssistantMessageController;
use App\Http\Middleware\AuthenticateMobileToken;
use App\Http\Middleware\EnsureMobileEmailVerified;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    require __DIR__.'/api-auth.php';
    require __DIR__.'/api-orders.php';

    Route::middleware(AuthenticateMobileToken::class)->group(function (): void {
        Route::patch('me', [ProfileController::class, 'update'])->middleware('throttle:20,1')->name('me.update');
        Route::delete('me', [ProfileController::class, 'destroy'])->middleware('throttle:5,1')->name('me.destroy');

        Route::middleware(EnsureMobileEmailVerified::class)->group(function (): void {
            Route::get('inventory', [InventoryController::class, 'index'])->name('inventory.index');
            Route::get('technician/performance', TechnicianPerformanceController::class)->name('technician.performance');
            Route::post('assistant/messages', [AssistantMessageController::class, 'store'])->middleware('throttle:assistant')->name('assistant.messages.store');
            Route::post('assistant/actions/{proposal}/confirm', [AssistantActionController::class, 'confirm'])->middleware('throttle:assistant')->name('assistant.actions.confirm');
            Route::post('assistant/actions/{proposal}/cancel', [AssistantActionController::class, 'cancel'])->middleware('throttle:assistant')->name('assistant.actions.cancel');
        });
    });
});
