<?php

namespace App\Http\Controllers;

use App\Models\ServiceOrder;
use App\Models\Warranty;
use App\Models\WarrantyClaim;
use App\Services\Warranty\WarrantyService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class WarrantyController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Warranty::class);

        $search = trim((string) $request->query('search', ''));
        $statusFilter = (string) $request->query('status', 'all');
        $scopeFilter = (string) $request->query('scope', 'all');
        $claimStatusFilter = (string) $request->query('claim_status', 'all');

        $warrantiesQuery = Warranty::query()
            ->with([
                'customer:id,name',
                'asset:id,device_code,name',
                'serviceOrder:id,ticket_number,customer_name',
                'claims:id,warranty_id,claim_number,status',
            ]);

        if ($search !== '') {
            $warrantiesQuery->where(function (Builder $q) use ($search): void {
                $q->where('warranty_number', 'like', "%{$search}%")
                    ->orWhere('item_label', 'like', "%{$search}%")
                    ->orWhere('reference_number', 'like', "%{$search}%")
                    ->orWhereHas('serviceOrder', fn (Builder $o) => $o->where('ticket_number', 'like', "%{$search}%")->orWhere('customer_name', 'like', "%{$search}%"))
                    ->orWhereHas('asset', fn (Builder $a) => $a->where('name', 'like', "%{$search}%")->orWhere('device_code', 'like', "%{$search}%"));
            });
        }

        if (in_array($scopeFilter, ['service', 'spare_part'], true)) {
            $warrantiesQuery->where('scope_type', $scopeFilter);
        }

        if ($statusFilter === 'active') {
            $warrantiesQuery->where('status', 'active')
                ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
        } elseif ($statusFilter === 'expiring') {
            $warrantiesQuery->where('status', 'active')
                ->where('expires_at', '>', now())
                ->where('expires_at', '<=', now()->addDays(7));
        } elseif ($statusFilter === 'expired') {
            $warrantiesQuery->where(function (Builder $q): void {
                $q->where('status', 'expired')
                    ->orWhere(fn (Builder $sub) => $sub->where('status', 'active')->where('expires_at', '<=', now()));
            });
        } elseif (in_array($statusFilter, ['void', 'pending'], true)) {
            $warrantiesQuery->where('status', $statusFilter);
        }

        $warranties = $warrantiesQuery
            ->latest('id')
            ->paginate(10, ['*'], 'warranties_page')
            ->withQueryString()
            ->through(fn (Warranty $warranty): array => [
                'id' => $warranty->id,
                'warranty_number' => $warranty->warranty_number,
                'scope_type' => $warranty->scope_type,
                'item_label' => $warranty->item_label,
                'reference_number' => $warranty->reference_number,
                'customer_name' => $warranty->customer->name ?? $warranty->serviceOrder->customer_name,
                'device_name' => $warranty->asset?->name,
                'device_code' => $warranty->asset?->device_code,
                'device_href' => $warranty->asset !== null ? route('devices.show', $warranty->asset) : null,
                'ticket_number' => $warranty->serviceOrder->ticket_number,
                'order_href' => route('orders.show', $warranty->service_order_id),
                'duration_days' => $warranty->duration_days,
                'coverage_description' => $warranty->coverage_description,
                'status' => $warranty->resolvedStatus(),
                'is_expiring' => $warranty->isApproachingExpiration(),
                'remaining_days' => $warranty->remainingDays(),
                'starts_at' => $warranty->starts_at?->translatedFormat('d M Y'),
                'expires_at' => $warranty->expires_at?->translatedFormat('d M Y'),
                'void_reason' => $warranty->void_reason,
                'active_claims_count' => $warranty->claims->whereIn('status', WarrantyClaim::ACTIVE_STATUSES)->count(),
                'can_void' => $warranty->resolvedStatus() !== 'void' && Gate::allows('void', $warranty),
            ]);

        $claimsQuery = WarrantyClaim::query()
            ->with([
                'warranty:id,warranty_number,item_label,scope_type,status,expires_at',
                'customer:id,name',
                'asset:id,device_code,name',
                'originalOrder:id,ticket_number,customer_name',
                'claimOrder:id,ticket_number,status',
                'submitter:id,name',
                'reviewer:id,name',
                'resolver:id,name',
            ]);

        if ($search !== '') {
            $claimsQuery->where(function (Builder $q) use ($search): void {
                $q->where('claim_number', 'like', "%{$search}%")
                    ->orWhere('issue_description', 'like', "%{$search}%")
                    ->orWhereHas('warranty', fn (Builder $w) => $w->where('warranty_number', 'like', "%{$search}%")->orWhere('item_label', 'like', "%{$search}%"))
                    ->orWhereHas('originalOrder', fn (Builder $o) => $o->where('ticket_number', 'like', "%{$search}%")->orWhere('customer_name', 'like', "%{$search}%"));
            });
        }

        if (in_array($claimStatusFilter, ['submitted', 'under_review', 'approved', 'rejected', 'resolved'], true)) {
            $claimsQuery->where('status', $claimStatusFilter);
        }

        $claims = $claimsQuery
            ->latest('submitted_at')
            ->paginate(10, ['*'], 'claims_page')
            ->withQueryString()
            ->through(fn (WarrantyClaim $claim): array => [
                'id' => $claim->id,
                'claim_number' => $claim->claim_number,
                'warranty_number' => $claim->warranty->warranty_number,
                'warranty_status' => $claim->warranty->resolvedStatus(),
                'item_label' => $claim->warranty->item_label,
                'customer_name' => $claim->customer->name ?? $claim->originalOrder->customer_name,
                'device_name' => $claim->asset?->name,
                'device_href' => $claim->asset !== null ? route('devices.show', $claim->asset) : null,
                'original_ticket' => $claim->originalOrder->ticket_number,
                'original_order_href' => route('orders.show', $claim->original_service_order_id),
                'claim_ticket' => $claim->claimOrder?->ticket_number,
                'claim_order_href' => $claim->claim_service_order_id ? route('orders.show', $claim->claim_service_order_id) : null,
                'status' => $claim->status,
                'issue_description' => $claim->issue_description,
                'eligibility_checked' => $claim->eligibility_checked,
                'eligibility_notes' => $claim->eligibility_notes,
                'review_notes' => $claim->review_notes,
                'resolution_notes' => $claim->resolution_notes,
                'submitter_name' => $claim->submitter?->name,
                'reviewer_name' => $claim->reviewer?->name,
                'resolver_name' => $claim->resolver?->name,
                'submitted_at' => $claim->submitted_at->translatedFormat('d M Y, H.i'),
                'reviewed_at' => $claim->reviewed_at?->translatedFormat('d M Y, H.i'),
                'resolved_at' => $claim->resolved_at?->translatedFormat('d M Y, H.i'),
                'can_review' => Gate::allows('review', $claim),
                'can_resolve' => Gate::allows('resolve', $claim),
            ]);

        $expiringAlerts = Warranty::query()
            ->with(['serviceOrder:id,ticket_number,customer_name', 'asset:id,name'])
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->where('expires_at', '<=', now()->addDays(7))
            ->orderBy('expires_at')
            ->limit(8)
            ->get()
            ->map(fn (Warranty $w): array => [
                'id' => $w->id,
                'warranty_number' => $w->warranty_number,
                'item_label' => $w->item_label,
                'customer_name' => $w->serviceOrder->customer_name,
                'device_name' => $w->asset?->name,
                'remaining_days' => $w->remainingDays(),
                'expires_at' => $w->expires_at?->translatedFormat('d M Y'),
                'order_href' => route('orders.show', $w->service_order_id),
            ]);

        return Inertia::render('operations/warranties', [
            'warranties' => $warranties,
            'claims' => $claims,
            'expiringAlerts' => $expiringAlerts,
            'filters' => [
                'search' => $search,
                'status' => $statusFilter,
                'scope' => $scopeFilter,
                'claim_status' => $claimStatusFilter,
            ],
            'stats' => [
                'active' => Warranty::query()->where('status', 'active')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count(),
                'expiring' => Warranty::query()->where('status', 'active')->where('expires_at', '>', now())->where('expires_at', '<=', now()->addDays(7))->count(),
                'expired' => Warranty::query()->where('status', 'expired')->orWhere(fn ($q) => $q->where('status', 'active')->where('expires_at', '<=', now()))->count(),
                'active_claims' => WarrantyClaim::query()->whereIn('status', WarrantyClaim::ACTIVE_STATUSES)->count(),
            ],
        ]);
    }

    public function store(Request $request, ServiceOrder $order, WarrantyService $warranties): RedirectResponse
    {
        Gate::authorize('create', Warranty::class);

        $validated = $request->validate([
            'scope_type' => ['required', Rule::in(['service', 'spare_part'])],
            'service_order_spare_part_id' => ['nullable', 'integer', 'exists:service_order_spare_parts,id'],
            'customer_asset_id' => ['nullable', 'string', 'exists:customer_assets,id'],
            'duration_days' => ['nullable', 'integer', 'min:1', 'max:1825'],
            'coverage_description' => ['nullable', 'string', 'max:1000'],
            'reference_number' => ['nullable', 'string', 'max:64'],
            'starts_at' => ['nullable', 'date'],
            'is_pending' => ['sometimes', 'boolean'],
        ]);

        $warranties->registerWarranty($order, $validated, $request->user(), $request->ip());

        return back()->with('success', 'Catatan garansi berhasil didaftarkan.');
    }

    public function void(Request $request, Warranty $warranty, WarrantyService $warranties): RedirectResponse
    {
        Gate::authorize('void', $warranty);

        $validated = $request->validate([
            'void_reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $warranties->voidWarranty($warranty, (string) $validated['void_reason'], $request->user(), $request->ip());

        return back()->with('success', 'Garansi berhasil dinonaktifkan (void).');
    }

    public function storeClaim(Request $request, Warranty $warranty, WarrantyService $warranties): RedirectResponse
    {
        Gate::authorize('submit', [WarrantyClaim::class, $warranty]);

        $validated = $request->validate([
            'issue_description' => ['required', 'string', 'min:10', 'max:2000'],
        ], [
            'issue_description.min' => 'Jelaskan kendala klaim minimal 10 karakter agar tim dapat memverifikasi kelayakan.',
        ]);

        $warranties->submitClaim($warranty, $request->user(), (string) $validated['issue_description'], $request->ip());

        return back()->with('success', 'Pengajuan klaim garansi berhasil dikirim untuk ditinjau.');
    }

    public function reviewClaim(Request $request, WarrantyClaim $warrantyClaim, WarrantyService $warranties): RedirectResponse
    {
        Gate::authorize('review', $warrantyClaim);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['under_review', 'approved', 'rejected'])],
            'eligibility_checked' => ['sometimes', 'boolean'],
            'eligibility_notes' => ['nullable', 'string', 'max:1000'],
            'review_notes' => ['nullable', 'string', 'max:1000'],
            'create_service_order' => ['sometimes', 'boolean'],
        ]);

        $warranties->reviewClaim($warrantyClaim, $request->user(), $validated, $request->ip());

        return back()->with('success', 'Keputusan tinjauan klaim garansi berhasil disimpan.');
    }

    public function resolveClaim(Request $request, WarrantyClaim $warrantyClaim, WarrantyService $warranties): RedirectResponse
    {
        Gate::authorize('resolve', $warrantyClaim);

        $validated = $request->validate([
            'resolution_notes' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $warranties->resolveClaim($warrantyClaim, $request->user(), (string) $validated['resolution_notes'], $request->ip());

        return back()->with('success', 'Klaim garansi ditandai selesai ditangani.');
    }
}
