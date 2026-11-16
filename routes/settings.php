<?php

use App\Http\Controllers\Settings\AdminControlCenterController;
use App\Http\Controllers\Settings\AiProviderController;
use App\Http\Controllers\Settings\AssistantSettingsController;
use App\Http\Controllers\Settings\IntegrationSettingsController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\ServiceManagementController;
use App\Http\Controllers\Settings\SystemSettingsController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::inertia('settings', 'settings/index')->name('settings.index');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('settings/admin', AdminControlCenterController::class)->name('admin.control-center');
    Route::get('settings/assistant', [AssistantSettingsController::class, 'edit'])->name('assistant-settings.edit');
    Route::put('settings/assistant', [AssistantSettingsController::class, 'update'])->name('assistant-settings.update');
    Route::post('settings/assistant/test', [AssistantSettingsController::class, 'test'])
        ->middleware('throttle:10,1')
        ->name('assistant-settings.test');

    Route::get('settings/services', [ServiceManagementController::class, 'index'])->name('services-settings.index');
    Route::post('settings/services', [ServiceManagementController::class, 'store'])->name('services-settings.store');
    Route::patch('settings/services/{service}', [ServiceManagementController::class, 'update'])->name('services-settings.update');

    Route::get('settings/system', [SystemSettingsController::class, 'edit'])->name('system-settings.edit');
    Route::put('settings/system', [SystemSettingsController::class, 'update'])->name('system-settings.update');

    Route::get('settings/integrations', [IntegrationSettingsController::class, 'edit'])->name('integrations-settings.edit');
    Route::put('settings/integrations/fonnte', [IntegrationSettingsController::class, 'updateFonnte'])->name('integrations-settings.fonnte.update');
    Route::post('settings/integrations/fonnte/test', [IntegrationSettingsController::class, 'testFonnte'])
        ->middleware('throttle:10,1')
        ->name('integrations-settings.fonnte.test');
    Route::post('settings/integrations/fonnte/send-test', [IntegrationSettingsController::class, 'sendFonnteTestMessage'])
        ->middleware('throttle:6,1')
        ->name('integrations-settings.fonnte.send-test');
    Route::put('settings/integrations/openai', [IntegrationSettingsController::class, 'updateOpenAi'])->name('integrations-settings.openai.update');
    Route::post('settings/integrations/openai/test', [IntegrationSettingsController::class, 'testOpenAi'])
        ->middleware('throttle:10,1')
        ->name('integrations-settings.openai.test');
    Route::put('settings/integrations/notifications', [IntegrationSettingsController::class, 'updateNotificationConfig'])
        ->name('integrations-settings.notifications.update');

    Route::get('settings/ai-providers', [AiProviderController::class, 'index'])->name('ai-providers.index');
    Route::post('settings/ai-providers', [AiProviderController::class, 'store'])->name('ai-providers.store');
    Route::patch('settings/ai-providers/{aiProvider}', [AiProviderController::class, 'update'])->name('ai-providers.update');
    Route::post('settings/ai-providers/{aiProvider}/test', [AiProviderController::class, 'test'])
        ->middleware('throttle:20,1')
        ->name('ai-providers.test');
    Route::post('settings/ai-providers/{aiProvider}/discover', [AiProviderController::class, 'discover'])
        ->middleware('throttle:15,1')
        ->name('ai-providers.discover');
    Route::post('settings/ai-providers/{aiProvider}/validate-model', [AiProviderController::class, 'validateManualModel'])
        ->middleware('throttle:15,1')
        ->name('ai-providers.validate-model');
    Route::post('settings/ai-providers/{aiProvider}/activate', [AiProviderController::class, 'activate'])
        ->name('ai-providers.activate');
    Route::delete('settings/ai-providers/{aiProvider}', [AiProviderController::class, 'destroy'])
        ->name('ai-providers.destroy');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::post('settings/password/otp', [SecurityController::class, 'sendPasswordOtp'])
        ->middleware('throttle:5,1')
        ->name('user-password.otp');

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');
    Route::inertia('settings/liquid-glass', 'settings/liquid-glass')->name('liquid-glass.playground');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
