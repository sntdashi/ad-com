<?php

namespace App\Http\Controllers;

use App\Actions\Orders\UpdateOrderStatus;
use App\Models\ChecklistTemplate;
use App\Models\CustomerAsset;
use App\Models\InventoryItem;
use App\Models\OrderWorkEvidence;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderSparePart;
use App\Models\TechnicianWorkSession;
use App\Models\User;
use App\Models\Warranty;
use App\Models\WarrantyClaim;
use App\Services\CustomerHistory\CustomerDeviceService;
use App\Services\Finance\FinancialReportService;
use App\Services\Sla\SlaConfig;
use App\Services\Sla\SlaService;
use App\Services\Workforce\WorkforceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OrderManagementController extends Controller
{
    /** @var array<string, string> */
    private const QUEUE_GROUPS = [
        'active' => 'Tiket aktif',
        'waiting' => 'Butuh aksi',
        'scheduled' => 'Terjadwal',
        'done' => 'Tuntas',
        'unassigned' => 'Tiket tanpa PIC',
        'high_risk' => 'Risiko tinggi aktif',
        'sla_breached' => 'SLA terlewati',
        'sla_approaching' => 'Mendekati batas SLA',
        'sla_paused' => 'SLA dijeda',
    ];

    /**
     * @param  Builder<ServiceOrder>  $query
     * @return Builder<ServiceOrder>
     */
    private function applyQueueGroup($query, string $group)
    {
        $open = ['completed', 'closed', 'cancelled'];
        $now = now();

        return match ($group) {
            'active' => $query->whereNotIn('status', $open),
            'waiting' => $query->whereIn('status', ['new', 'needs_info', 'waiting_approval']),
            'scheduled' => $query->whereIn('status', ['scheduled', 'on_the_way']),
            'done' => $query->whereIn('status', ['completed', 'warranty', 'closed']),
            'unassigned' => $query->whereNull('assigned_technician_id')->whereNotIn('status', $open),
            'high_risk' => $query->where('risk_level', 'high')->whereNotIn('status', $open),
            'sla_paused' => $query->whereNotIn('status', SlaService::TERMINAL_STATUSES)->whereNotNull('sla_paused_at'),
            'sla_breached' => $query->whereNotIn('status', SlaService::TERMINAL_STATUSES)
                ->whereNull('sla_paused_at')
                ->where(function (Builder $sub) use ($now): void {
                    $sub->where(function (Builder $r) use ($now): void {
                        $r->whereNull('first_responded_at')->where('response_due_at', '<', $now);
                    })->orWhere('resolution_due_at', '<', $now);
                }),
            'sla_approaching' => $query->whereNotIn('status', SlaService::TERMINAL_STATUSES)
                ->whereNull('sla_paused_at')
                ->where('resolution_due_at', '>=', $now),
            default => $query,
        };
    }

    public function index(Request $request, SlaService $slaService): Response
    {
        abort_unless(in_array($request->user()->role, ['operator', 'admin', 'supervisor'], true), 403);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'group' => ['nullable', Rule::in(array_keys(self::QUEUE_GROUPS))],
            'status' => ['nullable', Rule::in(['new', 'needs_info', 'triaged', 'waiting_estimate', 'waiting_approval', 'scheduled', 'on_the_way', 'in_progress', 'verification', 'waiting_payment', 'completed', 'warranty', 'reopened', 'closed', 'cancelled'])],
        ]);

        $now = now();
        $slaConfig = SlaConfig::get();

        $orders = ServiceOrder::query()
            ->with(['service:id,name,code', 'assignedTechnician:id,name', 'activeTechnicianAssignments.user:id,name'])
            ->when($filters['group'] ?? null, fn ($query, string $group) => $this->applyQueueGroup($query, $group))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($matches) use ($search): void {
                    $matches->where('ticket_number', 'like', "%{$search}%")
                        ->orWhere('customer_name', 'like', "%{$search}%")
                        ->orWhereHas('service', fn ($service) => $service->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('assignedTechnician', fn ($technician) => $technician->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('activeTechnicianAssignments.user', fn ($technician) => $technician->where('name', 'like', "%{$search}%"));
                });
            })
            ->latest()
            ->paginate(25)
            ->withQueryString()
            ->through(fn (ServiceOrder $order): array => [
                'id' => $order->id,
                'ticket_number' => $order->ticket_number,
                'customer_name' => $order->customer_name,
                'service' => $order->service->name,
                'status' => $order->status,
                'urgency' => $order->urgency,
                'risk_level' => $order->risk_level,
                'preferred_date' => $order->preferred_date?->format('d M Y'),
                'scheduled_at' => $order->scheduled_at?->translatedFormat('d M Y, H.i'),
                'estimated_total' => $order->estimated_total,
                'technicians' => $order->activeTechnicianAssignments
                    ->pluck('user.name')
                    ->filter()
                    ->whenEmpty(fn ($names) => $order->assignedTechnician ? $names->push($order->assignedTechnician->name) : $names)
                    ->values(),
                'href' => route('orders.show', $order),
                'sla' => $slaService->evaluate($order, $now, $slaConfig),
            ]);

        return Inertia::render('operations/orders', [
            'orders' => $orders,
            'sla_metrics' => $slaService->metricsForQuery(ServiceOrder::query(), $now),
            'filters' => [
                'search' => $filters['search'] ?? '',
                'status' => $filters['status'] ?? '',
                'group' => $filters['group'] ?? '',
                'group_label' => self::QUEUE_GROUPS[$filters['group'] ?? ''] ?? null,
            ],
            'statuses' => [
                ['value' => 'new', 'label' => 'Baru masuk'],
                ['value' => 'needs_info', 'label' => 'Perlu info'],
                ['value' => 'triaged', 'label' => 'Selesai triase'],
                ['value' => 'waiting_estimate', 'label' => 'Buat estimasi'],
                ['value' => 'waiting_approval', 'label' => 'Tunggu persetujuan'],
                ['value' => 'scheduled', 'label' => 'Terjadwal'],
                ['value' => 'on_the_way', 'label' => 'Menuju lokasi'],
                ['value' => 'in_progress', 'label' => 'Dikerjakan'],
                ['value' => 'verification', 'label' => 'Verifikasi'],
                ['value' => 'waiting_payment', 'label' => 'Tunggu bayar'],
                ['value' => 'completed', 'label' => 'Selesai'],
                ['value' => 'warranty', 'label' => 'Garansi'],
                ['value' => 'reopened', 'label' => 'Dibuka kembali'],
                ['value' => 'closed', 'label' => 'Ditutup'],
                ['value' => 'cancelled', 'label' => 'Batal'],
            ],
        ]);
    }

    public function show(Request $request, ServiceOrder $order, SlaService $slaService, CustomerDeviceService $deviceService): Response
    {
        Gate::authorize('view', $order);

        $order->load([
            'service.checklistTemplates',
            'device:id,customer_id,device_code,name,category,brand,model,serial_masked,location,status',
            'checklistResults.completedBy:id,name',
            'assignedTechnician:id,name,phone,avatar_url,specialty,bio,show_phone_publicly',
            'activeTechnicianAssignments.user:id,name,phone,avatar_url,specialty,show_phone_publicly',
            'assignments.user:id,name,avatar_url,specialty',
            'supervisor:id,name',
            'statusEvents.actor:id,name,role',
            'estimates.items',
            'estimates.invoice:id,estimate_id,invoice_number,status',
            'invoices',
            'messages.sender:id,name,role,avatar_url',
            'financialTransactions.actor:id,name',
            'financialTransactions.verifier:id,name',
            'technicianReviews.technician:id,name',
            'workSessions.technician:id,name',
            'workEvidences.uploader:id,name,role',
            'workEvidences.confirmer:id,name',
            'spareParts.item:id,sku,name,unit,quantity_on_hand,unit_cost,selling_price,warranty_days,warranty_terms,is_active',
            'spareParts.requester:id,name',
            'spareParts.approver:id,name',
            'spareParts.returner:id,name',
            'spareParts.warranty',
            'warranties.claims.claimOrder:id,ticket_number',
            'warranties.claims.submitter:id,name',
            'warranties.claims.reviewer:id,name',
            'warranties.claims.resolver:id,name',
        ]);

        $user = $request->user();
        $isOperations = in_array($user->role, ['operator', 'admin'], true);
        $isInternal = $user->role !== 'customer';
        $eligibleTechnicianIds = $order->assignments
            ->where('assignment_role', 'technician')
            ->pluck('user_id')
            ->when($order->assigned_technician_id !== null, fn ($ids) => $ids->push($order->assigned_technician_id))
            ->unique()
            ->values();
        $eligibleTechnicians = User::query()
            ->whereKey($eligibleTechnicianIds)
            ->withAvg('receivedTechnicianReviews as rating_average', 'rating')
            ->withCount('receivedTechnicianReviews')
            ->orderBy('name')
            ->get()
            ->keyBy('id');
        $reviewsByTechnician = $order->technicianReviews->keyBy('technician_id');
        $checklistResults = $order->checklistResults->keyBy('checklist_template_id');
        $visibleTransactions = $isOperations
            ? $order->financialTransactions
            : $order->financialTransactions->where('type', 'income');

        $slaSnapshot = $slaService->evaluate($order);

        $visibleSpareParts = ($isInternal
            ? $order->spareParts
            : $order->spareParts->filter(fn (ServiceOrderSparePart $sp): bool => $sp->netIssuedQuantity() > 0)
        )->values();

        $sparePartsCostTotal = (int) $order->spareParts
            ->filter(fn (ServiceOrderSparePart $sp): bool => $sp->netIssuedQuantity() > 0)
            ->sum(fn (ServiceOrderSparePart $sp): int => $sp->netIssuedQuantity() * $sp->unit_cost);
        $sparePartsChargeTotal = (int) $order->spareParts
            ->filter(fn (ServiceOrderSparePart $sp): bool => $sp->netIssuedQuantity() > 0)
            ->sum(fn (ServiceOrderSparePart $sp): int => $sp->netIssuedQuantity() * $sp->unit_price);

        $canRequestSpareParts = Gate::allows('requestForOrder', [ServiceOrderSparePart::class, $order])
            && ! in_array($order->status, SlaService::TERMINAL_STATUSES, true);
        $canManageInventoryCosts = in_array($user->role, ['operator', 'admin', 'warehouse'], true);

        return Inertia::render('orders/show', [
            'order' => [
                'id' => $order->id,
                'ticket_number' => $order->ticket_number,
                'status' => $order->status,
                'customer_name' => $order->customer_name,
                'phone' => $order->phone,
                'email' => $order->email,
                'address' => $order->address,
                'district' => $order->district,
                'complaint' => $order->complaint,
                'service' => $order->service->name,
                'service_code' => $order->service->code,
                'risk_level' => $order->risk_level,
                'urgency' => $order->urgency,
                'sla' => $user->role === 'customer'
                    ? [
                        'status' => $slaSnapshot['status'],
                        'label' => $slaSnapshot['label'],
                        'label_id' => $slaSnapshot['label_id'],
                        'response_due_formatted' => $slaSnapshot['response_due_formatted'],
                        'resolution_due_formatted' => $slaSnapshot['resolution_due_formatted'],
                        'first_responded_formatted' => $slaSnapshot['first_responded_formatted'],
                        'is_paused' => $slaSnapshot['is_paused'],
                        'pause_reason' => $slaSnapshot['pause_reason'],
                    ]
                    : $slaSnapshot,
                'work_sessions' => $isInternal
                    ? $order->workSessions->map(fn (TechnicianWorkSession $session): array => [
                        'id' => $session->id,
                        'technician_id' => $session->technician_id,
                        'technician' => $session->technician?->name,
                        'status' => $session->status,
                        'started_at' => $session->started_at->translatedFormat('d M Y, H.i'),
                        'paused_at' => $session->paused_at?->translatedFormat('d M Y, H.i'),
                        'resumed_at' => $session->resumed_at?->translatedFormat('d M Y, H.i'),
                        'completed_at' => $session->completed_at?->translatedFormat('d M Y, H.i'),
                        'paused_minutes' => (int) round($session->paused_seconds / 60),
                        'duration_minutes' => (int) round($session->duration_seconds / 60),
                        'notes' => $session->notes,
                    ])->values()
                    : [],
                'evidences' => $order->workEvidences->map(fn (OrderWorkEvidence $evidence): array => [
                    'id' => $evidence->id,
                    'stage' => $evidence->stage,
                    'caption' => $evidence->caption,
                    'has_file' => $evidence->file_path !== null,
                    'file_url' => $evidence->file_path !== null
                        ? route('orders.evidences.file', ['order' => $order->id, 'workEvidence' => $evidence->id])
                        : null,
                    'uploaded_by' => $evidence->uploader?->name,
                    'created_at' => $evidence->created_at?->translatedFormat('d M Y, H.i'),
                    'customer_confirmed_at' => $evidence->customer_confirmed_at?->translatedFormat('d M Y, H.i'),
                    'confirmation_note' => $evidence->confirmation_note,
                ])->values(),
                'preferred_date' => $order->preferred_date?->format('d M Y'),
                'preferred_window' => $order->preferred_window,
                'assigned_technician_id' => $order->assigned_technician_id,
                'technician' => $order->assignedTechnician ? [
                    'id' => $order->assignedTechnician->id,
                    'name' => $order->assignedTechnician->name,
                    'phone' => $order->assignedTechnician->show_phone_publicly || $user->role !== 'customer'
                        ? $order->assignedTechnician->phone
                        : null,
                    'avatar_url' => $order->assignedTechnician->avatar_url,
                    'specialty' => $order->assignedTechnician->specialty,
                    'bio' => $order->assignedTechnician->bio,
                    'rating_average' => $this->nullableAverage(
                        $eligibleTechnicians->get($order->assignedTechnician->id)?->getAttribute('rating_average'),
                    ),
                    'reviews_count' => (int) ($eligibleTechnicians->get($order->assignedTechnician->id)?->getAttribute('received_technician_reviews_count') ?? 0),
                ] : null,
                'technician_ids' => $order->activeTechnicianAssignments
                    ->pluck('user_id')
                    ->whenEmpty(function ($technicianIds) use ($order) {
                        return $order->assigned_technician_id
                            ? $technicianIds->push($order->assigned_technician_id)
                            : $technicianIds;
                    })
                    ->values(),
                'technician_team' => $order->activeTechnicianAssignments
                    ->map(fn ($assignment) => [
                        'id' => $assignment->user->id,
                        'name' => $assignment->user->name,
                        'avatar_url' => $assignment->user->avatar_url,
                        'specialty' => $assignment->user->specialty,
                        'rating_average' => $this->nullableAverage(
                            $eligibleTechnicians->get($assignment->user_id)?->getAttribute('rating_average'),
                        ),
                        'reviews_count' => (int) ($eligibleTechnicians->get($assignment->user_id)?->getAttribute('received_technician_reviews_count') ?? 0),
                    ])
                    ->values(),
                'reviewable_technicians' => $eligibleTechnicians
                    ->map(function (User $technician) use ($reviewsByTechnician): array {
                        $review = $reviewsByTechnician->get($technician->id);

                        return [
                            'id' => $technician->id,
                            'name' => $technician->name,
                            'avatar_url' => $technician->avatar_url,
                            'specialty' => $technician->specialty,
                            'rating_average' => $this->nullableAverage($technician->getAttribute('rating_average')),
                            'reviews_count' => (int) $technician->getAttribute('received_technician_reviews_count'),
                            'review' => $review ? [
                                'rating' => $review->rating,
                                'quality_rating' => $review->quality_rating,
                                'communication_rating' => $review->communication_rating,
                                'punctuality_rating' => $review->punctuality_rating,
                                'tags' => $review->tags ?? [],
                                'comment' => $review->comment,
                            ] : null,
                        ];
                    })
                    ->values(),
                'supervisor' => $order->supervisor?->name,
                'estimated_total' => $order->estimated_total,
                'timeline' => $order->statusEvents
                    ->filter(fn ($event) => $user->role !== 'customer' || $event->visibility === 'customer')
                    ->values()->map(fn ($event) => [
                        'title' => $event->title,
                        'description' => $event->description,
                        'visibility' => $event->visibility,
                        'actor' => $event->actor?->name,
                        'occurred_at' => $event->occurred_at->translatedFormat('d M Y, H.i'),
                    ]),
                'checklist' => collect($order->service->checklistTemplates)->map(function (ChecklistTemplate $item) use ($checklistResults): array {
                    $result = $checklistResults->get($item->id);
                    $completedAt = $result?->completed_at;

                    return [
                        'id' => $item->id,
                        'title' => $item->title,
                        'standard_code' => $item->standard_code,
                        'requires_evidence' => $item->requires_evidence,
                        'result' => $result !== null ? $result->result : 'pending',
                        'notes' => $result?->notes,
                        'completed_by' => $result?->completedBy?->name,
                        'completed_at' => $completedAt?->translatedFormat('d M Y, H.i'),
                    ];
                })->values(),
                'messages' => $order->messages
                    ->sortBy('created_at')
                    ->values()
                    ->map(fn ($message) => [
                        'id' => $message->id,
                        'body' => $message->body,
                        'sender_id' => $message->sender_id,
                        'sender_name' => $message->sender_id === null ? 'Akun tidak aktif' : $message->sender->name,
                        'sender_role' => $message->sender_id === null ? 'system' : $message->sender->role,
                        'sender_avatar_url' => $message->sender?->avatar_url,
                        'created_at' => $message->created_at->translatedFormat('d M, H.i'),
                    ]),
                'spare_parts' => $visibleSpareParts->map(fn (ServiceOrderSparePart $sp): array => [
                    'id' => $sp->id,
                    'inventory_item_id' => $sp->inventory_item_id,
                    'sku' => $sp->item->sku,
                    'name' => $sp->item->name,
                    'unit' => $sp->item->unit,
                    'requested_quantity' => $sp->requested_quantity,
                    'issued_quantity' => $sp->issued_quantity,
                    'returned_quantity' => $sp->returned_quantity,
                    'net_issued_quantity' => $sp->netIssuedQuantity(),
                    'unit_cost' => $canManageInventoryCosts ? $sp->unit_cost : null,
                    'unit_price' => $sp->unit_price,
                    'line_total' => $sp->netIssuedQuantity() * $sp->unit_price,
                    'warranty_days' => $sp->item->warranty_days,
                    'warranty_terms' => $sp->item->warranty_terms,
                    'has_warranty' => $sp->warranty !== null && $sp->warranty->resolvedStatus() !== 'void',
                    'status' => $sp->status,
                    'requester' => $isInternal ? ($sp->requester->name ?? 'Tim') : null,
                    'approver' => $isInternal ? $sp->approver?->name : null,
                    'returner' => $isInternal ? $sp->returner?->name : null,
                    'request_notes' => $isInternal ? $sp->request_notes : null,
                    'issue_notes' => $isInternal ? $sp->issue_notes : null,
                    'return_notes' => $isInternal ? $sp->return_notes : null,
                    'cancellation_reason' => $isInternal ? $sp->cancellation_reason : null,
                    'requested_at' => $sp->requested_at->translatedFormat('d M Y, H.i'),
                    'issued_at' => $sp->issued_at?->translatedFormat('d M Y, H.i'),
                    'returned_at' => $sp->returned_at?->translatedFormat('d M Y, H.i'),
                    'can_issue' => $isInternal && Gate::allows('issue', $sp),
                    'can_return' => $isInternal && Gate::allows('returnPart', $sp),
                    'can_cancel' => $isInternal && Gate::allows('cancel', $sp),
                ])->values(),
                'spare_parts_summary' => [
                    'internal_cost_total' => $canManageInventoryCosts ? $sparePartsCostTotal : null,
                    'chargeable_total' => $sparePartsChargeTotal,
                    'net_units' => (int) $order->spareParts->sum(fn (ServiceOrderSparePart $sp): int => $sp->netIssuedQuantity()),
                ],
                'device' => $order->device ? [
                    'id' => $order->device->id,
                    'device_code' => $order->device->device_code,
                    'name' => $order->device->name,
                    'category' => $order->device->category,
                    'brand' => $order->device->brand,
                    'model' => $order->device->model,
                    'serial_masked' => $order->device->serial_masked,
                    'location' => $order->device->location,
                    'status' => $order->device->status,
                    'href' => route('devices.show', $order->device),
                ] : null,
                'unified_timeline' => $deviceService->buildOrderTimeline($order, $user),
                'warranties' => $order->warranties->map(function (Warranty $warranty) use ($user, $isInternal): array {
                    $resolvedStatus = $warranty->resolvedStatus();
                    $hasActiveClaim = $warranty->claims->whereIn('status', WarrantyClaim::ACTIVE_STATUSES)->isNotEmpty();

                    return [
                        'id' => $warranty->id,
                        'warranty_number' => $warranty->warranty_number,
                        'scope_type' => $warranty->scope_type,
                        'item_label' => $warranty->item_label,
                        'reference_number' => $warranty->reference_number,
                        'duration_days' => $warranty->duration_days,
                        'coverage_description' => $warranty->coverage_description,
                        'status' => $resolvedStatus,
                        'is_expiring' => $warranty->isApproachingExpiration(),
                        'remaining_days' => $warranty->remainingDays(),
                        'starts_at' => $warranty->starts_at?->translatedFormat('d M Y, H.i'),
                        'expires_at' => $warranty->expires_at?->translatedFormat('d M Y, H.i'),
                        'void_reason' => $warranty->void_reason,
                        'can_submit_claim' => $resolvedStatus === 'active'
                            && ! $hasActiveClaim
                            && Gate::forUser($user)->allows('submit', [WarrantyClaim::class, $warranty]),
                        'can_void' => $resolvedStatus !== 'void' && Gate::forUser($user)->allows('void', $warranty),
                        'claims' => $warranty->claims->map(fn (WarrantyClaim $claim): array => [
                            'id' => $claim->id,
                            'claim_number' => $claim->claim_number,
                            'status' => $claim->status,
                            'issue_description' => $claim->issue_description,
                            'eligibility_checked' => $claim->eligibility_checked,
                            'eligibility_notes' => $claim->eligibility_notes,
                            'review_notes' => $claim->review_notes,
                            'resolution_notes' => $claim->resolution_notes,
                            'claim_ticket' => $claim->claimOrder?->ticket_number,
                            'claim_order_href' => $claim->claim_service_order_id ? route('orders.show', $claim->claim_service_order_id) : null,
                            'submitted_at' => $claim->submitted_at->translatedFormat('d M Y, H.i'),
                            'reviewed_at' => $claim->reviewed_at?->translatedFormat('d M Y, H.i'),
                            'resolved_at' => $claim->resolved_at?->translatedFormat('d M Y, H.i'),
                            'reviewer_name' => $isInternal ? $claim->reviewer?->name : ($claim->reviewed_at ? 'Tim AD Com' : null),
                            'can_review' => Gate::forUser($user)->allows('review', $claim),
                            'can_resolve' => Gate::forUser($user)->allows('resolve', $claim),
                        ])->values()->all(),
                    ];
                })->values(),
                'finance' => [
                    'estimated_total' => $order->estimated_total,
                    'verified_income' => (int) $order->financialTransactions->where('type', 'income')->where('status', 'verified')->sum('amount'),
                    'pending_income' => (int) $order->financialTransactions->where('type', 'income')->where('status', 'pending')->sum('amount'),
                    'profitability' => in_array($user->role, ['operator', 'admin', 'supervisor'], true)
                        ? app(FinancialReportService::class)->orderBreakdown($order)
                        : null,
                    'quotations' => $order->estimates->sortByDesc('version')->values()->map(fn ($estimate): array => [
                        'id' => $estimate->id,
                        'quotation_number' => $estimate->quotation_number ?? ('QUO-v'.$estimate->version),
                        'version' => $estimate->version,
                        'status' => $estimate->resolvedStatus(),
                        'total' => $estimate->total,
                        'valid_until' => $estimate->valid_until?->translatedFormat('d M Y'),
                        'invoice_id' => $estimate->invoice?->id,
                        'invoice_number' => $estimate->invoice?->invoice_number,
                    ]),
                    'invoices' => $order->invoices
                        ->filter(fn ($invoice): bool => $user->role !== 'customer' || $invoice->status !== 'draft')
                        ->values()
                        ->map(fn ($invoice): array => [
                            'id' => $invoice->id,
                            'invoice_number' => $invoice->invoice_number,
                            'status' => $invoice->resolvedStatus(),
                            'total_amount' => $invoice->total_amount,
                            'paid_amount' => $invoice->paid_amount,
                            'outstanding_balance' => $invoice->outstandingBalance(),
                            'due_date' => $invoice->due_date?->translatedFormat('d M Y'),
                        ]),
                    'transactions' => $visibleTransactions->sortByDesc('occurred_at')->values()->map(fn ($transaction) => [
                        'id' => $transaction->id,
                        'type' => $transaction->type,
                        'amount' => $transaction->amount,
                        'payment_method' => $transaction->payment_method,
                        'status' => $transaction->status,
                        'reference' => $transaction->reference,
                        'description' => $transaction->description,
                        'occurred_at' => $transaction->occurred_at->translatedFormat('d M Y, H.i'),
                    ]),
                ],
            ],
            'currentUserId' => $user->id,
            'currentUserRole' => $user->role,
            'canUpdateChecklist' => Gate::allows('update', $order),
            'canReview' => Gate::allows('review', $order),
            'canChat' => $order->assigned_technician_id !== null
                && ($order->customer_id !== null || $user->role === 'customer'),
            'allowedStatuses' => collect(UpdateOrderStatus::allowedTransitions($request->user(), $order))
                ->map(fn (string $status) => ['value' => $status, 'label' => UpdateOrderStatus::statusLabel($status)])
                ->values(),
            'canManageTeam' => Gate::allows('updateTeam', $order),
            'canManageSla' => Gate::allows('manageSla', $order),
            'canManageWork' => Gate::allows('update', $order),
            'canConfirmEvidence' => ($user->role === 'customer' && $order->customer_id === $user->id) || $user->role === 'operator',
            'canManageDevice' => Gate::allows('linkOrder', [CustomerAsset::class, $order]),
            'canManageWarranty' => Gate::allows('create', Warranty::class),
            'customerDevices' => Gate::allows('linkOrder', [CustomerAsset::class, $order]) && $order->customer_id !== null
                ? CustomerAsset::query()
                    ->where('customer_id', $order->customer_id)
                    ->orderBy('name')
                    ->get(['id', 'device_code', 'name', 'category', 'brand', 'model', 'serial_masked'])
                : [],
            'warrantyDefaults' => [
                'service_warranty_days' => (int) $order->service->warranty_days,
            ],
            'canRequestSpareParts' => $canRequestSpareParts,
            'inventoryCatalog' => $canRequestSpareParts
                ? InventoryItem::query()
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->get(['id', 'sku', 'name', 'unit', 'quantity_on_hand', 'selling_price'])
                    ->map(fn (InventoryItem $item): array => [
                        'id' => $item->id,
                        'sku' => $item->sku,
                        'name' => $item->name,
                        'unit' => $item->unit,
                        'available_stock' => $item->quantity_on_hand,
                        'selling_price' => $item->selling_price,
                    ])
                : [],
            'dispatchRecommendations' => Gate::allows('updateTeam', $order)
                && ! in_array($order->status, SlaService::TERMINAL_STATUSES, true)
                ? app(WorkforceService::class)->recommendForOrder($order)
                : [],
            'technicians' => Gate::allows('updateTeam', $order)
                ? User::query()
                    ->where('role', 'technician')
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->get(['id', 'name', 'avatar_url', 'specialty'])
                : [],
        ]);
    }

    private function nullableAverage(mixed $average): ?float
    {
        return $average === null ? null : round((float) $average, 2);
    }

    public function updateStatus(Request $request, ServiceOrder $order, UpdateOrderStatus $update): RedirectResponse
    {
        Gate::authorize('update', $order);
        $allowed = UpdateOrderStatus::allowedTransitions($request->user(), $order);
        abort_if($allowed === [], 403);
        $validated = $request->validate([
            'status' => ['required', Rule::in($allowed)],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $update->handle($order, $request->user(), $validated['status'], $validated['note'] ?? null, $request->ip());

        return back()->with('success', 'Status tiket berhasil diperbarui.');
    }
}
