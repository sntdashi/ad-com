<?php

namespace App\Http\Controllers;

use App\Models\AssetServiceRecord;
use App\Models\CustomerAsset;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Models\Warranty;
use App\Models\WarrantyClaim;
use App\Services\CustomerHistory\CustomerDeviceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CustomerDeviceController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', CustomerAsset::class);

        $user = $request->user();
        $isCustomer = $user->role === 'customer';
        $isManager = in_array($user->role, ['operator', 'admin', 'supervisor'], true);

        $search = trim((string) $request->query('search', ''));
        $statusFilter = (string) $request->query('status', 'all');
        $warrantyFilter = (string) $request->query('warranty', 'all');

        $query = CustomerAsset::query()
            ->with([
                'customer:id,name,email',
                'warranties' => fn ($q) => $q->latest('id'),
                'orders:id,customer_asset_id,ticket_number,status,completed_at,created_at',
            ])
            ->withCount(['orders', 'warranties', 'warrantyClaims']);

        if ($isCustomer) {
            $query->where('customer_id', $user->id);
        } elseif ($user->role === 'technician') {
            $query->whereHas('orders', function (Builder $orderQuery) use ($user): void {
                $orderQuery
                    ->where('assigned_technician_id', $user->id)
                    ->orWhereHas('activeTechnicianAssignments', fn (Builder $a) => $a->where('user_id', $user->id));
            });
        }

        if ($search !== '') {
            $query->where(function (Builder $q) use ($search, $isCustomer): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('device_code', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%")
                    ->orWhere('brand', 'like', "%{$search}%")
                    ->orWhere('model', 'like', "%{$search}%")
                    ->orWhere('serial_masked', 'like', "%{$search}%");

                if (! $isCustomer) {
                    $q->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'like', "%{$search}%"));
                }
            });
        }

        if (in_array($statusFilter, ['active', 'in_service', 'retired', 'transferred'], true)) {
            $query->where('status', $statusFilter);
        }

        if ($warrantyFilter === 'active') {
            $query->whereHas('warranties', fn (Builder $w) => $w->where('status', 'active')->where(function (Builder $exp): void {
                $exp->whereNull('expires_at')->orWhere('expires_at', '>', now());
            }));
        } elseif ($warrantyFilter === 'expiring') {
            $query->whereHas('warranties', fn (Builder $w) => $w->where('status', 'active')
                ->where('expires_at', '>', now())
                ->where('expires_at', '<=', now()->addDays(7)));
        } elseif ($warrantyFilter === 'expired') {
            $query->whereHas('warranties', fn (Builder $w) => $w->where('status', 'expired')
                ->orWhere(function (Builder $sub): void {
                    $sub->where('status', 'active')->where('expires_at', '<=', now());
                }));
        }

        $devices = $query
            ->latest('last_serviced_at')
            ->latest('id')
            ->paginate(12)
            ->withQueryString()
            ->through(function (CustomerAsset $asset) use ($isCustomer): array {
                $activeWarranties = $asset->warranties->filter(fn (Warranty $w): bool => $w->resolvedStatus() === 'active')->count();
                $expiringWarranties = $asset->warranties->filter(fn (Warranty $w): bool => $w->isApproachingExpiration())->count();

                return [
                    'id' => $asset->id,
                    'device_code' => $asset->device_code,
                    'name' => $asset->name,
                    'category' => $asset->category,
                    'brand' => $asset->brand,
                    'model' => $asset->model,
                    'serial_masked' => $asset->serial_masked,
                    'location' => $asset->location,
                    'status' => $asset->status,
                    'notes' => $asset->notes,
                    'customer_name' => $isCustomer ? $asset->customer?->name : ($asset->customer->name ?? 'Pelanggan tamu'),
                    'orders_count' => (int) $asset->getAttribute('orders_count'),
                    'warranties_count' => (int) $asset->getAttribute('warranties_count'),
                    'active_warranties_count' => $activeWarranties,
                    'expiring_warranties_count' => $expiringWarranties,
                    'claims_count' => (int) $asset->getAttribute('warranty_claims_count'),
                    'last_serviced_at' => $asset->last_serviced_at?->translatedFormat('d M Y, H.i'),
                    'registered_at' => $asset->registered_at?->translatedFormat('d M Y'),
                    'href' => route('devices.show', $asset),
                ];
            });

        $customerPortalData = null;
        if ($isCustomer) {
            $myOrders = ServiceOrder::query()
                ->where('customer_id', $user->id)
                ->with(['service:id,name,code', 'device:id,device_code,name'])
                ->latest()
                ->get();

            $activeOrders = $myOrders
                ->reject(fn (ServiceOrder $o): bool => in_array($o->status, ['completed', 'closed', 'cancelled'], true))
                ->values()
                ->map(fn (ServiceOrder $o): array => [
                    'id' => $o->id,
                    'ticket_number' => $o->ticket_number,
                    'service' => $o->service->name,
                    'status' => $o->status,
                    'device_name' => $o->device?->name,
                    'created_at' => $o->created_at->translatedFormat('d M Y, H.i'),
                    'href' => route('orders.show', $o),
                ]);

            $completedOrders = $myOrders
                ->filter(fn (ServiceOrder $o): bool => in_array($o->status, ['completed', 'warranty', 'closed'], true))
                ->values()
                ->map(fn (ServiceOrder $o): array => [
                    'id' => $o->id,
                    'ticket_number' => $o->ticket_number,
                    'service' => $o->service->name,
                    'status' => $o->status,
                    'device_name' => $o->device?->name,
                    'completed_at' => $o->completed_at?->translatedFormat('d M Y, H.i') ?? $o->created_at->translatedFormat('d M Y'),
                    'href' => route('orders.show', $o),
                ]);

            $myWarranties = Warranty::query()
                ->where('customer_id', $user->id)
                ->with(['serviceOrder:id,ticket_number', 'asset:id,device_code,name'])
                ->latest('id')
                ->get()
                ->map(fn (Warranty $w): array => [
                    'id' => $w->id,
                    'warranty_number' => $w->warranty_number,
                    'scope_type' => $w->scope_type,
                    'item_label' => $w->item_label,
                    'device_name' => $w->asset?->name,
                    'device_href' => $w->asset !== null ? route('devices.show', $w->asset) : null,
                    'ticket_number' => $w->serviceOrder->ticket_number,
                    'order_href' => route('orders.show', $w->service_order_id),
                    'status' => $w->resolvedStatus(),
                    'is_expiring' => $w->isApproachingExpiration(),
                    'remaining_days' => $w->remainingDays(),
                    'duration_days' => $w->duration_days,
                    'starts_at' => $w->starts_at?->translatedFormat('d M Y'),
                    'expires_at' => $w->expires_at?->translatedFormat('d M Y'),
                    'coverage_description' => $w->coverage_description,
                ]);

            $myClaims = WarrantyClaim::query()
                ->where('customer_id', $user->id)
                ->with(['warranty:id,warranty_number,item_label', 'originalOrder:id,ticket_number', 'claimOrder:id,ticket_number'])
                ->latest('submitted_at')
                ->get()
                ->map(fn (WarrantyClaim $c): array => [
                    'id' => $c->id,
                    'claim_number' => $c->claim_number,
                    'warranty_number' => $c->warranty->warranty_number,
                    'item_label' => $c->warranty->item_label,
                    'status' => $c->status,
                    'issue_description' => $c->issue_description,
                    'review_notes' => $c->review_notes,
                    'resolution_notes' => $c->resolution_notes,
                    'original_ticket' => $c->originalOrder->ticket_number,
                    'claim_ticket' => $c->claimOrder?->ticket_number,
                    'claim_order_href' => $c->claim_service_order_id ? route('orders.show', $c->claim_service_order_id) : null,
                    'submitted_at' => $c->submitted_at->translatedFormat('d M Y, H.i'),
                ]);

            $customerPortalData = [
                'active_orders' => $activeOrders,
                'completed_orders' => $completedOrders,
                'warranties' => $myWarranties,
                'claims' => $myClaims,
            ];
        }

        return Inertia::render('devices/index', [
            'isCustomer' => $isCustomer,
            'canCreate' => Gate::allows('create', CustomerAsset::class),
            'canSelectCustomer' => $isManager,
            'devices' => $devices,
            'filters' => [
                'search' => $search,
                'status' => $statusFilter,
                'warranty' => $warrantyFilter,
            ],
            'stats' => [
                'total_devices' => $isCustomer
                    ? CustomerAsset::query()->where('customer_id', $user->id)->count()
                    : CustomerAsset::query()->count(),
                'active_warranties' => Warranty::query()
                    ->when($isCustomer, fn ($q) => $q->where('customer_id', $user->id))
                    ->where('status', 'active')
                    ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->count(),
                'expiring_warranties' => Warranty::query()
                    ->when($isCustomer, fn ($q) => $q->where('customer_id', $user->id))
                    ->where('status', 'active')
                    ->where('expires_at', '>', now())
                    ->where('expires_at', '<=', now()->addDays(7))
                    ->count(),
                'active_claims' => WarrantyClaim::query()
                    ->when($isCustomer, fn ($q) => $q->where('customer_id', $user->id))
                    ->whereIn('status', WarrantyClaim::ACTIVE_STATUSES)
                    ->count(),
            ],
            'customers' => $isManager
                ? User::query()
                    ->where('role', 'customer')
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->get(['id', 'name', 'email'])
                : [],
            'customerPortal' => $customerPortalData,
        ]);
    }

    public function show(Request $request, CustomerAsset $customerAsset, CustomerDeviceService $deviceService): Response
    {
        Gate::authorize('view', $customerAsset);

        $user = $request->user();
        $isCustomer = $user->role === 'customer';

        $customerAsset->load([
            'customer:id,name,email,phone',
            'orders' => fn ($q) => $q->with([
                'service:id,name,code',
                'assignedTechnician:id,name',
            ])->latest(),
            'serviceRecords.order.service:id,name',
            'warranties' => fn ($q) => $q->with([
                'serviceOrder:id,ticket_number',
                'claims' => fn ($c) => $c->with(['claimOrder:id,ticket_number', 'submitter:id,name', 'reviewer:id,name', 'resolver:id,name']),
            ])->latest('id'),
        ]);

        $timeline = $deviceService->buildDeviceTimeline($customerAsset, $user);

        return Inertia::render('devices/show', [
            'isCustomer' => $isCustomer,
            'canUpdate' => Gate::allows('update', $customerAsset),
            'canManageWarranty' => Gate::allows('create', Warranty::class),
            'device' => [
                'id' => $customerAsset->id,
                'device_code' => $customerAsset->device_code,
                'name' => $customerAsset->name,
                'category' => $customerAsset->category,
                'brand' => $customerAsset->brand,
                'model' => $customerAsset->model,
                'serial_masked' => $customerAsset->serial_masked,
                'location' => $customerAsset->location,
                'status' => $customerAsset->status,
                'notes' => $customerAsset->notes,
                'customer_name' => $customerAsset->customer->name ?? 'Pelanggan tamu',
                'customer_email' => $isCustomer ? $customerAsset->customer?->email : $customerAsset->customer?->email,
                'registered_at' => $customerAsset->registered_at?->translatedFormat('d M Y, H.i'),
                'last_serviced_at' => $customerAsset->last_serviced_at?->translatedFormat('d M Y, H.i'),
            ],
            'orders' => $customerAsset->orders->map(fn (ServiceOrder $order): array => [
                'id' => $order->id,
                'ticket_number' => $order->ticket_number,
                'service' => $order->service->name,
                'status' => $order->status,
                'is_completed' => in_array($order->status, ['completed', 'warranty', 'closed'], true),
                'technician' => $order->assignedTechnician?->name,
                'created_at' => $order->created_at->translatedFormat('d M Y, H.i'),
                'completed_at' => $order->completed_at?->translatedFormat('d M Y, H.i'),
                'href' => route('orders.show', $order),
            ])->values(),
            'serviceRecords' => $customerAsset->serviceRecords->map(fn (AssetServiceRecord $record): array => [
                'id' => $record->id,
                'service_order_id' => $record->service_order_id,
                'ticket_number' => $record->order->ticket_number,
                'service_name' => $record->order->service->name,
                'order_status' => $record->order->status,
                'device_snapshot' => $record->device_snapshot,
                'diagnosis' => $record->diagnosis,
                'action_taken' => $record->action_taken,
                'test_result' => $record->test_result,
                'recommendation' => $record->recommendation,
                'warranty_until' => $record->warranty_until?->translatedFormat('d M Y'),
                'recorded_at' => $record->created_at?->translatedFormat('d M Y, H.i'),
                'order_href' => route('orders.show', $record->service_order_id),
            ])->values(),
            'warranties' => $customerAsset->warranties->map(function (Warranty $warranty) use ($user, $isCustomer): array {
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
                    'ticket_number' => $warranty->serviceOrder->ticket_number,
                    'order_href' => route('orders.show', $warranty->service_order_id),
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
                        'reviewer_name' => $isCustomer ? ($claim->reviewed_at ? 'Tim AD Com' : null) : $claim->reviewer?->name,
                    ])->values()->all(),
                ];
            })->values(),
            'timeline' => $timeline,
        ]);
    }

    public function store(Request $request, CustomerDeviceService $deviceService): RedirectResponse
    {
        Gate::authorize('create', CustomerAsset::class);

        $user = $request->user();
        $isCustomer = $user->role === 'customer';

        $validated = $request->validate([
            'customer_id' => [$isCustomer ? 'nullable' : 'required', 'nullable', 'integer', Rule::exists('users', 'id')->where('role', 'customer')],
            'name' => ['required', 'string', 'max:120'],
            'category' => ['required', 'string', 'max:80'],
            'brand' => ['nullable', 'string', 'max:80'],
            'model' => ['nullable', 'string', 'max:80'],
            'serial_number' => ['nullable', 'string', 'max:80'],
            'location' => ['nullable', 'string', 'max:160'],
            'status' => ['sometimes', Rule::in(['active', 'in_service', 'retired', 'transferred'])],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $owner = $isCustomer
            ? $user
            : User::query()->findOrFail((int) $validated['customer_id']);

        $deviceService->registerDevice($validated, $user, $owner, $request->ip());

        return back()->with('success', 'Perangkat berhasil didaftarkan ke riwayat pelanggan.');
    }

    public function update(Request $request, CustomerAsset $customerAsset, CustomerDeviceService $deviceService): RedirectResponse
    {
        Gate::authorize('update', $customerAsset);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'category' => ['required', 'string', 'max:80'],
            'brand' => ['nullable', 'string', 'max:80'],
            'model' => ['nullable', 'string', 'max:80'],
            'serial_number' => ['nullable', 'string', 'max:80'],
            'location' => ['nullable', 'string', 'max:160'],
            'status' => ['required', Rule::in(['active', 'in_service', 'retired', 'transferred'])],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $deviceService->updateDevice($customerAsset, $validated, $request->user(), $request->ip());

        return back()->with('success', 'Informasi perangkat diperbarui tanpa mengubah arsip layanan historis.');
    }

    public function linkOrder(Request $request, ServiceOrder $order, CustomerDeviceService $deviceService): RedirectResponse
    {
        Gate::authorize('linkOrder', [CustomerAsset::class, $order]);

        $user = $request->user();
        $mode = (string) $request->input('mode', 'existing');

        if ($mode === 'create') {
            $validated = $request->validate([
                'name' => ['required', 'string', 'max:120'],
                'category' => ['required', 'string', 'max:80'],
                'brand' => ['nullable', 'string', 'max:80'],
                'model' => ['nullable', 'string', 'max:80'],
                'serial_number' => ['nullable', 'string', 'max:80'],
                'location' => ['nullable', 'string', 'max:160'],
                'notes' => ['nullable', 'string', 'max:1000'],
                'diagnosis' => ['nullable', 'string', 'max:1000'],
                'action_taken' => ['nullable', 'string', 'max:1000'],
                'test_result' => ['nullable', 'string', 'max:1000'],
                'recommendation' => ['nullable', 'string', 'max:1000'],
            ]);

            $owner = $order->customer_id !== null
                ? User::query()->find($order->customer_id)
                : ($user->role === 'customer' ? $user : null);

            $asset = $deviceService->registerDevice([
                ...$validated,
                'origin_order_id' => $order->id,
            ], $user, $owner, $request->ip());

            $deviceService->linkOrderToDevice($order, $asset, $user, $validated, $request->ip());
        } else {
            $validated = $request->validate([
                'customer_asset_id' => ['required', 'string', Rule::exists('customer_assets', 'id')],
                'diagnosis' => ['nullable', 'string', 'max:1000'],
                'action_taken' => ['nullable', 'string', 'max:1000'],
                'test_result' => ['nullable', 'string', 'max:1000'],
                'recommendation' => ['nullable', 'string', 'max:1000'],
            ]);

            $asset = CustomerAsset::query()->findOrFail((string) $validated['customer_asset_id']);
            if ($user->role === 'customer' && $asset->customer_id !== $user->id) {
                abort(403, 'Perangkat tersebut bukan milik akun Anda.');
            }

            $deviceService->linkOrderToDevice($order, $asset, $user, $validated, $request->ip());
        }

        return back()->with('success', 'Perangkat berhasil ditautkan ke tiket dan arsip riwayat.');
    }
}
