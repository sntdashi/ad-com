<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreQuotationRequest;
use App\Models\Estimate;
use App\Models\InventoryItem;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderSparePart;
use App\Services\Finance\InvoiceAndPaymentService;
use App\Services\Finance\QuotationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class QuotationController extends Controller
{
    public function __construct(
        private readonly QuotationService $quotations,
        private readonly InvoiceAndPaymentService $invoices,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Estimate::class);

        $this->quotations->expireOverdueQuotations();

        $user = $request->user();
        $isInternal = in_array($user->role, ['operator', 'admin', 'supervisor'], true);
        $search = trim((string) $request->string('q', ''));
        $statusFilter = trim((string) $request->string('status', ''));

        $query = Estimate::query()
            ->with([
                'order:id,ticket_number,customer_id,customer_name,phone,email,status',
                'customer:id,name,email',
                'invoice:id,estimate_id,invoice_number,status',
            ])
            ->latest('created_at');

        if (! $isInternal) {
            $query->where(function ($builder) use ($user): void {
                $builder->where('customer_id', $user->id)
                    ->orWhereHas('order', fn ($orderQuery) => $orderQuery->where('customer_id', $user->id));
            });
        }

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder->where('quotation_number', 'like', "%{$search}%")
                    ->orWhereHas('order', function ($orderQuery) use ($search): void {
                        $orderQuery->where('ticket_number', 'like', "%{$search}%")
                            ->orWhere('customer_name', 'like', "%{$search}%");
                    });
            });
        }

        if ($statusFilter !== '' && $statusFilter !== 'all') {
            $query->where('status', $statusFilter);
        }

        $paginated = $query->paginate(15)->withQueryString();
        $paginated->through(function (Estimate $estimate): array {
            $resolvedStatus = $estimate->resolvedStatus();

            return [
                'id' => $estimate->id,
                'quotation_number' => $estimate->quotation_number ?? ('QUO-v'.$estimate->version),
                'service_order_id' => $estimate->service_order_id,
                'ticket_number' => $estimate->order->ticket_number,
                'customer_name' => $estimate->order->customer_name,
                'version' => $estimate->version,
                'status' => $resolvedStatus,
                'subtotal' => $estimate->subtotal,
                'discount_amount' => $estimate->discount_amount,
                'tax_rate' => (string) $estimate->tax_rate,
                'tax_amount' => $estimate->tax_amount,
                'total' => $estimate->total,
                'valid_until' => $estimate->valid_until?->toDateString(),
                'valid_until_label' => $estimate->valid_until?->translatedFormat('d M Y'),
                'sent_at' => $estimate->sent_at?->translatedFormat('d M Y, H.i'),
                'approved_at' => $estimate->approved_at?->translatedFormat('d M Y, H.i'),
                'rejected_at' => $estimate->rejected_at?->translatedFormat('d M Y, H.i'),
                'invoice' => $estimate->invoice !== null ? [
                    'id' => $estimate->invoice->id,
                    'invoice_number' => $estimate->invoice->invoice_number,
                    'status' => $estimate->invoice->resolvedStatus(),
                ] : null,
                'can_approve' => $resolvedStatus === 'sent' && Gate::allows('approve', $estimate),
                'can_convert' => $resolvedStatus === 'approved' && $estimate->invoice === null && Gate::allows('convert', $estimate),
            ];
        });

        $canCreate = Gate::allows('create', Estimate::class);

        return Inertia::render('finance/quotations/index', [
            'role' => $user->role,
            'isInternal' => $isInternal,
            'canCreate' => $canCreate,
            'filters' => [
                'q' => $search,
                'status' => $statusFilter !== '' ? $statusFilter : 'all',
            ],
            'defaults' => $this->quotations->defaults(),
            'quotations' => $paginated,
            'orders' => $canCreate
                ? ServiceOrder::query()
                    ->with(['spareParts' => fn ($q) => $q->with('item:id,name,sku,unit_cost,selling_price')])
                    ->latest()
                    ->limit(80)
                    ->get(['id', 'ticket_number', 'customer_id', 'customer_name', 'status'])
                    ->map(fn (ServiceOrder $order): array => [
                        'id' => $order->id,
                        'ticket_number' => $order->ticket_number,
                        'customer_name' => $order->customer_name,
                        'status' => $order->status,
                        'spare_parts' => $order->spareParts
                            ->whereIn('status', ['issued', 'partially_returned', 'approved', 'requested'])
                            ->values()
                            ->map(fn (ServiceOrderSparePart $part): array => [
                                'id' => $part->id,
                                'inventory_item_id' => $part->inventory_item_id,
                                'label' => $part->item->name,
                                'quantity' => max(1, $part->issued_quantity > 0 ? ($part->issued_quantity - $part->returned_quantity) : $part->requested_quantity),
                                'unit_price' => $part->unit_price > 0 ? $part->unit_price : ($part->item->selling_price ?? $part->unit_cost),
                                'unit_cost' => $part->unit_cost,
                            ])
                            ->all(),
                    ])
                : [],
            'inventoryItems' => $canCreate
                ? InventoryItem::query()
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->get(['id', 'sku', 'name', 'unit', 'unit_cost', 'selling_price'])
                    ->map(fn (InventoryItem $item): array => [
                        'id' => $item->id,
                        'sku' => $item->sku,
                        'name' => $item->name,
                        'unit' => $item->unit,
                        'unit_cost' => $item->unit_cost,
                        'selling_price' => $item->selling_price ?? $item->unit_cost,
                    ])
                : [],
        ]);
    }

    public function store(StoreQuotationRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        /** @var ServiceOrder $order */
        $order = ServiceOrder::query()->findOrFail((string) $validated['service_order_id']);

        $estimate = $this->quotations->createQuotation($request->user(), $order, $validated);

        return redirect()
            ->route('finance.quotations.show', $estimate)
            ->with('success', "Penawaran {$estimate->quotation_number} berhasil dibuat.");
    }

    public function update(StoreQuotationRequest $request, Estimate $estimate): RedirectResponse
    {
        Gate::authorize('update', $estimate);

        $updated = $this->quotations->updateDraftQuotation($request->user(), $estimate, $request->validated());

        return redirect()
            ->route('finance.quotations.show', $updated)
            ->with('success', "Draf penawaran {$updated->quotation_number} diperbarui.");
    }

    public function show(Request $request, Estimate $estimate): Response
    {
        $estimate->load([
            'order:id,ticket_number,customer_id,customer_name,phone,email,status',
            'customer:id,name,email,phone',
            'creator:id,name',
            'approver:id,name',
            'rejecter:id,name',
            'items.inventoryItem:id,sku,name,unit',
            'invoice:id,estimate_id,invoice_number,status,total_amount,paid_amount',
        ]);

        Gate::authorize('view', $estimate);

        $user = $request->user();
        $isInternal = in_array($user->role, ['operator', 'admin', 'supervisor'], true);
        $resolvedStatus = $estimate->resolvedStatus();

        $auditLogs = $isInternal
            ? DB::table('audit_logs')
                ->leftJoin('users', 'users.id', '=', 'audit_logs.actor_id')
                ->where('audit_logs.auditable_type', Estimate::class)
                ->where('audit_logs.auditable_id', $estimate->id)
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

        return Inertia::render('finance/quotations/show', [
            'role' => $user->role,
            'isInternal' => $isInternal,
            'quotation' => [
                'id' => $estimate->id,
                'quotation_number' => $estimate->quotation_number ?? ('QUO-v'.$estimate->version),
                'service_order_id' => $estimate->service_order_id,
                'ticket_number' => $estimate->order->ticket_number,
                'customer_name' => $estimate->order->customer_name,
                'customer_email' => $estimate->order->email ?? $estimate->customer?->email,
                'customer_phone' => $estimate->order->phone,
                'version' => $estimate->version,
                'status' => $resolvedStatus,
                'labor_total' => $estimate->labor_total,
                'spare_parts_total' => $estimate->spare_parts_total,
                'operational_total' => $estimate->operational_total,
                'subtotal' => $estimate->subtotal,
                'discount_amount' => $estimate->discount_amount,
                'tax_rate' => (string) $estimate->tax_rate,
                'tax_amount' => $estimate->tax_amount,
                'total' => $estimate->total,
                'notes' => $estimate->notes,
                'valid_until' => $estimate->valid_until?->toDateString(),
                'valid_until_label' => $estimate->valid_until?->translatedFormat('d M Y'),
                'sent_at' => $estimate->sent_at?->translatedFormat('d M Y, H.i'),
                'approved_at' => $estimate->approved_at?->translatedFormat('d M Y, H.i'),
                'approved_by_name' => $estimate->approver?->name,
                'rejected_at' => $estimate->rejected_at?->translatedFormat('d M Y, H.i'),
                'rejected_by_name' => $estimate->rejecter?->name,
                'rejection_reason' => $estimate->rejection_reason,
                'converted_at' => $estimate->converted_at?->translatedFormat('d M Y, H.i'),
                'created_by_name' => $estimate->creator->name ?? 'Sistem',
                'approved_snapshot' => $this->quotations->sanitizeSnapshotForRole($estimate->approved_snapshot, $user->role),
                'items' => $estimate->items->map(function ($item) use ($isInternal): array {
                    $row = [
                        'id' => $item->id,
                        'item_type' => $item->item_type,
                        'label' => $item->label,
                        'quantity' => (string) $item->quantity,
                        'unit_price' => $item->unit_price,
                        'total' => $item->total,
                        'sku' => $item->inventoryItem?->sku,
                    ];

                    if ($isInternal) {
                        $row['estimated_unit_cost'] = $item->estimated_unit_cost;
                    }

                    return $row;
                })->values()->all(),
                'invoice' => $estimate->invoice !== null ? [
                    'id' => $estimate->invoice->id,
                    'invoice_number' => $estimate->invoice->invoice_number,
                    'status' => $estimate->invoice->resolvedStatus(),
                    'total_amount' => $estimate->invoice->total_amount,
                    'paid_amount' => $estimate->invoice->paid_amount,
                ] : null,
            ],
            'permissions' => [
                'can_update' => $resolvedStatus === 'draft' && Gate::allows('update', $estimate),
                'can_send' => $resolvedStatus === 'draft' && Gate::allows('send', $estimate),
                'can_approve' => $resolvedStatus === 'sent' && Gate::allows('approve', $estimate),
                'can_reject' => $resolvedStatus === 'sent' && Gate::allows('reject', $estimate),
                'can_convert' => $resolvedStatus === 'approved' && $estimate->invoice === null && Gate::allows('convert', $estimate),
            ],
            'auditLogs' => $auditLogs,
        ]);
    }

    public function send(Request $request, Estimate $estimate): RedirectResponse
    {
        Gate::authorize('send', $estimate);

        $sent = $this->quotations->sendQuotation($request->user(), $estimate);

        return back()->with('success', "Penawaran {$sent->quotation_number} telah dikirim ke pelanggan.");
    }

    public function approve(Request $request, Estimate $estimate): RedirectResponse
    {
        $estimate->loadMissing('order');
        Gate::authorize('approve', $estimate);

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $approved = $this->quotations->approveQuotation($request->user(), $estimate, $validated['notes'] ?? null);

        return back()->with('success', "Penawaran {$approved->quotation_number} resmi disetujui dan snapshot terkunci.");
    }

    public function reject(Request $request, Estimate $estimate): RedirectResponse
    {
        $estimate->loadMissing('order');
        Gate::authorize('reject', $estimate);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $rejected = $this->quotations->rejectQuotation($request->user(), $estimate, $validated['reason']);

        return back()->with('success', "Penawaran {$rejected->quotation_number} ditolak.");
    }

    public function convertToInvoice(Request $request, Estimate $estimate): RedirectResponse
    {
        Gate::authorize('convert', $estimate);

        $validated = $request->validate([
            'issue_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'issue_now' => ['nullable', 'boolean'],
        ]);

        $invoice = $this->invoices->createFromApprovedQuotation($request->user(), $estimate, $validated);

        return redirect()
            ->route('finance.invoices.show', $invoice)
            ->with('success', "Tagihan {$invoice->invoice_number} berhasil dibuat dari penawaran {$estimate->quotation_number}.");
    }
}
