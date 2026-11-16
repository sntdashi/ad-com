<?php

namespace App\Services\Finance;

use App\Models\Estimate;
use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderSparePart;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class FinancialReportService
{
    /**
     * Compute cleanly separated financial summary metrics over an optional date window.
     *
     * @return array{
     *     period: array{start_date: string|null, end_date: string|null},
     *     invoiced_revenue: int,
     *     invoiced_count: int,
     *     cash_received: int,
     *     pending_income: int,
     *     outstanding_receivables: int,
     *     overdue_receivables: int,
     *     overdue_invoices_count: int,
     *     inventory_purchase_expenses: int,
     *     pending_inventory_expenses: int,
     *     operational_expenses: int,
     *     verified_expenses_total: int,
     *     pending_expenses_total: int,
     *     spare_part_cogs: int,
     *     gross_profit_accrual: int,
     *     net_cash_flow: int,
     *     net_verified_cash: int,
     *     quotations: array{draft: int, sent: int, approved: int, rejected: int, expired: int, converted: int}
     * }
     */
    public function summary(?string $startDate = null, ?string $endDate = null): array
    {
        $start = $startDate !== null && $startDate !== '' ? Carbon::parse($startDate)->startOfDay() : null;
        $end = $endDate !== null && $endDate !== '' ? Carbon::parse($endDate)->endOfDay() : null;

        $invoiceQuery = Invoice::query()
            ->whereIn('status', ['issued', 'partially_paid', 'paid', 'overdue']);
        if ($start !== null) {
            $invoiceQuery->whereDate(Invoice::query()->getModel()->qualifyColumn('issue_date'), '>=', $start->toDateString());
        }
        if ($end !== null) {
            $invoiceQuery->whereDate(Invoice::query()->getModel()->qualifyColumn('issue_date'), '<=', $end->toDateString());
        }

        $invoices = $invoiceQuery->get([
            'id',
            'status',
            'total_amount',
            'paid_amount',
            'due_date',
        ]);

        $invoicedRevenue = (int) $invoices->sum('total_amount');
        $invoicedCount = $invoices->count();

        $outstandingReceivables = 0;
        $overdueReceivables = 0;
        $overdueInvoicesCount = 0;
        $today = now()->startOfDay();

        foreach ($invoices as $invoice) {
            $outstanding = $invoice->outstandingBalance();
            $outstandingReceivables += $outstanding;
            if ($outstanding > 0 && $invoice->due_date !== null && $invoice->due_date->lt($today)) {
                $overdueReceivables += $outstanding;
                $overdueInvoicesCount++;
            }
        }

        $txBase = FinancialTransaction::query();
        if ($start !== null) {
            $txBase->where('occurred_at', '>=', $start);
        }
        if ($end !== null) {
            $txBase->where('occurred_at', '<=', $end);
        }

        $cashReceived = (int) (clone $txBase)
            ->where('type', 'income')
            ->where('status', 'verified')
            ->sum('amount');

        $pendingIncome = (int) (clone $txBase)
            ->where('type', 'income')
            ->where('status', 'pending')
            ->sum('amount');

        $inventoryPurchaseExpenses = (int) (clone $txBase)
            ->where('type', 'expense')
            ->where('status', 'verified')
            ->where('category', 'inventory_purchase')
            ->sum('amount');

        $pendingInventoryExpenses = (int) (clone $txBase)
            ->where('type', 'expense')
            ->where('status', 'pending')
            ->where('category', 'inventory_purchase')
            ->sum('amount');

        $operationalExpenses = (int) (clone $txBase)
            ->where('type', 'expense')
            ->where('status', 'verified')
            ->where('category', '!=', 'inventory_purchase')
            ->sum('amount');

        $verifiedExpensesTotal = $inventoryPurchaseExpenses + $operationalExpenses;
        $pendingExpensesTotal = (int) (clone $txBase)
            ->where('type', 'expense')
            ->where('status', 'pending')
            ->sum('amount');

        $cogsQuery = ServiceOrderSparePart::query()
            ->whereIn('status', ['issued', 'partially_returned']);
        if ($start !== null) {
            $cogsQuery->where('issued_at', '>=', $start);
        }
        if ($end !== null) {
            $cogsQuery->where('issued_at', '<=', $end);
        }

        $sparePartCogs = 0;
        foreach ($cogsQuery->get(['issued_quantity', 'returned_quantity', 'unit_cost']) as $part) {
            $netQty = max(0, $part->issued_quantity - $part->returned_quantity);
            $sparePartCogs += $netQty * $part->unit_cost;
        }

        $grossProfitAccrual = $invoicedRevenue - $sparePartCogs - $operationalExpenses;
        $netCashFlow = $cashReceived - $verifiedExpensesTotal;

        $quotationCounts = Estimate::query()
            ->selectRaw('status, COUNT(*) as total_count')
            ->groupBy('status')
            ->pluck('total_count', 'status');

        return [
            'period' => [
                'start_date' => $start?->toDateString(),
                'end_date' => $end?->toDateString(),
            ],
            'invoiced_revenue' => $invoicedRevenue,
            'invoiced_count' => $invoicedCount,
            'cash_received' => $cashReceived,
            'pending_income' => $pendingIncome,
            'outstanding_receivables' => $outstandingReceivables,
            'overdue_receivables' => $overdueReceivables,
            'overdue_invoices_count' => $overdueInvoicesCount,
            'inventory_purchase_expenses' => $inventoryPurchaseExpenses,
            'pending_inventory_expenses' => $pendingInventoryExpenses,
            'operational_expenses' => $operationalExpenses,
            'verified_expenses_total' => $verifiedExpensesTotal,
            'pending_expenses_total' => $pendingExpensesTotal,
            'spare_part_cogs' => $sparePartCogs,
            'gross_profit_accrual' => $grossProfitAccrual,
            'net_cash_flow' => $netCashFlow,
            'net_verified_cash' => $netCashFlow,
            'quotations' => [
                'draft' => (int) ($quotationCounts['draft'] ?? 0),
                'sent' => (int) ($quotationCounts['sent'] ?? 0),
                'approved' => (int) ($quotationCounts['approved'] ?? 0),
                'rejected' => (int) ($quotationCounts['rejected'] ?? 0),
                'expired' => (int) ($quotationCounts['expired'] ?? 0),
                'converted' => (int) ($quotationCounts['converted'] ?? 0),
            ],
        ];
    }

    /**
     * Build per-service-order profitability comparison (Estimated vs Invoiced vs Cash vs COGS/Expenses).
     *
     * @return Collection<int, array{
     *     service_order_id: string,
     *     ticket_number: string,
     *     customer_name: string,
     *     status: string,
     *     estimated_total: int,
     *     invoiced_total: int,
     *     cash_received: int,
     *     outstanding_receivables: int,
     *     spare_part_cogs: int,
     *     direct_expenses: int,
     *     gross_profit: int,
     *     cash_margin: int
     * }>
     */
    public function orderProfitabilityReport(?string $startDate = null, ?string $endDate = null, int $limit = 15): Collection
    {
        $ordersQuery = ServiceOrder::query()
            ->with([
                'invoices' => fn ($q) => $q->whereIn('status', ['issued', 'partially_paid', 'paid', 'overdue']),
                'financialTransactions',
                'spareParts' => fn ($q) => $q->whereIn('status', ['issued', 'partially_returned']),
                'estimates' => fn ($q) => $q->whereIn('status', ['approved', 'converted'])->latest('version'),
            ])
            ->latest('created_at')
            ->limit($limit);

        if ($startDate !== null && $startDate !== '') {
            $ordersQuery->whereDate('created_at', '>=', Carbon::parse($startDate)->toDateString());
        }
        if ($endDate !== null && $endDate !== '') {
            $ordersQuery->whereDate('created_at', '<=', Carbon::parse($endDate)->toDateString());
        }

        return $ordersQuery->get()->map(fn (ServiceOrder $order): array => $this->orderBreakdown($order));
    }

    /**
     * Compute staff profitability & billing metrics for a single service order.
     *
     * @return array{
     *     service_order_id: string,
     *     ticket_number: string,
     *     customer_name: string,
     *     status: string,
     *     estimated_total: int,
     *     invoiced_total: int,
     *     cash_received: int,
     *     outstanding_receivables: int,
     *     spare_part_cogs: int,
     *     direct_expenses: int,
     *     gross_profit: int,
     *     cash_margin: int
     * }
     */
    public function orderBreakdown(ServiceOrder $order): array
    {
        $order->loadMissing([
            'invoices',
            'financialTransactions',
            'spareParts',
            'estimates',
        ]);

        $activeInvoices = $order->invoices->filter(
            static fn (Invoice $inv): bool => in_array($inv->status, ['issued', 'partially_paid', 'paid', 'overdue'], true)
        );

        $invoicedTotal = (int) $activeInvoices->sum('total_amount');
        $outstanding = (int) $activeInvoices->sum(static fn (Invoice $inv): int => $inv->outstandingBalance());

        $cashReceived = (int) $order->financialTransactions
            ->where('type', 'income')
            ->where('status', 'verified')
            ->sum('amount');

        $directExpenses = (int) $order->financialTransactions
            ->where('type', 'expense')
            ->where('status', 'verified')
            ->sum('amount');

        $sparePartCogs = (int) $order->spareParts
            ->filter(static fn (ServiceOrderSparePart $part): bool => in_array($part->status, ['issued', 'partially_returned'], true))
            ->sum(static fn (ServiceOrderSparePart $part): int => max(0, $part->issued_quantity - $part->returned_quantity) * $part->unit_cost);

        $approvedEstimate = $order->estimates
            ->first(static fn (Estimate $est): bool => in_array($est->status, ['approved', 'converted'], true));

        $estimatedTotal = $approvedEstimate !== null
            ? $approvedEstimate->total
            : (int) ($order->estimated_total ?? 0);

        return [
            'service_order_id' => $order->id,
            'ticket_number' => $order->ticket_number,
            'customer_name' => $order->customer_name,
            'status' => $order->status,
            'estimated_total' => $estimatedTotal,
            'invoiced_total' => $invoicedTotal,
            'cash_received' => $cashReceived,
            'outstanding_receivables' => $outstanding,
            'spare_part_cogs' => $sparePartCogs,
            'direct_expenses' => $directExpenses,
            'gross_profit' => $invoicedTotal - $sparePartCogs - $directExpenses,
            'cash_margin' => $cashReceived - $sparePartCogs - $directExpenses,
        ];
    }
}
