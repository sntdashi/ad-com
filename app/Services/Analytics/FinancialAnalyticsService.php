<?php

namespace App\Services\Analytics;

use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderSparePart;
use App\Models\User;
use App\Services\Finance\FinancialReportService;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class FinancialAnalyticsService
{
    public function __construct(
        public FinancialReportService $financialReportService,
        public AnalyticsCacheManager $cacheManager,
        public AnalyticsAccess $access,
    ) {}

    /**
     * Build financial BI payload respecting viewer visibility (raw ledger vs oversight).
     *
     * @return array<string, mixed>
     */
    public function report(AnalyticsPeriod $period, User $viewer, int $page = 1, int $perPage = 10): array
    {
        $canViewRaw = $this->access->canViewRawFinanceLedger($viewer);
        $canViewPii = $this->access->canViewCustomerPii($viewer);
        $page = max(1, $page);
        $perPage = max(5, min(25, $perPage));

        return $this->cacheManager->remember(
            namespace: 'financial',
            domains: ['finance', 'orders', 'inventory', 'customers'],
            period: $period,
            extraKey: sprintf('raw:%d|pii:%d|p:%d|pp:%d', (int) $canViewRaw, (int) $canViewPii, $page, $perPage),
            resolver: fn (): array => $this->computeReport($period, $viewer, $canViewRaw, $page, $perPage),
        );
    }

    /**
     * Collect all order profitability rows for CSV export within period limits.
     *
     * @return list<array<string, mixed>>
     */
    public function profitabilityRowsForExport(AnalyticsPeriod $period, User $viewer): array
    {
        $orders = ServiceOrder::query()
            ->with(['service:id,code,name', 'invoices', 'financialTransactions', 'spareParts', 'estimates'])
            ->whereBetween('created_at', [$period->start, $period->end])
            ->where('status', '!=', 'cancelled')
            ->latest()
            ->get();

        return $this->mapOrderProfitabilityRows($orders, $viewer);
    }

    /**
     * @return array<string, mixed>
     */
    private function computeReport(
        AnalyticsPeriod $period,
        User $viewer,
        bool $canViewRaw,
        int $page,
        int $perPage,
    ): array {
        $currentSummary = $this->financialReportService->summary(
            $period->start->toDateString(),
            $period->end->toDateString(),
        );
        $previousSummary = $this->financialReportService->summary(
            $period->previousStart->toDateString(),
            $period->previousEnd->toDateString(),
        );

        $comparisons = [
            'invoiced_revenue' => [
                'current' => $currentSummary['invoiced_revenue'],
                'previous' => $previousSummary['invoiced_revenue'],
                'delta_pct' => AnalyticsPeriod::percentageDelta($currentSummary['invoiced_revenue'], $previousSummary['invoiced_revenue']),
                'basis' => 'accrual',
            ],
            'cash_received' => [
                'current' => $currentSummary['cash_received'],
                'previous' => $previousSummary['cash_received'],
                'delta_pct' => AnalyticsPeriod::percentageDelta($currentSummary['cash_received'], $previousSummary['cash_received']),
                'basis' => 'cash',
            ],
            'gross_profit_accrual' => [
                'current' => $currentSummary['gross_profit_accrual'],
                'previous' => $previousSummary['gross_profit_accrual'],
                'delta_pct' => AnalyticsPeriod::percentageDelta($currentSummary['gross_profit_accrual'], $previousSummary['gross_profit_accrual']),
                'basis' => 'accrual',
            ],
            'net_cash_flow' => [
                'current' => $currentSummary['net_cash_flow'],
                'previous' => $previousSummary['net_cash_flow'],
                'delta_pct' => AnalyticsPeriod::percentageDelta($currentSummary['net_cash_flow'], $previousSummary['net_cash_flow']),
                'basis' => 'cash',
            ],
            'operational_expenses' => [
                'current' => $canViewRaw ? $currentSummary['operational_expenses'] : null,
                'previous' => $canViewRaw ? $previousSummary['operational_expenses'] : null,
                'delta_pct' => $canViewRaw
                    ? AnalyticsPeriod::percentageDelta($currentSummary['operational_expenses'], $previousSummary['operational_expenses'])
                    : null,
                'basis' => 'cash',
            ],
            'spare_part_cogs' => [
                'current' => $currentSummary['spare_part_cogs'],
                'previous' => $previousSummary['spare_part_cogs'],
                'delta_pct' => AnalyticsPeriod::percentageDelta($currentSummary['spare_part_cogs'], $previousSummary['spare_part_cogs']),
                'basis' => 'accrual',
            ],
        ];

        $periodOrders = ServiceOrder::query()
            ->with(['service:id,code,name', 'invoices', 'financialTransactions', 'spareParts', 'estimates'])
            ->whereBetween('created_at', [$period->start, $period->end])
            ->where('status', '!=', 'cancelled')
            ->latest()
            ->get();

        $allProfitRows = $this->mapOrderProfitabilityRows($periodOrders, $viewer);
        $paginator = new LengthAwarePaginator(
            items: array_slice($allProfitRows, ($page - 1) * $perPage, $perPage),
            total: count($allProfitRows),
            perPage: $perPage,
            currentPage: $page,
        );

        $expenseBreakdown = $canViewRaw ? $this->computeExpenseBreakdown($period) : [];
        $topCustomers = $this->computeTopCustomers($period, $viewer);
        $serviceBreakdown = $this->computeServiceRevenueAndMargin($period);
        $anomalies = $this->detectAnomalies($currentSummary, $previousSummary, $allProfitRows, $canViewRaw);

        return [
            'period' => $period->toArray(),
            'can_view_raw_ledger' => $canViewRaw,
            'summary' => [
                ...$currentSummary,
                'operational_expenses' => $canViewRaw ? $currentSummary['operational_expenses'] : null,
                'inventory_purchase_expenses' => $canViewRaw ? $currentSummary['inventory_purchase_expenses'] : null,
                'verified_expenses_total' => $canViewRaw ? $currentSummary['verified_expenses_total'] : null,
                'pending_expenses_total' => $canViewRaw ? $currentSummary['pending_expenses_total'] : null,
            ],
            'comparisons' => $comparisons,
            'trends' => $this->computeFinancialTrends($period, $canViewRaw),
            'receivables_aging' => $this->computeReceivablesAging($period->timezone),
            'invoice_status_breakdown' => $this->computeInvoiceStatusBreakdown($period),
            'revenue_by_service' => $serviceBreakdown,
            'top_customers' => $topCustomers,
            'expense_breakdown' => $expenseBreakdown,
            'spare_part_cogs_analysis' => $this->computeSparePartCogsAnalysis($period),
            'estimated_vs_actual' => $this->computeEstimatedVsActual($allProfitRows),
            'order_profitability' => [
                'data' => array_values($paginator->items()),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'anomalies' => $anomalies,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function computeFinancialTrends(AnalyticsPeriod $period, bool $canViewRaw): array
    {
        $buckets = [];
        foreach ($period->buckets() as $key => $bucket) {
            $buckets[$key] = [
                'key' => $key,
                'label' => $bucket['label'],
                'invoiced_revenue' => 0,
                'cash_received' => 0,
                'spare_part_cogs' => 0,
                'operational_expenses' => 0,
                'inventory_purchase_expenses' => 0,
                'gross_profit_accrual' => 0,
                'net_cash_flow' => 0,
            ];
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
            if (isset($buckets[$key])) {
                $buckets[$key]['invoiced_revenue'] += $invoice->total_amount;
            }
        }

        $transactions = FinancialTransaction::query()
            ->where('status', 'verified')
            ->whereBetween('occurred_at', [$period->start, $period->end])
            ->get(['type', 'category', 'amount', 'occurred_at']);

        foreach ($transactions as $tx) {
            $key = $period->bucketKeyFor(CarbonImmutable::instance($tx->occurred_at));
            if (! isset($buckets[$key])) {
                continue;
            }

            if ($tx->type === 'income') {
                $buckets[$key]['cash_received'] += $tx->amount;
            } elseif ($tx->type === 'expense') {
                if ($tx->category === 'inventory_purchase') {
                    $buckets[$key]['inventory_purchase_expenses'] += $tx->amount;
                } else {
                    $buckets[$key]['operational_expenses'] += $tx->amount;
                }
            }
        }

        $cogsParts = ServiceOrderSparePart::query()
            ->whereIn('status', ['issued', 'partially_returned'])
            ->whereBetween('issued_at', [$period->start, $period->end])
            ->get(['issued_at', 'issued_quantity', 'returned_quantity', 'unit_cost']);

        foreach ($cogsParts as $part) {
            if ($part->issued_at === null) {
                continue;
            }
            $key = $period->bucketKeyFor(CarbonImmutable::instance($part->issued_at));
            if (! isset($buckets[$key])) {
                continue;
            }
            $netQty = max(0, $part->issued_quantity - $part->returned_quantity);
            $buckets[$key]['spare_part_cogs'] += $netQty * $part->unit_cost;
        }

        foreach ($buckets as $key => $point) {
            $verifiedExpenses = $point['operational_expenses'] + $point['inventory_purchase_expenses'];
            $buckets[$key]['gross_profit_accrual'] = $point['invoiced_revenue']
                - $point['spare_part_cogs']
                - $point['operational_expenses'];
            $buckets[$key]['net_cash_flow'] = $point['cash_received'] - $verifiedExpenses;

            if (! $canViewRaw) {
                $buckets[$key]['operational_expenses'] = 0;
                $buckets[$key]['inventory_purchase_expenses'] = 0;
            }
        }

        return array_values($buckets);
    }

    /**
     * Compute live accounts receivable aging buckets based on due_date.
     *
     * @return list<array{bucket: string, label: string, count: int, amount: int}>
     */
    private function computeReceivablesAging(string $timezone): array
    {
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $buckets = [
            'current' => ['bucket' => 'current', 'label' => 'Belum Jatuh Tempo', 'count' => 0, 'amount' => 0],
            '1_30' => ['bucket' => '1_30', 'label' => '1–30 Hari', 'count' => 0, 'amount' => 0],
            '31_60' => ['bucket' => '31_60', 'label' => '31–60 Hari', 'count' => 0, 'amount' => 0],
            'over_60' => ['bucket' => 'over_60', 'label' => '> 60 Hari', 'count' => 0, 'amount' => 0],
        ];

        $openInvoices = Invoice::query()
            ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
            ->get(['id', 'status', 'due_date', 'total_amount', 'paid_amount']);

        foreach ($openInvoices as $invoice) {
            $balance = $invoice->outstandingBalance();
            if ($balance <= 0) {
                continue;
            }

            if ($invoice->due_date === null || $invoice->due_date->gte($today)) {
                $key = 'current';
            } else {
                $overdueDays = (int) CarbonImmutable::instance($invoice->due_date)->startOfDay()->diffInDays($today);
                $key = match (true) {
                    $overdueDays <= 30 => '1_30',
                    $overdueDays <= 60 => '31_60',
                    default => 'over_60',
                };
            }

            $buckets[$key]['count']++;
            $buckets[$key]['amount'] += $balance;
        }

        return array_values($buckets);
    }

    /**
     * @return list<array{status: string, count: int, total_amount: int, paid_amount: int, outstanding_amount: int}>
     */
    private function computeInvoiceStatusBreakdown(AnalyticsPeriod $period): array
    {
        $invoices = Invoice::query()
            ->where(function ($query) use ($period): void {
                $query->where(function ($issued) use ($period): void {
                    $issued->whereNotNull('issue_date')
                        ->whereDate('issue_date', '>=', $period->start->toDateString())
                        ->whereDate('issue_date', '<=', $period->end->toDateString());
                })->orWhere(function ($draft) use ($period): void {
                    $draft->whereNull('issue_date')
                        ->whereBetween('created_at', [$period->start, $period->end]);
                });
            })
            ->get(['status', 'due_date', 'total_amount', 'paid_amount']);

        $grouped = [];
        foreach ($invoices as $invoice) {
            $resolvedStatus = $invoice->resolvedStatus();
            if (! isset($grouped[$resolvedStatus])) {
                $grouped[$resolvedStatus] = [
                    'status' => $resolvedStatus,
                    'count' => 0,
                    'total_amount' => 0,
                    'paid_amount' => 0,
                    'outstanding_amount' => 0,
                ];
            }

            $grouped[$resolvedStatus]['count']++;
            $grouped[$resolvedStatus]['total_amount'] += $invoice->total_amount;
            $grouped[$resolvedStatus]['paid_amount'] += $invoice->paid_amount;
            $grouped[$resolvedStatus]['outstanding_amount'] += $invoice->outstandingBalance();
        }

        return array_values($grouped);
    }

    /**
     * Compute invoiced revenue, spare-part COGS, direct expenses, and net margin grouped by Service.
     *
     * @return list<array{
     *     service_id: int,
     *     code: string,
     *     name: string,
     *     service_mode: string,
     *     invoiced_revenue: int,
     *     spare_part_cogs: int,
     *     direct_expenses: int,
     *     gross_profit: int,
     *     margin_pct: float|null
     * }>
     */
    private function computeServiceRevenueAndMargin(AnalyticsPeriod $period): array
    {
        $invoices = Invoice::query()
            ->with(['order.service:id,code,name,service_mode'])
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->whereDate('issue_date', '>=', $period->start->toDateString())
            ->whereDate('issue_date', '<=', $period->end->toDateString())
            ->get();

        $byService = [];

        foreach ($invoices as $invoice) {
            $service = $invoice->order?->service;
            if ($service === null) {
                continue;
            }
            $sid = (int) $service->id;
            if (! isset($byService[$sid])) {
                $byService[$sid] = [
                    'service_id' => $sid,
                    'code' => $service->code,
                    'name' => $service->name,
                    'service_mode' => $service->service_mode,
                    'invoiced_revenue' => 0,
                    'spare_part_cogs' => 0,
                    'direct_expenses' => 0,
                    'gross_profit' => 0,
                    'margin_pct' => null,
                ];
            }
            $byService[$sid]['invoiced_revenue'] += $invoice->total_amount;
        }

        $cogsParts = ServiceOrderSparePart::query()
            ->with(['order.service:id,code,name,service_mode'])
            ->whereIn('status', ['issued', 'partially_returned'])
            ->whereBetween('issued_at', [$period->start, $period->end])
            ->get();

        foreach ($cogsParts as $part) {
            $service = $part->order->service;
            $sid = (int) $service->id;
            if (! isset($byService[$sid])) {
                $byService[$sid] = [
                    'service_id' => $sid,
                    'code' => $service->code,
                    'name' => $service->name,
                    'service_mode' => $service->service_mode,
                    'invoiced_revenue' => 0,
                    'spare_part_cogs' => 0,
                    'direct_expenses' => 0,
                    'gross_profit' => 0,
                    'margin_pct' => null,
                ];
            }
            $netQty = max(0, $part->issued_quantity - $part->returned_quantity);
            $byService[$sid]['spare_part_cogs'] += $netQty * $part->unit_cost;
        }

        $directExpenses = FinancialTransaction::query()
            ->with(['order.service:id,code,name,service_mode'])
            ->whereNotNull('service_order_id')
            ->where('type', 'expense')
            ->where('status', 'verified')
            ->where('category', '!=', 'inventory_purchase')
            ->whereBetween('occurred_at', [$period->start, $period->end])
            ->get();

        foreach ($directExpenses as $expense) {
            $service = $expense->order?->service;
            if ($service === null) {
                continue;
            }
            $sid = (int) $service->id;
            if (! isset($byService[$sid])) {
                $byService[$sid] = [
                    'service_id' => $sid,
                    'code' => $service->code,
                    'name' => $service->name,
                    'service_mode' => $service->service_mode,
                    'invoiced_revenue' => 0,
                    'spare_part_cogs' => 0,
                    'direct_expenses' => 0,
                    'gross_profit' => 0,
                    'margin_pct' => null,
                ];
            }
            $byService[$sid]['direct_expenses'] += $expense->amount;
        }

        foreach ($byService as $sid => $row) {
            $profit = $row['invoiced_revenue'] - $row['spare_part_cogs'] - $row['direct_expenses'];
            $byService[$sid]['gross_profit'] = $profit;
            $byService[$sid]['margin_pct'] = $row['invoiced_revenue'] > 0
                ? round(($profit / $row['invoiced_revenue']) * 100, 1)
                : null;
        }

        usort($byService, fn (array $a, array $b): int => $b['invoiced_revenue'] <=> $a['invoiced_revenue']);

        return $byService;
    }

    /**
     * @return list<array{customer_label: string, invoice_count: int, invoiced_revenue: int, cash_paid: int}>
     */
    private function computeTopCustomers(AnalyticsPeriod $period, User $viewer): array
    {
        $invoices = Invoice::query()
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->whereDate('issue_date', '>=', $period->start->toDateString())
            ->whereDate('issue_date', '<=', $period->end->toDateString())
            ->get(['customer_id', 'customer_name', 'customer_email', 'total_amount', 'paid_amount']);

        $grouped = [];
        foreach ($invoices as $invoice) {
            $key = $invoice->customer_id !== null
                ? 'user:'.$invoice->customer_id
                : 'name:'.mb_strtolower(trim($invoice->customer_name));

            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'customer_label' => $this->access->formatCustomerLabel(
                        $invoice->customer_name,
                        $invoice->customer_email,
                        $viewer,
                        $invoice->customer_id,
                    ),
                    'invoice_count' => 0,
                    'invoiced_revenue' => 0,
                    'cash_paid' => 0,
                ];
            }

            $grouped[$key]['invoice_count']++;
            $grouped[$key]['invoiced_revenue'] += $invoice->total_amount;
            $grouped[$key]['cash_paid'] += $invoice->paid_amount;
        }

        usort($grouped, fn (array $a, array $b): int => $b['invoiced_revenue'] <=> $a['invoiced_revenue']);

        return array_slice($grouped, 0, 8);
    }

    /**
     * @return list<array{category: string, count: int, total_amount: int}>
     */
    private function computeExpenseBreakdown(AnalyticsPeriod $period): array
    {
        $rows = FinancialTransaction::query()
            ->where('type', 'expense')
            ->where('status', 'verified')
            ->whereBetween('occurred_at', [$period->start, $period->end])
            ->selectRaw('category, COUNT(*) as tx_count, COALESCE(SUM(amount), 0) as total_amount')
            ->groupBy('category')
            ->orderByDesc('total_amount')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'category' => (string) $row->category,
                'count' => (int) $row->getAttribute('tx_count'),
                'total_amount' => (int) $row->getAttribute('total_amount'),
            ];
        }

        return $result;
    }

    /**
     * @return list<array{item_id: int, sku: string, name: string, category: string, net_units: int, total_cogs: int, avg_unit_cost: int}>
     */
    private function computeSparePartCogsAnalysis(AnalyticsPeriod $period): array
    {
        $parts = ServiceOrderSparePart::query()
            ->with(['item:id,sku,name,category'])
            ->whereIn('status', ['issued', 'partially_returned'])
            ->whereBetween('issued_at', [$period->start, $period->end])
            ->get();

        $grouped = [];
        foreach ($parts as $part) {
            $netQty = max(0, $part->issued_quantity - $part->returned_quantity);
            if ($netQty === 0) {
                continue;
            }

            $itemId = $part->inventory_item_id;
            if (! isset($grouped[$itemId])) {
                $grouped[$itemId] = [
                    'item_id' => $itemId,
                    'sku' => $part->item->sku,
                    'name' => $part->item->name,
                    'category' => $part->item->category,
                    'net_units' => 0,
                    'total_cogs' => 0,
                    'avg_unit_cost' => 0,
                ];
            }

            $grouped[$itemId]['net_units'] += $netQty;
            $grouped[$itemId]['total_cogs'] += $netQty * $part->unit_cost;
        }

        foreach ($grouped as $itemId => $row) {
            $grouped[$itemId]['avg_unit_cost'] = (int) round($row['total_cogs'] / $row['net_units']);
        }

        usort($grouped, fn (array $a, array $b): int => $b['total_cogs'] <=> $a['total_cogs']);

        return array_slice($grouped, 0, 10);
    }

    /**
     * @param  Collection<int, ServiceOrder>  $orders
     * @return list<array<string, mixed>>
     */
    private function mapOrderProfitabilityRows(Collection $orders, User $viewer): array
    {
        $rows = [];
        foreach ($orders as $order) {
            $breakdown = $this->financialReportService->orderBreakdown($order);
            $invoiced = (int) $breakdown['invoiced_total'];
            $cogs = (int) $breakdown['spare_part_cogs'];
            $direct = (int) $breakdown['direct_expenses'];
            $grossProfit = (int) $breakdown['gross_profit'];
            $estimated = (int) $breakdown['estimated_total'];
            $actualTotalCost = $cogs + $direct;

            $rows[] = [
                'service_order_id' => $order->id,
                'ticket_number' => $order->ticket_number,
                'service_name' => $order->service->name,
                'customer_label' => $this->access->formatCustomerLabel($order->customer_name, $order->email, $viewer, $order->customer_id),
                'status' => $order->status,
                'estimated_total' => $estimated,
                'invoiced_total' => $invoiced,
                'cash_received' => (int) $breakdown['cash_received'],
                'outstanding_receivables' => (int) $breakdown['outstanding_receivables'],
                'spare_part_cogs' => $cogs,
                'direct_expenses' => $direct,
                'actual_cost_total' => $actualTotalCost,
                'estimate_variance' => $invoiced - $estimated,
                'gross_profit' => $grossProfit,
                'margin_pct' => $invoiced > 0 ? round(($grossProfit / $invoiced) * 100, 1) : null,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $profitRows
     * @return array{orders_count: int, total_estimated: int, total_invoiced: int, total_actual_cost: int, variance_estimated_vs_invoiced: int}
     */
    private function computeEstimatedVsActual(array $profitRows): array
    {
        $totalEstimated = 0;
        $totalInvoiced = 0;
        $totalActualCost = 0;

        foreach ($profitRows as $row) {
            $totalEstimated += (int) $row['estimated_total'];
            $totalInvoiced += (int) $row['invoiced_total'];
            $totalActualCost += (int) $row['actual_cost_total'];
        }

        return [
            'orders_count' => count($profitRows),
            'total_estimated' => $totalEstimated,
            'total_invoiced' => $totalInvoiced,
            'total_actual_cost' => $totalActualCost,
            'variance_estimated_vs_invoiced' => $totalInvoiced - $totalEstimated,
        ];
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $previous
     * @param  list<array<string, mixed>>  $profitRows
     * @return list<array{code: string, title: string, severity: string, detail: string}>
     */
    private function detectAnomalies(array $current, array $previous, array $profitRows, bool $canViewRaw): array
    {
        $anomalies = [];
        $expenseSpikeThreshold = (float) config('analytics.thresholds.expense_spike_pct', 25.0);

        if ($canViewRaw) {
            $opDelta = AnalyticsPeriod::percentageDelta($current['operational_expenses'], $previous['operational_expenses']);
            if ($opDelta !== null && $opDelta >= $expenseSpikeThreshold && $current['operational_expenses'] > 0) {
                $anomalies[] = [
                    'code' => 'operational_expense_spike',
                    'title' => 'Lonjakan Beban Operasional',
                    'severity' => 'warning',
                    'detail' => sprintf('Beban operasional naik %.1f%% dibanding periode sebelumnya.', $opDelta),
                ];
            }
        }

        if ((int) $current['overdue_receivables'] > 0) {
            $anomalies[] = [
                'code' => 'overdue_receivables',
                'title' => 'Piutang Jatuh Tempo Aktif',
                'severity' => 'warning',
                'detail' => sprintf(
                    '%d invoice jatuh tempo dengan total piutang Rp %s.',
                    (int) $current['overdue_invoices_count'],
                    number_format((int) $current['overdue_receivables'], 0, ',', '.'),
                ),
            ];
        }

        $negativeMarginCount = 0;
        foreach ($profitRows as $row) {
            if ((int) $row['invoiced_total'] > 0 && (int) $row['gross_profit'] < 0) {
                $negativeMarginCount++;
            }
        }

        if ($negativeMarginCount > 0) {
            $anomalies[] = [
                'code' => 'negative_order_margin',
                'title' => 'Order dengan Margin Negatif',
                'severity' => 'critical',
                'detail' => sprintf('%d order pada periode ini mencatat gross profit negatif (biaya melebihi tagihan).', $negativeMarginCount),
            ];
        }

        return $anomalies;
    }
}
