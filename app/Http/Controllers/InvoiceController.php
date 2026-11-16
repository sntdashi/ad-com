<?php

namespace App\Http\Controllers;

use App\Http\Requests\RecordInvoicePaymentRequest;
use App\Http\Requests\StoreInvoiceRequest;
use App\Models\FinancialTransaction;
use App\Models\InventoryItem;
use App\Models\Invoice;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\Finance\InvoiceAndPaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoiceController extends Controller
{
    public function __construct(
        private readonly InvoiceAndPaymentService $invoiceService,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Invoice::class);

        $user = $request->user();
        $isInternal = in_array($user->role, ['operator', 'admin', 'supervisor'], true);
        $search = trim((string) $request->string('q', ''));
        $statusFilter = trim((string) $request->string('status', ''));
        $startDate = trim((string) $request->string('start_date', ''));
        $endDate = trim((string) $request->string('end_date', ''));

        $query = Invoice::query()
            ->with([
                'order:id,ticket_number,customer_id',
                'estimate:id,quotation_number',
                'customer:id,name,email',
            ])
            ->latest('created_at');

        if (! $isInternal) {
            $query->where('status', '!=', 'draft')
                ->where(function ($builder) use ($user): void {
                    $builder->where('customer_id', $user->id)
                        ->orWhereHas('order', fn ($orderQuery) => $orderQuery->where('customer_id', $user->id));
                });
        }

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder->where('invoice_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhereHas('order', fn ($orderQuery) => $orderQuery->where('ticket_number', 'like', "%{$search}%"));
            });
        }

        if ($startDate !== '') {
            $query->whereDate('issue_date', '>=', $startDate);
        }
        if ($endDate !== '') {
            $query->whereDate('issue_date', '<=', $endDate);
        }

        if ($statusFilter !== '' && $statusFilter !== 'all') {
            if ($statusFilter === 'overdue') {
                $query->whereIn('status', ['issued', 'partially_paid', 'overdue'])
                    ->whereNotNull('due_date')
                    ->whereDate('due_date', '<', now()->toDateString());
            } elseif (in_array($statusFilter, ['issued', 'partially_paid'], true)) {
                $query->where('status', $statusFilter);
            } else {
                $query->where('status', $statusFilter);
            }
        }

        $paginated = $query->paginate(15)->withQueryString();
        $paginated->through(function (Invoice $invoice): array {
            $resolvedStatus = $invoice->resolvedStatus();

            return [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'service_order_id' => $invoice->service_order_id,
                'ticket_number' => $invoice->order?->ticket_number,
                'quotation_number' => $invoice->estimate?->quotation_number,
                'customer_name' => $invoice->customer_name,
                'status' => $resolvedStatus,
                'issue_date' => $invoice->issue_date?->translatedFormat('d M Y'),
                'due_date' => $invoice->due_date?->translatedFormat('d M Y'),
                'total_amount' => $invoice->total_amount,
                'paid_amount' => $invoice->paid_amount,
                'outstanding_balance' => $invoice->outstandingBalance(),
                'can_issue' => $resolvedStatus === 'draft' && Gate::allows('issue', $invoice),
                'can_record_payment' => in_array($resolvedStatus, ['issued', 'partially_paid', 'overdue'], true)
                    && Gate::allows('recordPayment', $invoice),
            ];
        });

        $canCreate = Gate::allows('create', Invoice::class);

        return Inertia::render('finance/invoices/index', [
            'role' => $user->role,
            'isInternal' => $isInternal,
            'canCreate' => $canCreate,
            'filters' => [
                'q' => $search,
                'status' => $statusFilter !== '' ? $statusFilter : 'all',
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'invoices' => $paginated,
            'paymentMethods' => $this->invoiceService->availablePaymentMethods(),
            'orders' => $canCreate
                ? ServiceOrder::query()->latest()->limit(80)->get(['id', 'ticket_number', 'customer_id', 'customer_name', 'email', 'phone'])
                : [],
            'customers' => $canCreate
                ? User::query()->where('role', 'customer')->where('is_active', true)->orderBy('name')->limit(100)->get(['id', 'name', 'email', 'phone'])
                : [],
            'inventoryItems' => $canCreate
                ? InventoryItem::query()->where('is_active', true)->orderBy('name')->get(['id', 'sku', 'name', 'unit_cost', 'selling_price'])
                    ->map(fn (InventoryItem $item): array => [
                        'id' => $item->id,
                        'sku' => $item->sku,
                        'name' => $item->name,
                        'unit_cost' => $item->unit_cost,
                        'selling_price' => $item->selling_price ?? $item->unit_cost,
                    ])
                : [],
        ]);
    }

    public function store(StoreInvoiceRequest $request): RedirectResponse
    {
        $invoice = $this->invoiceService->createManualInvoice($request->user(), $request->validated());

        return redirect()
            ->route('finance.invoices.show', $invoice)
            ->with('success', "Tagihan {$invoice->invoice_number} berhasil dibuat.");
    }

    public function show(Request $request, Invoice $invoice): Response
    {
        $invoice->load([
            'order:id,ticket_number,customer_id,customer_name,status',
            'estimate:id,quotation_number,version',
            'customer:id,name,email,phone',
            'creator:id,name',
            'issuer:id,name',
            'voider:id,name',
            'items.inventoryItem:id,sku,name,unit',
            'transactions' => fn ($q) => $q->with(['actor:id,name', 'verifier:id,name', 'rejecter:id,name']),
        ]);

        Gate::authorize('view', $invoice);

        $user = $request->user();
        $isInternal = in_array($user->role, ['operator', 'admin', 'supervisor'], true);
        $resolvedStatus = $invoice->resolvedStatus();
        $outstanding = $invoice->outstandingBalance();

        $auditLogs = $isInternal
            ? DB::table('audit_logs')
                ->leftJoin('users', 'users.id', '=', 'audit_logs.actor_id')
                ->where('audit_logs.auditable_type', Invoice::class)
                ->where('audit_logs.auditable_id', $invoice->id)
                ->orderByDesc('audit_logs.created_at')
                ->get(['audit_logs.id', 'audit_logs.event', 'audit_logs.after', 'audit_logs.created_at', 'users.name as actor_name'])
                ->map(fn (object $row): array => [
                    'id' => $row->id,
                    'event' => $row->event,
                    'actor_name' => $row->actor_name ?? 'Sistem',
                    'after' => is_string($row->after) ? json_decode($row->after, true) : null,
                    'created_at' => $row->created_at,
                ])
                ->values()
                ->all()
            : [];

        $pendingIncomeTotal = (int) $invoice->transactions
            ->where('type', 'income')
            ->where('status', 'pending')
            ->sum('amount');

        return Inertia::render('finance/invoices/show', [
            'role' => $user->role,
            'isInternal' => $isInternal,
            'paymentMethods' => $this->invoiceService->availablePaymentMethods(),
            'invoice' => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'service_order_id' => $invoice->service_order_id,
                'ticket_number' => $invoice->order?->ticket_number,
                'estimate_id' => $invoice->estimate_id,
                'quotation_number' => $invoice->estimate?->quotation_number,
                'customer_name' => $invoice->customer_name,
                'customer_email' => $invoice->customer_email,
                'customer_phone' => $invoice->customer_phone,
                'status' => $resolvedStatus,
                'issue_date' => $invoice->issue_date?->toDateString(),
                'issue_date_label' => $invoice->issue_date?->translatedFormat('d M Y'),
                'due_date' => $invoice->due_date?->toDateString(),
                'due_date_label' => $invoice->due_date?->translatedFormat('d M Y'),
                'subtotal' => $invoice->subtotal,
                'discount_amount' => $invoice->discount_amount,
                'tax_rate' => (string) $invoice->tax_rate,
                'tax_amount' => $invoice->tax_amount,
                'total_amount' => $invoice->total_amount,
                'paid_amount' => $invoice->paid_amount,
                'pending_amount' => $pendingIncomeTotal,
                'outstanding_balance' => $outstanding,
                'notes' => $invoice->notes,
                'issued_at' => $invoice->issued_at?->translatedFormat('d M Y, H.i'),
                'issued_by_name' => $invoice->issuer?->name,
                'paid_at' => $invoice->paid_at?->translatedFormat('d M Y, H.i'),
                'cancelled_at' => $invoice->cancelled_at?->translatedFormat('d M Y, H.i'),
                'voided_by_name' => $invoice->voider?->name,
                'cancellation_reason' => $invoice->cancellation_reason,
                'issued_snapshot' => $this->invoiceService->sanitizeSnapshotForRole($invoice->issued_snapshot, $user->role),
                'items' => $invoice->items->map(function ($item) use ($isInternal): array {
                    $row = [
                        'id' => $item->id,
                        'item_type' => $item->item_type,
                        'label' => $item->label,
                        'description' => $item->description,
                        'quantity' => (string) $item->quantity,
                        'unit_price' => $item->unit_price,
                        'line_total' => $item->line_total,
                        'sku' => $item->inventoryItem?->sku,
                    ];

                    if ($isInternal) {
                        $row['unit_cost'] = $item->unit_cost;
                    }

                    return $row;
                })->values()->all(),
                'payments' => $invoice->transactions->map(fn (FinancialTransaction $tx): array => [
                    'id' => $tx->id,
                    'amount' => $tx->amount,
                    'payment_method' => $tx->payment_method,
                    'status' => $tx->status,
                    'reference' => $tx->reference,
                    'description' => $tx->description,
                    'occurred_at' => $tx->occurred_at->translatedFormat('d M Y, H.i'),
                    'actor_name' => $tx->actor !== null ? $tx->actor->name : 'Sistem',
                    'verifier_name' => $tx->verifier?->name,
                    'verified_at' => $tx->verified_at?->translatedFormat('d M Y, H.i'),
                    'rejecter_name' => $tx->rejecter?->name,
                    'rejected_at' => $tx->rejected_at?->translatedFormat('d M Y, H.i'),
                    'rejection_reason' => $tx->rejection_reason,
                    'proof_url' => $tx->proof_path !== null && Gate::allows('view', $tx)
                        ? route('finance.transactions.proof', $tx)
                        : null,
                    'can_verify' => Gate::allows('verify', $tx),
                    'can_reject' => Gate::allows('reject', $tx),
                ])->values()->all(),
            ],
            'permissions' => [
                'can_issue' => $resolvedStatus === 'draft' && Gate::allows('issue', $invoice),
                'can_record_payment' => in_array($resolvedStatus, ['issued', 'partially_paid', 'overdue'], true)
                    && Gate::allows('recordPayment', $invoice),
                'can_cancel' => $resolvedStatus !== 'cancelled' && $invoice->paid_amount === 0 && Gate::allows('cancel', $invoice),
                'can_verify_immediately' => $user->role === 'operator',
            ],
            'auditLogs' => $auditLogs,
        ]);
    }

    public function issue(Request $request, Invoice $invoice): RedirectResponse
    {
        Gate::authorize('issue', $invoice);

        $validated = $request->validate([
            'issue_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
        ]);

        $issued = $this->invoiceService->issueInvoice(
            $request->user(),
            $invoice,
            $validated['issue_date'] ?? null,
            $validated['due_date'] ?? null,
        );

        return back()->with('success', "Tagihan {$issued->invoice_number} resmi diterbitkan dan item terkunci.");
    }

    public function recordPayment(RecordInvoicePaymentRequest $request, Invoice $invoice): RedirectResponse
    {
        $payload = $request->validated();
        if ($request->hasFile('proof')) {
            $payload['proof'] = $request->file('proof');
        }

        $tx = $this->invoiceService->recordInvoicePayment($request->user(), $invoice, $payload);
        $message = $tx->status === 'verified'
            ? 'Pembayaran dicatat dan langsung terverifikasi.'
            : 'Pembayaran dicatat dan menunggu verifikasi operator.';

        return back()->with('success', $message);
    }

    public function cancel(Request $request, Invoice $invoice): RedirectResponse
    {
        Gate::authorize('cancel', $invoice);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $cancelled = $this->invoiceService->cancelInvoice($request->user(), $invoice, $validated['reason']);

        return back()->with('success', "Tagihan {$cancelled->invoice_number} berhasil dibatalkan.");
    }

    public function rejectTransaction(Request $request, FinancialTransaction $financialTransaction): RedirectResponse
    {
        Gate::authorize('reject', $financialTransaction);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $this->invoiceService->rejectTransaction($request->user(), $financialTransaction, $validated['reason']);

        return back()->with('success', 'Transaksi ditolak dan dicatat dalam audit.');
    }

    public function paymentProof(FinancialTransaction $financialTransaction): StreamedResponse
    {
        $financialTransaction->loadMissing(['invoice', 'order']);
        Gate::authorize('view', $financialTransaction);

        if ($financialTransaction->proof_path === null || ! Storage::disk('local')->exists($financialTransaction->proof_path)) {
            abort(404);
        }

        return Storage::disk('local')->response($financialTransaction->proof_path);
    }
}
