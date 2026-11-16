<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Cache Settings
    |--------------------------------------------------------------------------
    | Aggregates are cached using domain version stamps bumped via model
    | observers so mutations immediately invalidate affected caches across
    | database/array/file cache drivers without tag support requirements.
    */
    'cache_ttl_seconds' => (int) env('ANALYTICS_CACHE_TTL', 300),

    /*
    |--------------------------------------------------------------------------
    | Documented Business Insights Engine Thresholds
    |--------------------------------------------------------------------------
    | Every automated insight must reference explicit persisted metrics and
    | meet minimum sample sizes so noise does not create false alarms.
    */
    'thresholds' => [
        // SLA drops >= 5 percentage points vs previous period OR falls below 85%
        'sla_drop_pp' => 5.0,
        'sla_critical_floor_pct' => 85.0,
        'sla_min_sample' => 3,

        // Service demand growth or contraction >= 25% with >= 3 orders
        'service_demand_change_pct' => 25.0,
        'service_demand_min_orders' => 3,

        // Spare-part COGS or unit-cost period increase >= 15%
        'spare_part_cost_increase_pct' => 15.0,

        // Recurring low-stock / out-of-stock threshold
        'dead_stock_days' => 60,
        'slow_moving_days' => 30,
        'fast_moving_turnover_min' => 0.5,

        // Quotation approval rate drop >= 10 percentage points (min 3 decided quotes)
        'quotation_approval_drop_pp' => 10.0,
        'quotation_min_decided' => 3,

        // Outstanding receivables increase >= 20% or overdue balance exists
        'receivables_increase_pct' => 20.0,

        // Financial anomaly alert on >= 25% swing in operational expenses
        'expense_spike_pct' => 25.0,

        // Active workload spread >= 3 active+pending tickets between highest & lowest available tech
        'workload_imbalance_spread' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Authoritative KPI Definitions (Reference Metadata)
    |--------------------------------------------------------------------------
    */
    'kpi_definitions' => [
        'invoiced_revenue' => 'Sum of Invoice.total_amount for non-draft, non-cancelled invoices issued within the period (accrual basis).',
        'cash_received' => 'Sum of FinancialTransaction.amount where type=income and status=verified occurred within the period (cash basis).',
        'outstanding_receivables' => 'Sum of max(0, total_amount - paid_amount) across active unpaid/partially_paid/overdue invoices.',
        'gross_profit_accrual' => 'invoiced_revenue - spare_part_cogs - operational_expenses.',
        'net_cash_flow' => 'cash_received - verified_expenses_total (inventory_purchase + operational_expenses).',
        'order_completion_rate' => '(completed + warranty + closed) / non_cancelled_orders * 100.',
        'sla_compliance_rate' => 'Percentage of non-cancelled orders in period not breaching response or resolution SLA deadlines.',
        'inventory_valuation' => 'Sum of (active item quantity_on_hand * weighted-average unit_cost).',
        'stock_turnover' => 'Outbound units (spare_part_issue - spare_part_return + stock_out) / average stock units.',
        'repeat_service_rate' => 'Registered customers with >= 2 non-cancelled service orders / active customers with >= 1 order * 100.',
    ],
];
