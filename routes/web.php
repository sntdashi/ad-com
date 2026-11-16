<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AssistantActionController;
use App\Http\Controllers\AssistantMessageController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\CustomerDeviceController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\FinancialTransactionVerificationController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\InventoryMovementController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OrderChecklistController;
use App\Http\Controllers\OrderManagementController;
use App\Http\Controllers\OrderMessageController;
use App\Http\Controllers\OrderSlaController;
use App\Http\Controllers\OrderTeamController;
use App\Http\Controllers\OrderWorkEvidenceController;
use App\Http\Controllers\OrderWorkSessionController;
use App\Http\Controllers\OtpController;
use App\Http\Controllers\Public\BookingController;
use App\Http\Controllers\Public\TrackOrderController;
use App\Http\Controllers\QuotationController;
use App\Http\Controllers\ServiceOrderSparePartController;
use App\Http\Controllers\TeamManagementController;
use App\Http\Controllers\TechnicianAvailabilityController;
use App\Http\Controllers\TechnicianPerformanceController;
use App\Http\Controllers\TechnicianReviewController;
use App\Http\Controllers\WarrantyController;
use App\Http\Controllers\WorkforceController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');
Route::inertia('/privasi', 'legal', ['kind' => 'privacy'])->name('privacy');
Route::inertia('/syarat-layanan', 'legal', ['kind' => 'terms'])->name('terms');
Route::get('/pesan', [BookingController::class, 'create'])->name('booking.create');
Route::post('/pesan', [BookingController::class, 'store'])->middleware('throttle:10,1')->name('booking.store');
Route::get('/lacak', [TrackOrderController::class, 'index'])->name('track.index');
Route::post('/lacak', [TrackOrderController::class, 'lookup'])->middleware('throttle:10,1')->name('track.lookup');
Route::get('/lacak/t/{token}', [TrackOrderController::class, 'token'])->middleware('throttle:30,1')->name('track.token');
Route::get('/lacak/p/{order}', [TrackOrderController::class, 'signed'])->name('track.signed');
Route::post('/assistant/messages', [AssistantMessageController::class, 'store'])
    ->middleware('throttle:assistant')
    ->name('assistant.messages.store');

Route::middleware(['guest', 'throttle:10,1'])
    ->prefix('auth/google')
    ->name('auth.google.')
    ->controller(GoogleAuthController::class)
    ->group(function () {
        Route::get('redirect', 'redirect')->name('redirect');
        Route::get('callback', 'callback')->name('callback');
    });

Route::middleware(['auth', 'verified'])->group(function () {
    Route::post('assistant/actions/{proposal}/confirm', [AssistantActionController::class, 'confirm'])
        ->middleware('throttle:assistant')
        ->name('assistant.actions.confirm');
    Route::post('assistant/actions/{proposal}/cancel', [AssistantActionController::class, 'cancel'])
        ->middleware('throttle:assistant')
        ->name('assistant.actions.cancel');
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('orders/{order}', [OrderManagementController::class, 'show'])->name('orders.show');
    Route::patch('orders/{order}/checklist/{checklistTemplate}', [OrderChecklistController::class, 'update'])
        ->name('orders.checklist.update');
    Route::patch('orders/{order}/status', [OrderManagementController::class, 'updateStatus'])->name('orders.status.update');
    Route::patch('orders/{order}/sla', [OrderSlaController::class, 'update'])->name('orders.sla.update');
    Route::patch('orders/{order}/team', [OrderTeamController::class, 'update'])->name('orders.team.update');
    Route::post('orders/{order}/work-sessions', [OrderWorkSessionController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('orders.work-sessions.store');
    Route::patch('orders/{order}/work-sessions/{workSession}', [OrderWorkSessionController::class, 'update'])
        ->middleware('throttle:30,1')
        ->name('orders.work-sessions.update');
    Route::post('orders/{order}/evidences', [OrderWorkEvidenceController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('orders.evidences.store');
    Route::get('orders/{order}/evidences/{workEvidence}/file', [OrderWorkEvidenceController::class, 'file'])
        ->name('orders.evidences.file');
    Route::patch('orders/{order}/evidences/{workEvidence}/confirm', [OrderWorkEvidenceController::class, 'confirm'])
        ->name('orders.evidences.confirm');

    Route::get('workforce', [WorkforceController::class, 'index'])->name('workforce.index');
    Route::patch('technicians/{user}/availability', [TechnicianAvailabilityController::class, 'update'])
        ->middleware('throttle:30,1')
        ->name('technicians.availability.update');
    Route::post('orders/{order}/messages', [OrderMessageController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('orders.messages.store');
    Route::post('orders/{order}/technician-reviews', [TechnicianReviewController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('orders.technician-reviews.store');
    Route::get('orders', [OrderManagementController::class, 'index'])->name('orders.index');

    Route::get('inventory', [InventoryController::class, 'index'])->name('inventory.index');
    Route::post('inventory', [InventoryController::class, 'store'])->name('inventory.store');
    Route::patch('inventory/{inventoryItem}', [InventoryController::class, 'update'])->name('inventory.update');
    Route::post('inventory/movements', [InventoryMovementController::class, 'store'])->name('inventory.movements.store');

    Route::post('orders/{order}/spare-parts', [ServiceOrderSparePartController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('orders.spare-parts.store');
    Route::patch('orders/{order}/spare-parts/{sparePart}/issue', [ServiceOrderSparePartController::class, 'issue'])
        ->middleware('throttle:30,1')
        ->name('orders.spare-parts.issue');
    Route::patch('orders/{order}/spare-parts/{sparePart}/return', [ServiceOrderSparePartController::class, 'returnPart'])
        ->middleware('throttle:30,1')
        ->name('orders.spare-parts.return');
    Route::patch('orders/{order}/spare-parts/{sparePart}/cancel', [ServiceOrderSparePartController::class, 'cancel'])
        ->middleware('throttle:30,1')
        ->name('orders.spare-parts.cancel');

    Route::get('devices', [CustomerDeviceController::class, 'index'])->name('devices.index');
    Route::post('devices', [CustomerDeviceController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('devices.store');
    Route::get('devices/{customerAsset}', [CustomerDeviceController::class, 'show'])->name('devices.show');
    Route::patch('devices/{customerAsset}', [CustomerDeviceController::class, 'update'])
        ->middleware('throttle:30,1')
        ->name('devices.update');
    Route::post('orders/{order}/device', [CustomerDeviceController::class, 'linkOrder'])
        ->middleware('throttle:30,1')
        ->name('orders.device.link');

    Route::get('warranties', [WarrantyController::class, 'index'])->name('warranties.index');
    Route::post('orders/{order}/warranties', [WarrantyController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('orders.warranties.store');
    Route::patch('warranties/{warranty}/void', [WarrantyController::class, 'void'])
        ->middleware('throttle:30,1')
        ->name('warranties.void');
    Route::post('warranties/{warranty}/claims', [WarrantyController::class, 'storeClaim'])
        ->middleware('throttle:20,1')
        ->name('warranties.claims.store');
    Route::patch('warranties/claims/{warrantyClaim}/review', [WarrantyController::class, 'reviewClaim'])
        ->middleware('throttle:30,1')
        ->name('warranties.claims.review');
    Route::patch('warranties/claims/{warrantyClaim}/resolve', [WarrantyController::class, 'resolveClaim'])
        ->middleware('throttle:30,1')
        ->name('warranties.claims.resolve');

    Route::get('finance', [FinanceController::class, 'index'])->name('finance.index');
    Route::post('finance', [FinanceController::class, 'store'])->name('finance.store');
    Route::patch('finance/transactions/{financialTransaction}/verify', [FinancialTransactionVerificationController::class, 'update'])
        ->name('finance.transactions.verify');
    Route::patch('finance/transactions/{financialTransaction}/reject', [InvoiceController::class, 'rejectTransaction'])
        ->name('finance.transactions.reject');
    Route::get('finance/transactions/{financialTransaction}/proof', [InvoiceController::class, 'paymentProof'])
        ->name('finance.transactions.proof');

    Route::get('finance/quotations', [QuotationController::class, 'index'])->name('finance.quotations.index');
    Route::post('finance/quotations', [QuotationController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('finance.quotations.store');
    Route::get('finance/quotations/{estimate}', [QuotationController::class, 'show'])->name('finance.quotations.show');
    Route::put('finance/quotations/{estimate}', [QuotationController::class, 'update'])
        ->middleware('throttle:30,1')
        ->name('finance.quotations.update');
    Route::post('finance/quotations/{estimate}/send', [QuotationController::class, 'send'])
        ->middleware('throttle:30,1')
        ->name('finance.quotations.send');
    Route::post('finance/quotations/{estimate}/approve', [QuotationController::class, 'approve'])
        ->middleware('throttle:30,1')
        ->name('finance.quotations.approve');
    Route::post('finance/quotations/{estimate}/reject', [QuotationController::class, 'reject'])
        ->middleware('throttle:30,1')
        ->name('finance.quotations.reject');
    Route::post('finance/quotations/{estimate}/convert-to-invoice', [QuotationController::class, 'convertToInvoice'])
        ->middleware('throttle:30,1')
        ->name('finance.quotations.convert');

    Route::get('finance/invoices', [InvoiceController::class, 'index'])->name('finance.invoices.index');
    Route::post('finance/invoices', [InvoiceController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('finance.invoices.store');
    Route::get('finance/invoices/{invoice}', [InvoiceController::class, 'show'])->name('finance.invoices.show');
    Route::post('finance/invoices/{invoice}/issue', [InvoiceController::class, 'issue'])
        ->middleware('throttle:30,1')
        ->name('finance.invoices.issue');
    Route::post('finance/invoices/{invoice}/payments', [InvoiceController::class, 'recordPayment'])
        ->middleware('throttle:30,1')
        ->name('finance.invoices.payments.store');
    Route::post('finance/invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])
        ->middleware('throttle:30,1')
        ->name('finance.invoices.cancel');

    Route::get('team', [TeamManagementController::class, 'index'])->name('team.index');
    Route::post('team', [TeamManagementController::class, 'store'])->name('team.store');
    Route::patch('team/{user}', [TeamManagementController::class, 'update'])->name('team.update');
    Route::get('technicians/performance', TechnicianPerformanceController::class)
        ->name('technicians.performance');

    Route::get('analytics', [AnalyticsController::class, 'index'])
        ->name('analytics.index');
    Route::get('analytics/export', [AnalyticsController::class, 'export'])
        ->middleware('throttle:30,1')
        ->name('analytics.export');

    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::patch('notifications/read-all', [NotificationController::class, 'markAllRead'])
        ->middleware('throttle:30,1')
        ->name('notifications.read-all');
    Route::put('notifications/preferences', [NotificationController::class, 'updatePreferences'])
        ->middleware('throttle:20,1')
        ->name('notifications.preferences.update');
    Route::patch('notifications/{notificationId}/read', [NotificationController::class, 'markAsRead'])
        ->middleware('throttle:60,1')
        ->name('notifications.read');
    Route::patch('notifications/{notificationId}/archive', [NotificationController::class, 'archive'])
        ->middleware('throttle:60,1')
        ->name('notifications.archive');
    Route::delete('notifications/{notificationId}', [NotificationController::class, 'destroy'])
        ->middleware('throttle:60,1')
        ->name('notifications.destroy');
    Route::post('notifications/deliveries/{delivery}/retry', [NotificationController::class, 'retryDelivery'])
        ->middleware('throttle:20,1')
        ->name('notifications.deliveries.retry');
});

Route::post('otp/challenges', [OtpController::class, 'issue'])
    ->middleware('throttle:15,1')
    ->name('otp.issue');
Route::post('otp/challenges/{challengeId}/resend', [OtpController::class, 'resend'])
    ->middleware('throttle:10,1')
    ->name('otp.resend');
Route::post('otp/challenges/{challengeId}/verify', [OtpController::class, 'verify'])
    ->middleware('throttle:20,1')
    ->name('otp.verify');

require __DIR__.'/settings.php';
