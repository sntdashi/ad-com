<?php

namespace App\Services\Analytics;

use App\Models\CustomerAsset;
use App\Models\Invoice;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Models\Warranty;
use App\Models\WarrantyClaim;
use Carbon\CarbonImmutable;

class CustomerAnalyticsService
{
    public function __construct(
        public AnalyticsCacheManager $cacheManager,
        public AnalyticsAccess $access,
    ) {}

    /**
     * Build privacy-aware Customer & Warranty Intelligence metrics.
     *
     * @return array<string, mixed>
     */
    public function summary(AnalyticsPeriod $period, User $viewer): array
    {
        $canViewPii = $this->access->canViewCustomerPii($viewer);

        return $this->cacheManager->remember(
            namespace: 'customers',
            domains: ['customers', 'orders', 'finance', 'warranties'],
            period: $period,
            extraKey: $canViewPii ? 'pii:1' : 'pii:0',
            resolver: fn (): array => $this->computeSummary($period, $viewer),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function computeSummary(AnalyticsPeriod $period, User $viewer): array
    {
        $registeredUsers = User::query()
            ->where('role', 'customer')
            ->get(['id', 'name', 'email', 'created_at']);

        $newRegisteredPeriod = $registeredUsers->filter(
            fn (User $user): bool => $user->created_at !== null
                && $user->created_at->between($period->start, $period->end),
        )->count();

        $periodOrders = ServiceOrder::query()
            ->with(['service:id,code,name'])
            ->where('status', '!=', 'cancelled')
            ->whereBetween('created_at', [$period->start, $period->end])
            ->get(['id', 'customer_id', 'service_id', 'customer_name', 'email', 'phone', 'created_at']);

        $priorOrders = ServiceOrder::query()
            ->where('status', '!=', 'cancelled')
            ->where('created_at', '<', $period->start)
            ->get(['customer_id', 'email', 'phone']);

        $priorCustomerKeys = [];
        foreach ($priorOrders as $prior) {
            $priorCustomerKeys[$this->customerKey($prior->customer_id, $prior->email, $prior->phone)] = true;
        }

        $periodOrderCountsByKey = [];
        $servicePreferenceCounts = [];

        foreach ($periodOrders as $order) {
            $key = $this->customerKey($order->customer_id, $order->email, $order->phone);
            $periodOrderCountsByKey[$key] = ($periodOrderCountsByKey[$key] ?? 0) + 1;

            if ($order->service !== null) {
                $sid = (int) $order->service->id;
                if (! isset($servicePreferenceCounts[$sid])) {
                    $servicePreferenceCounts[$sid] = [
                        'service_id' => $sid,
                        'code' => $order->service->code,
                        'name' => $order->service->name,
                        'orders_count' => 0,
                    ];
                }
                $servicePreferenceCounts[$sid]['orders_count']++;
            }
        }

        $activeCustomersCount = count($periodOrderCountsByKey);
        $returningCustomersCount = 0;
        $newOrderingCustomersCount = 0;

        foreach ($periodOrderCountsByKey as $key => $count) {
            if ($count >= 2 || isset($priorCustomerKeys[$key])) {
                $returningCustomersCount++;
            } else {
                $newOrderingCustomersCount++;
            }
        }

        $repeatServiceRate = $activeCustomersCount > 0
            ? round(($returningCustomersCount / $activeCustomersCount) * 100, 1)
            : null;

        $avgServiceFrequency = $activeCustomersCount > 0
            ? round($periodOrders->count() / $activeCustomersCount, 2)
            : null;

        // Lifetime service value across all non-draft/non-cancelled invoices.
        $allInvoices = Invoice::query()
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->get(['customer_id', 'customer_name', 'customer_email', 'total_amount', 'paid_amount']);

        $ltvByCustomer = [];
        $totalLifetimeValue = 0;

        foreach ($allInvoices as $invoice) {
            $totalLifetimeValue += $invoice->total_amount;
            $key = $invoice->customer_id !== null
                ? 'u:'.$invoice->customer_id
                : 'n:'.mb_strtolower(trim($invoice->customer_name));

            if (! isset($ltvByCustomer[$key])) {
                $ltvByCustomer[$key] = [
                    'customer_label' => $this->access->formatCustomerLabel(
                        $invoice->customer_name,
                        $invoice->customer_email,
                        $viewer,
                        $invoice->customer_id,
                    ),
                    'invoices_count' => 0,
                    'lifetime_invoiced' => 0,
                    'lifetime_paid' => 0,
                ];
            }

            $ltvByCustomer[$key]['invoices_count']++;
            $ltvByCustomer[$key]['lifetime_invoiced'] += $invoice->total_amount;
            $ltvByCustomer[$key]['lifetime_paid'] += $invoice->paid_amount;
        }

        $billedCustomerCount = count($ltvByCustomer);
        $avgLifetimeValue = $billedCustomerCount > 0
            ? (int) round($totalLifetimeValue / $billedCustomerCount)
            : null;

        usort($ltvByCustomer, fn (array $a, array $b): int => $b['lifetime_invoiced'] <=> $a['lifetime_invoiced']);

        // Acquisition trend over period buckets.
        $acquisitionBuckets = [];
        foreach ($period->buckets() as $key => $bucket) {
            $acquisitionBuckets[$key] = [
                'key' => $key,
                'label' => $bucket['label'],
                'new_registered_customers' => 0,
                'active_ordering_customers' => 0,
            ];
        }

        foreach ($registeredUsers as $user) {
            if ($user->created_at === null || ! $user->created_at->between($period->start, $period->end)) {
                continue;
            }
            $key = $period->bucketKeyFor(CarbonImmutable::instance($user->created_at));
            if (isset($acquisitionBuckets[$key])) {
                $acquisitionBuckets[$key]['new_registered_customers']++;
            }
        }

        $seenPerBucket = [];
        foreach ($periodOrders as $order) {
            $bKey = $period->bucketKeyFor(CarbonImmutable::instance($order->created_at));
            if (! isset($acquisitionBuckets[$bKey])) {
                continue;
            }
            $cKey = $this->customerKey($order->customer_id, $order->email, $order->phone);
            if (! isset($seenPerBucket[$bKey][$cKey])) {
                $seenPerBucket[$bKey][$cKey] = true;
                $acquisitionBuckets[$bKey]['active_ordering_customers']++;
            }
        }

        // Warranty & claim metrics.
        $warrantiesPeriod = Warranty::query()
            ->whereBetween('created_at', [$period->start, $period->end])
            ->count();
        $activeWarranties = Warranty::query()
            ->where('status', 'active')
            ->count();

        $claimsPeriod = WarrantyClaim::query()
            ->whereBetween('submitted_at', [$period->start, $period->end])
            ->get(['status']);

        $claimsByStatus = [];
        foreach ($claimsPeriod as $claim) {
            $claimsByStatus[$claim->status] = ($claimsByStatus[$claim->status] ?? 0) + 1;
        }

        $warrantyClaimRate = $warrantiesPeriod > 0
            ? round(($claimsPeriod->count() / $warrantiesPeriod) * 100, 1)
            : null;

        $deviceCategories = CustomerAsset::query()
            ->selectRaw('category, COUNT(*) as total_count')
            ->groupBy('category')
            ->orderByDesc('total_count')
            ->get()
            ->map(fn ($row): array => [
                'category' => (string) $row->category,
                'count' => (int) $row->getAttribute('total_count'),
            ])
            ->values()
            ->all();

        $servicePreferences = array_values($servicePreferenceCounts);
        usort($servicePreferences, fn (array $a, array $b): int => $b['orders_count'] <=> $a['orders_count']);

        return [
            'period' => $period->toArray(),
            'can_view_pii' => $this->access->canViewCustomerPii($viewer),
            'overview' => [
                'total_registered_customers' => $registeredUsers->count(),
                'new_registered_period' => $newRegisteredPeriod,
                'active_ordering_customers_period' => $activeCustomersCount,
                'new_ordering_customers_period' => $newOrderingCustomersCount,
                'returning_customers_period' => $returningCustomersCount,
                'repeat_service_rate' => $repeatServiceRate,
                'avg_service_frequency' => $avgServiceFrequency,
                'avg_lifetime_value' => $avgLifetimeValue,
                'total_lifetime_invoiced_value' => $totalLifetimeValue,
                'registered_devices_total' => CustomerAsset::query()->count(),
                'warranties_issued_period' => $warrantiesPeriod,
                'active_warranties_total' => $activeWarranties,
                'warranty_claims_period' => $claimsPeriod->count(),
                'warranty_claim_rate_pct' => $warrantyClaimRate,
            ],
            'acquisition_trends' => array_values($acquisitionBuckets),
            'most_requested_services' => $servicePreferences,
            'top_lifetime_segments' => array_slice($ltvByCustomer, 0, 8),
            'device_categories' => $deviceCategories,
            'warranty_claims_by_status' => $claimsByStatus,
        ];
    }

    private function customerKey(?int $customerId, ?string $email, ?string $phone): string
    {
        if ($customerId !== null) {
            return 'u:'.$customerId;
        }

        $normalizedEmail = mb_strtolower(trim((string) $email));
        if ($normalizedEmail !== '') {
            return 'e:'.$normalizedEmail;
        }

        return 'p:'.preg_replace('/\D+/', '', (string) $phone);
    }
}
