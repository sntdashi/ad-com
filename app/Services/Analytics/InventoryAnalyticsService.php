<?php

namespace App\Services\Analytics;

use App\Models\FinancialTransaction;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\ServiceOrderSparePart;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class InventoryAnalyticsService
{
    public function __construct(
        public InventoryService $inventoryService,
        public AnalyticsCacheManager $cacheManager,
        public AnalyticsAccess $access,
    ) {}

    /**
     * Build Inventory BI summary, turnover, fast/slow/dead stock, and historical WAC trends.
     *
     * @return array<string, mixed>
     */
    public function summary(AnalyticsPeriod $period, User $viewer): array
    {
        $canViewFinancePurchaseLedger = $this->access->canViewRawFinanceLedger($viewer);

        return $this->cacheManager->remember(
            namespace: 'inventory',
            domains: ['inventory', 'finance'],
            period: $period,
            extraKey: $canViewFinancePurchaseLedger ? 'fin:1' : 'fin:0',
            resolver: fn (): array => $this->computeSummary($period, $canViewFinancePurchaseLedger),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function computeSummary(AnalyticsPeriod $period, bool $canViewFinancePurchaseLedger): array
    {
        $valuation = $this->inventoryService->valuationSummary();

        /** @var Collection<int, InventoryItem> $items */
        $items = InventoryItem::query()->orderBy('name')->get();
        $activeItems = $items->where('is_active', true);

        $periodMovements = InventoryMovement::query()
            ->with(['item:id,sku,name,category'])
            ->whereBetween('occurred_at', [$period->start, $period->end])
            ->orderBy('occurred_at')
            ->get();

        $movementTrends = [];
        foreach ($period->buckets() as $key => $bucket) {
            $movementTrends[$key] = [
                'key' => $key,
                'label' => $bucket['label'],
                'stock_in' => 0,
                'stock_out' => 0,
                'spare_part_issue' => 0,
                'spare_part_return' => 0,
                'net_outbound' => 0,
            ];
        }

        $outboundByItem = [];
        $inboundValuePeriod = 0;

        foreach ($periodMovements as $movement) {
            $itemId = (int) $movement->inventory_item_id;
            $key = $period->bucketKeyFor(CarbonImmutable::instance($movement->occurred_at));

            if ($movement->type === 'stock_in') {
                $inboundValuePeriod += $movement->quantity * (int) ($movement->unit_cost ?? 0);
                if (isset($movementTrends[$key])) {
                    $movementTrends[$key]['stock_in'] += $movement->quantity;
                }
            } elseif ($movement->type === 'stock_out') {
                $outboundByItem[$itemId] = ($outboundByItem[$itemId] ?? 0) + $movement->quantity;
                if (isset($movementTrends[$key])) {
                    $movementTrends[$key]['stock_out'] += $movement->quantity;
                    $movementTrends[$key]['net_outbound'] += $movement->quantity;
                }
            } elseif ($movement->type === 'spare_part_issue') {
                $outboundByItem[$itemId] = ($outboundByItem[$itemId] ?? 0) + $movement->quantity;
                if (isset($movementTrends[$key])) {
                    $movementTrends[$key]['spare_part_issue'] += $movement->quantity;
                    $movementTrends[$key]['net_outbound'] += $movement->quantity;
                }
            } elseif ($movement->type === 'spare_part_return') {
                $outboundByItem[$itemId] = max(0, ($outboundByItem[$itemId] ?? 0) - $movement->quantity);
                if (isset($movementTrends[$key])) {
                    $movementTrends[$key]['spare_part_return'] += $movement->quantity;
                    $movementTrends[$key]['net_outbound'] = max(0, $movementTrends[$key]['net_outbound'] - $movement->quantity);
                }
            }
        }

        $totalOutboundUnits = array_sum($outboundByItem);
        $currentUnits = (int) $valuation['units'];
        $stockTurnoverRatio = $currentUnits > 0
            ? round($totalOutboundUnits / $currentUnits, 2)
            : null;

        // Latest outbound timestamp per item for slow/dead stock detection.
        $lastOutboundByItem = InventoryMovement::query()
            ->whereIn('type', ['stock_out', 'spare_part_issue'])
            ->selectRaw('inventory_item_id, MAX(occurred_at) as last_outbound_at')
            ->groupBy('inventory_item_id')
            ->pluck('last_outbound_at', 'inventory_item_id');

        $now = CarbonImmutable::now($period->timezone);
        $deadStockDays = (int) config('analytics.thresholds.dead_stock_days', 60);
        $slowMovingDays = (int) config('analytics.thresholds.slow_moving_days', 30);
        $fastTurnoverMin = (float) config('analytics.thresholds.fast_moving_turnover_min', 0.5);

        $itemAnalytics = [];
        $fastMoving = [];
        $slowMoving = [];
        $deadStock = [];
        $reorderIndicators = [];
        $valuationByCategory = [];

        foreach ($activeItems as $item) {
            $itemId = (int) $item->id;
            $outbound = (int) ($outboundByItem[$itemId] ?? 0);
            $onHand = $item->quantity_on_hand;
            $avgStock = max(1, (int) ceil(($onHand + $outbound) / 2));
            $itemTurnover = ($onHand > 0 || $outbound > 0) ? round($outbound / $avgStock, 2) : 0.0;
            $assetValue = $onHand * $item->unit_cost;

            $category = trim((string) ($item->category ?: 'Lainnya'));
            if (! isset($valuationByCategory[$category])) {
                $valuationByCategory[$category] = [
                    'category' => $category,
                    'items_count' => 0,
                    'units' => 0,
                    'asset_value' => 0,
                ];
            }
            $valuationByCategory[$category]['items_count']++;
            $valuationByCategory[$category]['units'] += $onHand;
            $valuationByCategory[$category]['asset_value'] += $assetValue;

            $lastOutboundRaw = $lastOutboundByItem->get($itemId);
            $lastOutboundAt = $lastOutboundRaw !== null ? CarbonImmutable::parse((string) $lastOutboundRaw, $period->timezone) : null;
            $daysSinceOutbound = $lastOutboundAt !== null
                ? (int) $lastOutboundAt->diffInDays($now)
                : (int) CarbonImmutable::instance($item->created_at ?? $now)->diffInDays($now);

            $velocityClass = 'normal';
            if ($itemTurnover >= $fastTurnoverMin && $outbound >= 2) {
                $velocityClass = 'fast_moving';
            } elseif ($onHand > 0 && $outbound === 0 && $daysSinceOutbound >= $deadStockDays) {
                $velocityClass = 'dead_stock';
            } elseif ($onHand > 0 && ($outbound === 0 || $daysSinceOutbound >= $slowMovingDays)) {
                $velocityClass = 'slow_moving';
            }

            $entry = [
                'id' => $itemId,
                'sku' => $item->sku,
                'name' => $item->name,
                'category' => $category,
                'unit' => $item->unit,
                'quantity_on_hand' => $onHand,
                'reorder_level' => $item->reorder_level,
                'unit_cost' => $item->unit_cost,
                'asset_value' => $assetValue,
                'outbound_units_period' => $outbound,
                'turnover_ratio' => $itemTurnover,
                'days_since_last_outbound' => $daysSinceOutbound,
                'velocity_class' => $velocityClass,
            ];

            $itemAnalytics[] = $entry;

            if ($velocityClass === 'fast_moving') {
                $fastMoving[] = $entry;
            } elseif ($velocityClass === 'dead_stock') {
                $deadStock[] = $entry;
            } elseif ($velocityClass === 'slow_moving') {
                $slowMoving[] = $entry;
            }

            if ($onHand <= $item->reorder_level) {
                $reorderIndicators[] = [
                    'id' => $itemId,
                    'sku' => $item->sku,
                    'name' => $item->name,
                    'category' => $category,
                    'quantity_on_hand' => $onHand,
                    'reorder_level' => $item->reorder_level,
                    'suggested_restock_qty' => max(1, ($item->reorder_level * 2) - $onHand),
                    'severity' => $onHand === 0 ? 'out_of_stock' : 'low_stock',
                ];
            }
        }

        usort($fastMoving, fn (array $a, array $b): int => $b['outbound_units_period'] <=> $a['outbound_units_period']);
        usort($slowMoving, fn (array $a, array $b): int => $b['days_since_last_outbound'] <=> $a['days_since_last_outbound']);
        usort($deadStock, fn (array $a, array $b): int => $b['asset_value'] <=> $a['asset_value']);
        usort($reorderIndicators, fn (array $a, array $b): int => $a['quantity_on_hand'] <=> $b['quantity_on_hand']);

        $realizedCogsPeriod = 0;
        $cogsParts = ServiceOrderSparePart::query()
            ->whereIn('status', ['issued', 'partially_returned'])
            ->whereBetween('issued_at', [$period->start, $period->end])
            ->get(['issued_quantity', 'returned_quantity', 'unit_cost']);

        foreach ($cogsParts as $part) {
            $netQty = max(0, $part->issued_quantity - $part->returned_quantity);
            $realizedCogsPeriod += $netQty * $part->unit_cost;
        }

        $verifiedPurchaseExpensesPeriod = null;
        if ($canViewFinancePurchaseLedger) {
            $verifiedPurchaseExpensesPeriod = (int) FinancialTransaction::query()
                ->where('type', 'expense')
                ->where('category', 'inventory_purchase')
                ->where('status', 'verified')
                ->whereBetween('occurred_at', [$period->start, $period->end])
                ->sum('amount');
        }

        // Historical weighted-average unit cost snapshots from inbound stock movements.
        $wacHistory = $this->computeWeightedAverageCostHistory($period);

        return [
            'period' => $period->toArray(),
            'can_view_finance_ledger' => $canViewFinancePurchaseLedger,
            'overview' => [
                'item_types' => (int) $valuation['item_types'],
                'inactive_types' => (int) $valuation['inactive_types'],
                'units_on_hand' => $currentUnits,
                'asset_value' => (int) $valuation['asset_value'],
                'retail_value' => (int) $valuation['retail_value'],
                'low_stock_count' => (int) $valuation['low_stock'],
                'out_of_stock_count' => (int) $valuation['out_of_stock'],
                'valuation_method' => $valuation['valuation_method'],
                'stock_turnover_ratio' => $stockTurnoverRatio,
                'outbound_units_period' => $totalOutboundUnits,
                'inbound_value_period' => $inboundValuePeriod,
                'realized_spare_part_cogs_period' => $realizedCogsPeriod,
                'verified_purchase_expenses_period' => $verifiedPurchaseExpensesPeriod,
            ],
            'movement_trends' => array_values($movementTrends),
            'valuation_by_category' => array_values($valuationByCategory),
            'fast_moving' => array_slice($fastMoving, 0, 8),
            'slow_moving' => array_slice($slowMoving, 0, 8),
            'dead_stock_candidates' => array_slice($deadStock, 0, 8),
            'reorder_indicators' => array_slice($reorderIndicators, 0, 12),
            'historical_unit_cost_tracking' => $wacHistory,
            'items' => $itemAnalytics,
        ];
    }

    /**
     * Track historical unit-cost evolution across stock_in movements per item without mutating ledger.
     *
     * @return list<array{
     *     item_id: int,
     *     sku: string,
     *     name: string,
     *     current_wac: int,
     *     earliest_period_cost: int,
     *     latest_period_cost: int,
     *     cost_change_pct: float|null,
     *     stock_in_events: int
     * }>
     */
    private function computeWeightedAverageCostHistory(AnalyticsPeriod $period): array
    {
        $stockIns = InventoryMovement::query()
            ->with(['item:id,sku,name,unit_cost'])
            ->where('type', 'stock_in')
            ->whereNotNull('unit_cost')
            ->whereBetween('occurred_at', [$period->start, $period->end])
            ->orderBy('occurred_at')
            ->get(['inventory_item_id', 'unit_cost', 'occurred_at']);

        $grouped = [];
        foreach ($stockIns as $movement) {
            if ($movement->unit_cost === null) {
                continue;
            }
            $itemId = (int) $movement->inventory_item_id;
            if (! isset($grouped[$itemId])) {
                $grouped[$itemId] = [
                    'item_id' => $itemId,
                    'sku' => $movement->item->sku,
                    'name' => $movement->item->name,
                    'current_wac' => $movement->item->unit_cost,
                    'earliest_period_cost' => $movement->unit_cost,
                    'latest_period_cost' => $movement->unit_cost,
                    'cost_change_pct' => null,
                    'stock_in_events' => 0,
                ];
            }

            $grouped[$itemId]['latest_period_cost'] = $movement->unit_cost;
            $grouped[$itemId]['stock_in_events']++;
        }

        foreach ($grouped as $itemId => $row) {
            $grouped[$itemId]['cost_change_pct'] = AnalyticsPeriod::percentageDelta(
                $row['latest_period_cost'],
                $row['earliest_period_cost'],
            );
        }

        usort($grouped, fn (array $a, array $b): int => $b['stock_in_events'] <=> $a['stock_in_events']);

        return array_slice($grouped, 0, 10);
    }
}
