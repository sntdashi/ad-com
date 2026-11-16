<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFinancialTransactionRequest;
use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\ServiceOrder;
use App\Services\Finance\FinancialReportService;
use App\Services\Finance\InvoiceAndPaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class FinanceController extends Controller
{
    public function __construct(
        private readonly FinancialReportService $reports,
        private readonly InvoiceAndPaymentService $invoiceService,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', FinancialTransaction::class);

        $user = $request->user();
        $startDate = trim((string) $request->string('start_date', ''));
        $endDate = trim((string) $request->string('end_date', ''));
        $typeFilter = trim((string) $request->string('type', ''));
        $statusFilter = trim((string) $request->string('status', ''));
        $search = trim((string) $request->string('q', ''));

        $summary = $this->reports->summary(
            $startDate !== '' ? $startDate : null,
            $endDate !== '' ? $endDate : null,
        );

        $txQuery = FinancialTransaction::query()
            ->with([
                'order:id,ticket_number,customer_name',
                'invoice:id,invoice_number',
                'actor:id,name',
                'verifier:id,name',
                'rejecter:id,name',
            ])
            ->latest('occurred_at')
            ->latest('id');

        if ($startDate !== '') {
            $txQuery->whereDate('occurred_at', '>=', $startDate);
        }
        if ($endDate !== '') {
            $txQuery->whereDate('occurred_at', '<=', $endDate);
        }
        if ($typeFilter !== '' && $typeFilter !== 'all') {
            $txQuery->where('type', $typeFilter);
        }
        if ($statusFilter !== '' && $statusFilter !== 'all') {
            $txQuery->where('status', $statusFilter);
        }
        if ($search !== '') {
            $txQuery->where(function ($builder) use ($search): void {
                $builder->where('reference', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%")
                    ->orWhereHas('order', fn ($orderQuery) => $orderQuery
                        ->where('ticket_number', 'like', "%{$search}%")
                        ->orWhere('customer_name', 'like', "%{$search}%"))
                    ->orWhereHas('invoice', fn ($invQuery) => $invQuery
                        ->where('invoice_number', 'like', "%{$search}%"));
            });
        }

        $transactions = $txQuery->paginate(15)->withQueryString();
        $transactions->through(fn (FinancialTransaction $transaction): array => [
            'id' => $transaction->id,
            'type' => $transaction->type,
            'category' => $transaction->category,
            'amount' => $transaction->amount,
            'payment_method' => $transaction->payment_method,
            'status' => $transaction->status,
            'reference' => $transaction->reference,
            'description' => $transaction->description,
            'invoice_id' => $transaction->invoice_id,
            'invoice_number' => $transaction->invoice?->invoice_number,
            'ticket_number' => $transaction->order?->ticket_number,
            'customer_name' => $transaction->order?->customer_name,
            'actor' => $transaction->actor_id === null ? 'Sistem' : $transaction->actor->name,
            'verifier' => $transaction->verifier?->name,
            'rejecter' => $transaction->rejecter?->name,
            'rejection_reason' => $transaction->rejection_reason,
            'proof_url' => $transaction->proof_path !== null ? route('finance.transactions.proof', $transaction) : null,
            'occurred_at' => $transaction->occurred_at->translatedFormat('d M Y, H.i'),
            'can_verify' => Gate::allows('verify', $transaction),
            'can_reject' => Gate::allows('reject', $transaction),
        ]);

        $canCreate = Gate::allows('create', FinancialTransaction::class);

        return Inertia::render('operations/finance', [
            'role' => $user->role,
            'canCreate' => $canCreate,
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'type' => $typeFilter !== '' ? $typeFilter : 'all',
                'status' => $statusFilter !== '' ? $statusFilter : 'all',
                'q' => $search,
            ],
            'stats' => [
                'income' => $summary['cash_received'],
                'expense' => $summary['verified_expenses_total'],
                'balance' => $summary['net_cash_flow'],
                'pending' => FinancialTransaction::query()->where('status', 'pending')->count(),
            ],
            'summary' => $summary,
            'orderProfitability' => $this->reports->orderProfitabilityReport(
                $startDate !== '' ? $startDate : null,
                $endDate !== '' ? $endDate : null,
                15,
            )->values()->all(),
            'paymentMethods' => $this->invoiceService->availablePaymentMethods(),
            'transactions' => $transactions,
            'orders' => $canCreate
                ? ServiceOrder::query()->latest()->limit(100)->get(['id', 'ticket_number', 'customer_name'])
                : [],
            'openInvoices' => $canCreate
                ? Invoice::query()
                    ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
                    ->latest('issued_at')
                    ->limit(80)
                    ->get(['id', 'invoice_number', 'service_order_id', 'customer_name', 'total_amount', 'paid_amount'])
                    ->map(fn (Invoice $invoice): array => [
                        'id' => $invoice->id,
                        'invoice_number' => $invoice->invoice_number,
                        'service_order_id' => $invoice->service_order_id,
                        'customer_name' => $invoice->customer_name,
                        'outstanding_balance' => $invoice->outstandingBalance(),
                    ])
                : [],
        ]);
    }

    public function store(StoreFinancialTransactionRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        if (! empty($validated['invoice_id']) && $validated['type'] === 'income') {
            $invoice = Invoice::query()->findOrFail((string) $validated['invoice_id']);
            if ($request->hasFile('proof')) {
                $validated['proof'] = $request->file('proof');
            }

            $this->invoiceService->recordInvoicePayment($request->user(), $invoice, $validated);

            return back()->with('success', 'Pembayaran tagihan dicatat dan menunggu verifikasi operator.');
        }

        $idempotencyKey = isset($validated['idempotency_key']) && trim((string) $validated['idempotency_key']) !== ''
            ? trim((string) $validated['idempotency_key'])
            : null;

        if ($idempotencyKey !== null && FinancialTransaction::query()->where('idempotency_key', $idempotencyKey)->exists()) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'Transaksi duplikat terdeteksi berdasarkan kunci idempotensi.',
            ]);
        }

        $proofPath = $request->hasFile('proof')
            ? $request->file('proof')?->store('finance-proofs', 'local')
            : null;

        DB::transaction(function () use ($request, $validated, $idempotencyKey, $proofPath): void {
            unset($validated['proof']);

            $transaction = FinancialTransaction::query()->create([
                ...$validated,
                'idempotency_key' => $idempotencyKey,
                'proof_path' => $proofPath ?: null,
                'actor_id' => $request->user()->id,
                'status' => 'pending',
            ]);

            DB::table('audit_logs')->insert([
                'actor_id' => $request->user()->id,
                'event' => 'finance.transaction_recorded',
                'auditable_type' => FinancialTransaction::class,
                'auditable_id' => (string) $transaction->id,
                'before' => null,
                'after' => json_encode(['type' => $transaction->type, 'amount' => $transaction->amount, 'status' => 'pending']),
                'ip_address' => $request->ip(),
                'created_at' => now(),
            ]);
        });

        return back()->with('success', 'Transaksi dicatat dan menunggu verifikasi operator.');
    }
}
