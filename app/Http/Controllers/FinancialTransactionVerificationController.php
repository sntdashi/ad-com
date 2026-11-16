<?php

namespace App\Http\Controllers;

use App\Models\FinancialTransaction;
use App\Services\Finance\InvoiceAndPaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class FinancialTransactionVerificationController extends Controller
{
    public function __construct(
        private readonly InvoiceAndPaymentService $invoiceService,
    ) {}

    public function update(Request $request, FinancialTransaction $financialTransaction): RedirectResponse
    {
        Gate::authorize('verify', $financialTransaction);

        $this->invoiceService->verifyTransaction($request->user(), $financialTransaction);

        return back()->with('success', 'Transaksi berhasil diverifikasi.');
    }
}
