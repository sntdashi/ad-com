<?php

namespace App\Services\Analytics;

use App\Models\Estimate;
use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderSparePart;
use Illuminate\Support\Collection;

class ServiceSalesAnalyticsService
{
    public function __construct(
        public AnalyticsCacheManager $cacheManager,
    ) {}

    /**
     * Build Service & Sales analytics (demand, conversion, quotations, true net profitability).
     *
     * @return array<string, mixed>
     */
    public function summary(AnalyticsPeriod $period): array
    {
        return $this->cacheManager->remember(
            namespace: 'services',
            domains: ['orders', 'finance', 'inventory'],
            period: $period,
            resolver: fn (): array => $this->computeSummary($period),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function computeSummary(AnalyticsPeriod $period): array
    {
        $services = Service::query()
            ->orderBy('sort_order')
            ->get(['id', 'code', 'name', 'service_mode', 'risk_level', 'is_active'])
            ->keyBy('id');

        $currentOrders = ServiceOrder::query()
            ->whereBetween('created_at', [$period->start, $period->end])
            ->get([
                'id',
                'service_id',
                'customer_id',
                'phone',
                'email',
                'service_mode',
                'risk_level',
                'status',
                'created_at',
            ]);

        $previousOrders = ServiceOrder::query()
            ->whereBetween('created_at', [$period->previousStart, $period->previousEnd])
            ->get(['id', 'service_id', 'status']);

        $prevCountsByService = [];
        foreach ($previousOrders as $prevOrder) {
            if ($prevOrder->status === 'cancelled') {
                continue;
            }
            $sid = (int) $prevOrder->service_id;
            $prevCountsByService[$sid] = ($prevCountsByService[$sid] ?? 0) + 1;
        }

        $serviceRows = [];
        foreach ($services as $service) {
            $sid = (int) $service->id;
            $serviceRows[$sid] = [
                'service_id' => $sid,
                'code' => $service->code,
                'name' => $service->name,
                'service_mode' => $service->service_mode,
                'risk_level' => $service->risk_level,
                'requests_count' => 0,
                'non_cancelled_count' => 0,
                'previous_requests_count' => (int) ($prevCountsByService[$sid] ?? 0),
                'demand_change_pct' => null,
                'completed_count' => 0,
                'cancelled_count' => 0,
                'cancellation_rate' => null,
                'invoiced_revenue' => 0,
                'spare_part_cogs' => 0,
                'direct_expenses' => 0,
                'gross_profit' => 0,
                'margin_pct' => null,
            ];
        }

        $modeDistribution = [];
        $riskDistribution = [];
        $totalOrders = $currentOrders->count();
        $totalCancelled = 0;

        foreach ($currentOrders as $order) {
            $sid = (int) $order->service_id;
            if (isset($serviceRows[$sid])) {
                $serviceRows[$sid]['requests_count']++;
                if ($order->status === 'cancelled') {
                    $serviceRows[$sid]['cancelled_count']++;
                } else {
                    $serviceRows[$sid]['non_cancelled_count']++;
                    if (in_array($order->status, ExecutiveAnalyticsService::COMPLETED_STATUSES, true)) {
                        $serviceRows[$sid]['completed_count']++;
                    }
                }
            }

            if ($order->status === 'cancelled') {
                $totalCancelled++;
            }

            $mode = $order->service_mode ?: 'onsite';
            $modeDistribution[$mode] = ($modeDistribution[$mode] ?? 0) + 1;

            $risk = $order->risk_level ?: 'medium';
            $riskDistribution[$risk] = ($riskDistribution[$risk] ?? 0) + 1;
        }

        $invoices = Invoice::query()
            ->with(['order:id,service_id'])
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->whereDate('issue_date', '>=', $period->start->toDateString())
            ->whereDate('issue_date', '<=', $period->end->toDateString())
            ->get(['id', 'service_order_id', 'total_amount']);

        foreach ($invoices as $invoice) {
            $sid = $invoice->order?->service_id;
            if ($sid !== null && isset($serviceRows[(int) $sid])) {
                $serviceRows[(int) $sid]['invoiced_revenue'] += $invoice->total_amount;
            }
        }

        $parts = ServiceOrderSparePart::query()
            ->with(['order:id,service_id'])
            ->whereIn('status', ['issued', 'partially_returned'])
            ->whereBetween('issued_at', [$period->start, $period->end])
            ->get(['id', 'service_order_id', 'issued_quantity', 'returned_quantity', 'unit_cost']);

        foreach ($parts as $part) {
            $sid = (int) $part->order->service_id;
            if (isset($serviceRows[$sid])) {
                $netQty = max(0, $part->issued_quantity - $part->returned_quantity);
                $serviceRows[$sid]['spare_part_cogs'] += $netQty * $part->unit_cost;
            }
        }

        $directExpenses = FinancialTransaction::query()
            ->with(['order:id,service_id'])
            ->whereNotNull('service_order_id')
            ->where('type', 'expense')
            ->where('status', 'verified')
            ->where('category', '!=', 'inventory_purchase')
            ->whereBetween('occurred_at', [$period->start, $period->end])
            ->get(['id', 'service_order_id', 'amount']);

        foreach ($directExpenses as $expense) {
            $sid = $expense->order?->service_id;
            if ($sid !== null && isset($serviceRows[(int) $sid])) {
                $serviceRows[(int) $sid]['direct_expenses'] += $expense->amount;
            }
        }

        foreach ($serviceRows as $sid => $row) {
            $profit = $row['invoiced_revenue'] - $row['spare_part_cogs'] - $row['direct_expenses'];
            $serviceRows[$sid]['gross_profit'] = $profit;
            $serviceRows[$sid]['margin_pct'] = $row['invoiced_revenue'] > 0
                ? round(($profit / $row['invoiced_revenue']) * 100, 1)
                : null;
            $serviceRows[$sid]['cancellation_rate'] = $row['requests_count'] > 0
                ? round(($row['cancelled_count'] / $row['requests_count']) * 100, 1)
                : null;
            $serviceRows[$sid]['demand_change_pct'] = AnalyticsPeriod::percentageDelta(
                $row['non_cancelled_count'],
                $row['previous_requests_count'],
            );
        }

        $allServicesList = array_values($serviceRows);

        $mostRequested = $allServicesList;
        usort($mostRequested, fn (array $a, array $b): int => $b['requests_count'] <=> $a['requests_count']);

        $mostProfitable = $allServicesList;
        usort($mostProfitable, fn (array $a, array $b): int => $b['gross_profit'] <=> $a['gross_profit']);

        $withRevenue = array_values(array_filter(
            $allServicesList,
            fn (array $row): bool => $row['invoiced_revenue'] > 0 && $row['margin_pct'] !== null,
        ));
        usort($withRevenue, fn (array $a, array $b): int => $a['margin_pct'] <=> $b['margin_pct']);

        $currentQuotes = $this->summarizeQuotations($period->start, $period->end);
        $previousQuotes = $this->summarizeQuotations($period->previousStart, $period->previousEnd);

        $ordersWithInvoices = $invoices->pluck('service_order_id')->filter()->unique()->count();
        $bookingToInvoiceRate = $totalOrders > 0 ? round(($ordersWithInvoices / $totalOrders) * 100, 1) : null;
        $avgInvoiceValue = $invoices->isNotEmpty() ? (int) round($invoices->avg('total_amount') ?? 0) : null;

        $repeatOrdersRate = $this->computeRepeatOrderShare($currentOrders, $period->start);

        $risingServices = array_values(array_filter(
            $allServicesList,
            fn (array $row): bool => ($row['demand_change_pct'] ?? 0.0) > 0.0,
        ));
        $contractingServices = array_values(array_filter(
            $allServicesList,
            fn (array $row): bool => $row['demand_change_pct'] !== null && $row['demand_change_pct'] < 0.0,
        ));

        return [
            'period' => $period->toArray(),
            'overview' => [
                'total_requests' => $totalOrders,
                'cancellation_rate' => $totalOrders > 0 ? round(($totalCancelled / $totalOrders) * 100, 1) : null,
                'booking_to_invoice_conversion_rate' => $bookingToInvoiceRate,
                'repeat_service_rate' => $repeatOrdersRate,
                'avg_invoice_value' => $avgInvoiceValue,
                'total_quotations' => $currentQuotes['total'],
                'avg_quotation_value' => $currentQuotes['avg_value'],
                'quotation_approval_rate' => $currentQuotes['approval_rate'],
                'previous_quotation_approval_rate' => $previousQuotes['approval_rate'],
                'quotation_approval_delta_pp' => AnalyticsPeriod::pointDelta(
                    $currentQuotes['approval_rate'],
                    $previousQuotes['approval_rate'],
                ),
                'quotation_rejection_rate' => $currentQuotes['rejection_rate'],
                'quotation_conversion_rate' => $currentQuotes['conversion_rate'],
            ],
            'quotations' => $currentQuotes,
            'services' => $mostRequested,
            'most_requested' => array_slice($mostRequested, 0, 5),
            'most_profitable' => array_slice($mostProfitable, 0, 5),
            'lowest_margin' => array_slice($withRevenue, 0, 5),
            'demand_momentum' => [
                'rising' => $risingServices,
                'contracting' => $contractingServices,
            ],
            'service_mode_distribution' => collect($modeDistribution)
                ->map(fn (int $count, string $mode): array => ['mode' => $mode, 'count' => $count])
                ->values()
                ->all(),
            'risk_level_distribution' => collect($riskDistribution)
                ->map(fn (int $count, string $risk): array => ['risk_level' => $risk, 'count' => $count])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summarizeQuotations(mixed $start, mixed $end): array
    {
        $estimates = Estimate::query()
            ->whereBetween('created_at', [$start, $end])
            ->get(['status', 'valid_until', 'total']);

        $counts = [
            'draft' => 0,
            'sent' => 0,
            'approved' => 0,
            'rejected' => 0,
            'expired' => 0,
            'converted' => 0,
        ];

        $totalValue = 0;
        foreach ($estimates as $estimate) {
            $status = $estimate->resolvedStatus();
            $counts[$status] = ($counts[$status] ?? 0) + 1;
            $totalValue += $estimate->total;
        }

        $total = $estimates->count();
        $approvedAndConverted = $counts['approved'] + $counts['converted'];
        $decided = $approvedAndConverted + $counts['rejected'];

        return [
            'total' => $total,
            'decided' => $decided,
            'avg_value' => $total > 0 ? (int) round($totalValue / $total) : null,
            'approval_rate' => $decided > 0 ? round(($approvedAndConverted / $decided) * 100, 1) : null,
            'rejection_rate' => $decided > 0 ? round(($counts['rejected'] / $decided) * 100, 1) : null,
            'conversion_rate' => $approvedAndConverted > 0 ? round(($counts['converted'] / $approvedAndConverted) * 100, 1) : null,
            'by_status' => $counts,
        ];
    }

    /**
     * Share of non-cancelled orders in period belonging to customers who already had an earlier order.
     *
     * @param  Collection<int, ServiceOrder>  $orders
     */
    private function computeRepeatOrderShare(Collection $orders, mixed $periodStart): ?float
    {
        $nonCancelled = $orders->filter(fn (ServiceOrder $order): bool => $order->status !== 'cancelled');
        if ($nonCancelled->isEmpty()) {
            return null;
        }

        $priorCustomerKeys = [];
        $priorOrders = ServiceOrder::query()
            ->where('status', '!=', 'cancelled')
            ->where('created_at', '<', $periodStart)
            ->get(['customer_id', 'phone', 'email']);

        foreach ($priorOrders as $prior) {
            $priorCustomerKeys[$this->customerKey($prior)] = true;
        }

        $seenInPeriod = [];
        $repeatOrders = 0;

        foreach ($nonCancelled->sortBy('created_at') as $order) {
            $key = $this->customerKey($order);
            if (isset($priorCustomerKeys[$key]) || isset($seenInPeriod[$key])) {
                $repeatOrders++;
            }
            $seenInPeriod[$key] = true;
        }

        return round(($repeatOrders / $nonCancelled->count()) * 100, 1);
    }

    private function customerKey(ServiceOrder $order): string
    {
        if ($order->customer_id !== null) {
            return 'u:'.$order->customer_id;
        }

        $email = mb_strtolower(trim((string) $order->email));
        if ($email !== '') {
            return 'e:'.$email;
        }

        return 'p:'.preg_replace('/\D+/', '', (string) $order->phone);
    }
}
