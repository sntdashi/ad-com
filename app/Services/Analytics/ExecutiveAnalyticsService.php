<?php

namespace App\Services\Analytics;

use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderSparePart;
use App\Models\User;
use App\Services\Finance\FinancialReportService;
use App\Services\Inventory\InventoryService;
use App\Services\Sla\SlaService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class ExecutiveAnalyticsService
{
    /** @var list<string> */
    public const COMPLETED_STATUSES = ['completed', 'warranty', 'closed'];

    public function __construct(
        public FinancialReportService $financialReportService,
        public SlaService $slaService,
        public InventoryService $inventoryService,
        public AnalyticsCacheManager $cacheManager,
        public AnalyticsAccess $access,
    ) {}

    /**
     * Build executive KPI summary, period comparisons, time-series trends, and drill-downs.
     *
     * @return array<string, mixed>
     */
    public function summary(AnalyticsPeriod $period, User $viewer): array
    {
        $roleScope = $this->access->canViewRawFinanceLedger($viewer) ? 'full' : 'oversight';

        return $this->cacheManager->remember(
            namespace: 'executive',
            domains: ['orders', 'finance', 'inventory', 'customers'],
            period: $period,
            extraKey: $roleScope,
            resolver: fn (): array => $this->computeSummary($period),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function computeSummary(AnalyticsPeriod $period): array
    {
        $currentOrders = ServiceOrder::query()
            ->whereBetween('created_at', [$period->start, $period->end])
            ->get([
                'id',
                'customer_id',
                'phone',
                'email',
                'status',
                'service_mode',
                'created_at',
                'completed_at',
            ]);

        $previousOrders = ServiceOrder::query()
            ->whereBetween('created_at', [$period->previousStart, $period->previousEnd])
            ->get([
                'id',
                'customer_id',
                'phone',
                'email',
                'status',
                'created_at',
            ]);

        $currentOrderMetrics = $this->summarizeOrderCollection($currentOrders);
        $previousOrderMetrics = $this->summarizeOrderCollection($previousOrders);

        $currentSla = $this->slaService->metricsForQuery(
            ServiceOrder::query()->whereBetween('created_at', [$period->start, $period->end]),
        );
        $previousSla = $this->slaService->metricsForQuery(
            ServiceOrder::query()->whereBetween('created_at', [$period->previousStart, $period->previousEnd]),
        );

        $currentNonCancelled = $currentOrderMetrics['non_cancelled_orders'];
        $previousNonCancelled = $previousOrderMetrics['non_cancelled_orders'];

        $currentSlaRate = $currentNonCancelled > 0 ? (float) $currentSla['compliance_rate'] : null;
        $previousSlaRate = $previousNonCancelled > 0 ? (float) $previousSla['compliance_rate'] : null;

        $currentFinance = $this->financialReportService->summary(
            $period->start->toDateString(),
            $period->end->toDateString(),
        );
        $previousFinance = $this->financialReportService->summary(
            $period->previousStart->toDateString(),
            $period->previousEnd->toDateString(),
        );

        $inventorySummary = $this->inventoryService->valuationSummary();

        $currentCustomers = $this->summarizeActiveCustomers($currentOrders, $period->start, $period->end);
        $previousCustomers = $this->summarizeActiveCustomers($previousOrders, $period->previousStart, $period->previousEnd);

        $totalRegisteredCustomers = User::query()->where('role', 'customer')->count();

        $statusDistribution = $currentOrders
            ->groupBy('status')
            ->map(fn (Collection $group, string $status): array => [
                'status' => $status,
                'count' => $group->count(),
            ])
            ->values()
            ->all();

        return [
            'period' => $period->toArray(),
            'kpis' => [
                'total_orders' => $this->kpi($currentOrderMetrics['total_orders'], $previousOrderMetrics['total_orders'], 'pct'),
                'new_orders' => $this->kpi($currentOrderMetrics['new_orders'], $previousOrderMetrics['new_orders'], 'pct'),
                'active_orders' => $this->kpi($currentOrderMetrics['active_orders'], $previousOrderMetrics['active_orders'], 'pct'),
                'completed_orders' => $this->kpi($currentOrderMetrics['completed_orders'], $previousOrderMetrics['completed_orders'], 'pct'),
                'cancelled_orders' => $this->kpi($currentOrderMetrics['cancelled_orders'], $previousOrderMetrics['cancelled_orders'], 'pct'),
                'completion_rate' => $this->kpi($currentOrderMetrics['completion_rate'], $previousOrderMetrics['completion_rate'], 'pp'),
                'avg_resolution_minutes' => $this->kpi(
                    $currentSla['avg_resolution_minutes'],
                    $previousSla['avg_resolution_minutes'],
                    'pct',
                ),
                'sla_compliance_rate' => $this->kpi($currentSlaRate, $previousSlaRate, 'pp'),
                'invoiced_revenue' => $this->kpi(
                    $currentFinance['invoiced_revenue'],
                    $previousFinance['invoiced_revenue'],
                    'pct',
                    ['basis' => 'accrual'],
                ),
                'cash_received' => $this->kpi(
                    $currentFinance['cash_received'],
                    $previousFinance['cash_received'],
                    'pct',
                    ['basis' => 'cash'],
                ),
                'outstanding_receivables' => [
                    'value' => (int) $currentFinance['outstanding_receivables'],
                    'previous' => null,
                    'delta' => null,
                    'delta_unit' => 'snapshot',
                    'overdue' => (int) $currentFinance['overdue_receivables'],
                    'overdue_count' => (int) $currentFinance['overdue_invoices_count'],
                ],
                'gross_profit_accrual' => $this->kpi(
                    $currentFinance['gross_profit_accrual'],
                    $previousFinance['gross_profit_accrual'],
                    'pct',
                    ['basis' => 'accrual'],
                ),
                'operational_expenses' => $this->kpi(
                    $currentFinance['operational_expenses'],
                    $previousFinance['operational_expenses'],
                    'pct',
                ),
                'spare_part_cogs' => $this->kpi(
                    $currentFinance['spare_part_cogs'],
                    $previousFinance['spare_part_cogs'],
                    'pct',
                ),
                'net_cash_flow' => $this->kpi(
                    $currentFinance['net_cash_flow'],
                    $previousFinance['net_cash_flow'],
                    'pct',
                    ['basis' => 'cash'],
                ),
                'inventory_valuation' => [
                    'value' => (int) $inventorySummary['asset_value'],
                    'previous' => null,
                    'delta' => null,
                    'delta_unit' => 'snapshot',
                    'retail_value' => (int) $inventorySummary['retail_value'],
                    'units' => (int) $inventorySummary['units'],
                    'low_stock' => (int) $inventorySummary['low_stock'],
                    'out_of_stock' => (int) $inventorySummary['out_of_stock'],
                ],
                'active_customers' => $this->kpi($currentCustomers['active_customers'], $previousCustomers['active_customers'], 'pct'),
                'returning_customers' => $this->kpi($currentCustomers['returning_customers'], $previousCustomers['returning_customers'], 'pct'),
                'repeat_customer_rate' => $this->kpi($currentCustomers['repeat_rate'], $previousCustomers['repeat_rate'], 'pp'),
                'total_registered_customers' => [
                    'value' => $totalRegisteredCustomers,
                    'previous' => null,
                    'delta' => null,
                    'delta_unit' => 'snapshot',
                ],
            ],
            'sla_snapshot' => [
                'on_track' => (int) $currentSla['on_track'],
                'approaching_deadline' => (int) $currentSla['approaching_deadline'],
                'breached' => (int) $currentSla['breached'],
                'paused' => (int) $currentSla['paused'],
                'completed' => (int) $currentSla['completed'],
                'avg_first_response_minutes' => $currentSla['avg_first_response_minutes'],
            ],
            'status_distribution' => $statusDistribution,
            'trends' => $this->buildTrends($period, $currentOrders),
            'drill_downs' => [
                ['label' => 'Daftar Order Servis', 'href' => '/dashboard'],
                ['label' => 'Keuangan & Invoice', 'href' => '/finance'],
                ['label' => 'Monitoring Inventori', 'href' => '/inventory'],
                ['label' => 'Kinerja Workforce', 'href' => '/workforce'],
            ],
        ];
    }

    /**
     * @param  Collection<int, ServiceOrder>  $orders
     * @return array{
     *     total_orders: int,
     *     new_orders: int,
     *     active_orders: int,
     *     completed_orders: int,
     *     cancelled_orders: int,
     *     non_cancelled_orders: int,
     *     completion_rate: float|null
     * }
     */
    private function summarizeOrderCollection(Collection $orders): array
    {
        $total = $orders->count();
        $new = 0;
        $active = 0;
        $completed = 0;
        $cancelled = 0;

        foreach ($orders as $order) {
            if ($order->status === 'cancelled') {
                $cancelled++;
            } elseif (in_array($order->status, self::COMPLETED_STATUSES, true)) {
                $completed++;
            } elseif ($order->status === 'new') {
                $new++;
            } else {
                $active++;
            }
        }

        $nonCancelled = $total - $cancelled;

        return [
            'total_orders' => $total,
            'new_orders' => $new,
            'active_orders' => $active,
            'completed_orders' => $completed,
            'cancelled_orders' => $cancelled,
            'non_cancelled_orders' => $nonCancelled,
            'completion_rate' => $nonCancelled > 0 ? round(($completed / $nonCancelled) * 100, 1) : null,
        ];
    }

    /**
     * Count distinct active & returning customers without fabricating identity keys.
     *
     * @param  Collection<int, ServiceOrder>  $periodOrders
     * @return array{active_customers: int, returning_customers: int, repeat_rate: float|null}
     */
    private function summarizeActiveCustomers(Collection $periodOrders, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $nonCancelled = $periodOrders->filter(fn (ServiceOrder $order): bool => $order->status !== 'cancelled');
        if ($nonCancelled->isEmpty()) {
            return [
                'active_customers' => 0,
                'returning_customers' => 0,
                'repeat_rate' => null,
            ];
        }

        $inPeriodByKey = [];
        $customerIds = [];
        foreach ($nonCancelled as $order) {
            $key = $this->customerIdentityKey($order);
            $inPeriodByKey[$key] = ($inPeriodByKey[$key] ?? 0) + 1;
            if ($order->customer_id !== null) {
                $customerIds[] = $order->customer_id;
            }
        }

        $priorKeys = [];
        $priorOrders = ServiceOrder::query()
            ->where('status', '!=', 'cancelled')
            ->where('created_at', '<', $start)
            ->when($customerIds !== [], function ($query) use ($customerIds): void {
                $query->where(function ($nested) use ($customerIds): void {
                    $nested->whereIn('customer_id', array_values(array_unique($customerIds)))
                        ->orWhereNull('customer_id');
                });
            })
            ->get(['customer_id', 'phone', 'email']);

        foreach ($priorOrders as $prior) {
            $priorKeys[$this->customerIdentityKey($prior)] = true;
        }

        $activeCount = count($inPeriodByKey);
        $returningCount = 0;

        foreach ($inPeriodByKey as $key => $countInPeriod) {
            if ($countInPeriod >= 2 || isset($priorKeys[$key])) {
                $returningCount++;
            }
        }

        return [
            'active_customers' => $activeCount,
            'returning_customers' => $returningCount,
            'repeat_rate' => $activeCount > 0 ? round(($returningCount / $activeCount) * 100, 1) : null,
        ];
    }

    private function customerIdentityKey(ServiceOrder $order): string
    {
        if ($order->customer_id !== null) {
            return 'user:'.$order->customer_id;
        }

        $normalizedEmail = mb_strtolower(trim((string) $order->email));
        if ($normalizedEmail !== '') {
            return 'email:'.$normalizedEmail;
        }

        return 'phone:'.preg_replace('/\D+/', '', (string) $order->phone);
    }

    /**
     * Build aligned time-series points across all buckets in the selected period.
     *
     * @param  Collection<int, ServiceOrder>  $orders
     * @return list<array{
     *     key: string,
     *     label: string,
     *     orders_total: int,
     *     orders_completed: int,
     *     invoiced_revenue: int,
     *     cash_received: int,
     *     expenses: int,
     *     gross_profit: int
     * }>
     */
    private function buildTrends(AnalyticsPeriod $period, Collection $orders): array
    {
        $series = [];
        foreach ($period->buckets() as $key => $bucket) {
            $series[$key] = [
                'key' => $key,
                'label' => $bucket['label'],
                'orders_total' => 0,
                'orders_completed' => 0,
                'invoiced_revenue' => 0,
                'cash_received' => 0,
                'expenses' => 0,
                'gross_profit' => 0,
            ];
        }

        foreach ($orders as $order) {
            $key = $period->bucketKeyFor(CarbonImmutable::instance($order->created_at));
            if (! isset($series[$key])) {
                continue;
            }
            $series[$key]['orders_total']++;
            if (in_array($order->status, self::COMPLETED_STATUSES, true)) {
                $series[$key]['orders_completed']++;
            }
        }

        $invoices = Invoice::query()
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->whereDate('issue_date', '>=', $period->start->toDateString())
            ->whereDate('issue_date', '<=', $period->end->toDateString())
            ->get(['issue_date', 'total_amount']);

        foreach ($invoices as $invoice) {
            if ($invoice->issue_date === null) {
                continue;
            }
            $key = $period->bucketKeyFor(CarbonImmutable::instance($invoice->issue_date));
            if (isset($series[$key])) {
                $series[$key]['invoiced_revenue'] += $invoice->total_amount;
            }
        }

        $transactions = FinancialTransaction::query()
            ->where('status', 'verified')
            ->whereBetween('occurred_at', [$period->start, $period->end])
            ->get(['type', 'category', 'amount', 'occurred_at']);

        $opExpensesByKey = [];
        foreach ($transactions as $tx) {
            $key = $period->bucketKeyFor(CarbonImmutable::instance($tx->occurred_at));
            if (! isset($series[$key])) {
                continue;
            }

            if ($tx->type === 'income') {
                $series[$key]['cash_received'] += $tx->amount;
            } elseif ($tx->type === 'expense') {
                $series[$key]['expenses'] += $tx->amount;
                if ($tx->category !== 'inventory_purchase') {
                    $opExpensesByKey[$key] = ($opExpensesByKey[$key] ?? 0) + $tx->amount;
                }
            }
        }

        $cogsParts = ServiceOrderSparePart::query()
            ->whereIn('status', ['issued', 'partially_returned'])
            ->whereBetween('issued_at', [$period->start, $period->end])
            ->get(['issued_at', 'issued_quantity', 'returned_quantity', 'unit_cost']);

        $cogsByKey = [];
        foreach ($cogsParts as $part) {
            if ($part->issued_at === null) {
                continue;
            }
            $key = $period->bucketKeyFor(CarbonImmutable::instance($part->issued_at));
            if (! isset($series[$key])) {
                continue;
            }
            $netQty = max(0, $part->issued_quantity - $part->returned_quantity);
            $cogsByKey[$key] = ($cogsByKey[$key] ?? 0) + ($netQty * $part->unit_cost);
        }

        foreach ($series as $key => $point) {
            $series[$key]['gross_profit'] = $point['invoiced_revenue']
                - ($cogsByKey[$key] ?? 0)
                - ($opExpensesByKey[$key] ?? 0);
        }

        return array_values($series);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function kpi(int|float|null $current, int|float|null $previous, string $deltaMode, array $extra = []): array
    {
        $delta = $deltaMode === 'pp'
            ? AnalyticsPeriod::pointDelta($current, $previous)
            : AnalyticsPeriod::percentageDelta($current, $previous);

        return [
            'value' => $current,
            'previous' => $previous,
            'delta' => $delta,
            'delta_unit' => $deltaMode,
            ...$extra,
        ];
    }
}
