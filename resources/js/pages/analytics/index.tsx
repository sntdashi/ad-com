import { Head, Link, router, setLayoutProps } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    BarChart3,
    Boxes,
    Briefcase,
    CalendarRange,
    ChevronLeft,
    ChevronRight,
    Download,
    Info,
    Landmark,
    Lightbulb,
    ShieldAlert,
    TrendingUp,
    Users,
    Wrench,
} from 'lucide-react';
import { type ComponentType, useState } from 'react';
import {
    ComparativeBarChart,
    DistributionDonutChart,
    InteractiveTimeSeriesChart,
    KpiStatCard,
    formatIdr,
    formatNumber,
} from '@/components/analytics/charts';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import {
    exportMethod as analyticsExport,
    index as analyticsIndex,
} from '@/routes/analytics';
import type { BreadcrumbItem } from '@/types';

type SectionKey =
    | 'executive'
    | 'financial'
    | 'services'
    | 'workforce'
    | 'inventory'
    | 'customers'
    | 'insights';

interface PeriodInfo {
    preset: string;
    start_date: string;
    end_date: string;
    previous_start_date: string;
    previous_end_date: string;
    label: string;
    comparison_label: string;
    bucket_granularity: string;
    timezone: string;
}

interface Capabilities {
    role: string;
    allowed_sections: SectionKey[];
    default_section: SectionKey;
    allowed_exports: string[];
    can_view_financial_summary: boolean;
    can_view_raw_finance_ledger: boolean;
    can_view_customer_pii: boolean;
    scoped_technician_id: number | null;
}

interface BusinessInsight {
    id: string;
    title: string;
    category: string;
    severity: string;
    description: string;
    supporting_metrics: Record<string, string | number | null>;
    comparison_period: string;
    detected_at: string;
    recommended_action: string;
    source_label: string;
    href: string;
}

interface KpiItem {
    value: number | null;
    previous: number | null;
    delta: number | null;
    delta_unit: string;
    basis?: string;
    overdue?: number;
    overdue_count?: number;
    retail_value?: number;
    units?: number;
    low_stock?: number;
    out_of_stock?: number;
}

interface ExecutivePayload {
    kpis: Record<string, KpiItem>;
    sla_snapshot: {
        on_track: number;
        approaching_deadline: number;
        breached: number;
        paused: number;
        completed: number;
        avg_first_response_minutes: number | null;
    };
    status_distribution: Array<{ status: string; count: number }>;
    trends: Array<{
        key: string;
        label: string;
        orders_total: number;
        orders_completed: number;
        invoiced_revenue: number;
        cash_received: number;
        expenses: number;
        gross_profit: number;
    }>;
    drill_downs: Array<{ label: string; href: string }>;
}

interface FinancialPayload {
    can_view_raw_ledger: boolean;
    summary: {
        invoiced_revenue: number;
        invoiced_count: number;
        cash_received: number;
        pending_income: number;
        outstanding_receivables: number;
        overdue_receivables: number;
        overdue_invoices_count: number;
        inventory_purchase_expenses: number | null;
        operational_expenses: number | null;
        spare_part_cogs: number;
        gross_profit_accrual: number;
        net_cash_flow: number;
    };
    comparisons: Record<
        string,
        {
            current: number | null;
            previous: number | null;
            delta_pct: number | null;
            basis: string;
        }
    >;
    trends: Array<{
        key: string;
        label: string;
        invoiced_revenue: number;
        cash_received: number;
        spare_part_cogs: number;
        operational_expenses: number;
        inventory_purchase_expenses: number;
        gross_profit_accrual: number;
        net_cash_flow: number;
    }>;
    receivables_aging: Array<{
        bucket: string;
        label: string;
        count: number;
        amount: number;
    }>;
    invoice_status_breakdown: Array<{
        status: string;
        count: number;
        total_amount: number;
        paid_amount: number;
        outstanding_amount: number;
    }>;
    revenue_by_service: Array<{
        service_id: number;
        code: string;
        name: string;
        service_mode: string;
        invoiced_revenue: number;
        spare_part_cogs: number;
        direct_expenses: number;
        gross_profit: number;
        margin_pct: number | null;
    }>;
    top_customers: Array<{
        customer_label: string;
        invoice_count: number;
        invoiced_revenue: number;
        cash_paid: number;
    }>;
    expense_breakdown: Array<{
        category: string;
        count: number;
        total_amount: number;
    }>;
    spare_part_cogs_analysis: Array<{
        item_id: number;
        sku: string;
        name: string;
        category: string;
        net_units: number;
        total_cogs: number;
        avg_unit_cost: number;
    }>;
    estimated_vs_actual: {
        orders_count: number;
        total_estimated: number;
        total_invoiced: number;
        total_actual_cost: number;
        variance_estimated_vs_invoiced: number;
    };
    order_profitability: {
        data: Array<{
            service_order_id: string;
            ticket_number: string;
            service_name: string;
            customer_label: string;
            status: string;
            estimated_total: number;
            invoiced_total: number;
            cash_received: number;
            outstanding_receivables: number;
            spare_part_cogs: number;
            direct_expenses: number;
            actual_cost_total: number;
            estimate_variance: number;
            gross_profit: number;
            margin_pct: number | null;
        }>;
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    anomalies: Array<{
        code: string;
        title: string;
        severity: string;
        detail: string;
    }>;
}

interface ServiceItemMetric {
    service_id: number;
    code: string;
    name: string;
    service_mode: string;
    risk_level: string;
    requests_count: number;
    non_cancelled_count: number;
    previous_requests_count: number;
    demand_change_pct: number | null;
    completed_count: number;
    cancelled_count: number;
    cancellation_rate: number | null;
    invoiced_revenue: number;
    spare_part_cogs: number;
    direct_expenses: number;
    gross_profit: number;
    margin_pct: number | null;
}

interface ServicesPayload {
    overview: {
        total_requests: number;
        cancellation_rate: number | null;
        booking_to_invoice_conversion_rate: number | null;
        repeat_service_rate: number | null;
        avg_invoice_value: number | null;
        total_quotations: number;
        avg_quotation_value: number | null;
        quotation_approval_rate: number | null;
        previous_quotation_approval_rate: number | null;
        quotation_approval_delta_pp: number | null;
        quotation_rejection_rate: number | null;
        quotation_conversion_rate: number | null;
    };
    quotations: {
        total: number;
        decided: number;
        avg_value: number | null;
        approval_rate: number | null;
        rejection_rate: number | null;
        conversion_rate: number | null;
        by_status: Record<string, number>;
    };
    services: ServiceItemMetric[];
    most_requested: ServiceItemMetric[];
    most_profitable: ServiceItemMetric[];
    lowest_margin: ServiceItemMetric[];
    service_mode_distribution: Array<{ mode: string; count: number }>;
    risk_level_distribution: Array<{ risk_level: string; count: number }>;
}

interface WorkforcePayload {
    is_personal_scope: boolean;
    overview: {
        technicians_count: number;
        assigned_jobs_period: number;
        completed_jobs_period: number;
        reopened_jobs_period: number;
        avg_resolution_minutes: number | null;
        avg_work_session_minutes: number | null;
        avg_sla_compliance_rate: number | null;
    };
    availability_distribution: Record<string, number>;
    workload_distribution: Record<string, number>;
    specialty_performance: Array<{
        specialty: string;
        technicians_count: number;
        assigned_jobs: number;
        completed_jobs: number;
        avg_sla_compliance_rate: number | null;
    }>;
    technicians: Array<{
        id: number;
        name: string;
        specialty: string;
        is_active: boolean;
        availability_status: string;
        availability_label_id: string;
        availability_changes_count: number;
        assigned_jobs_period: number;
        completed_jobs_period: number;
        high_complexity_jobs_period: number;
        high_complexity_share_pct: number;
        avg_resolution_minutes_period: number | null;
        avg_work_session_minutes_period: number | null;
        total_work_hours_period: number;
        work_sessions_count: number;
        sla_compliance_rate_period: number | null;
        reopened_jobs_period: number;
        current_workload: number;
        active_assignments_count: number;
        pending_assignments_count: number;
        sla_risk_assignments_count: number;
        workload_level: string;
        context_note: string;
    }>;
}

interface InventoryItemMetric {
    id: number;
    sku: string;
    name: string;
    category: string;
    unit: string;
    quantity_on_hand: number;
    reorder_level: number;
    unit_cost: number;
    asset_value: number;
    outbound_units_period: number;
    turnover_ratio: number;
    days_since_last_outbound: number;
    velocity_class: string;
}

interface InventoryPayload {
    can_view_finance_ledger: boolean;
    overview: {
        item_types: number;
        inactive_types: number;
        units_on_hand: number;
        asset_value: number;
        retail_value: number;
        low_stock_count: number;
        out_of_stock_count: number;
        valuation_method: string;
        stock_turnover_ratio: number | null;
        outbound_units_period: number;
        inbound_value_period: number;
        realized_spare_part_cogs_period: number;
        verified_purchase_expenses_period: number | null;
    };
    movement_trends: Array<{
        key: string;
        label: string;
        stock_in: number;
        stock_out: number;
        spare_part_issue: number;
        spare_part_return: number;
        net_outbound: number;
    }>;
    valuation_by_category: Array<{
        category: string;
        items_count: number;
        units: number;
        asset_value: number;
    }>;
    fast_moving: InventoryItemMetric[];
    slow_moving: InventoryItemMetric[];
    dead_stock_candidates: InventoryItemMetric[];
    reorder_indicators: Array<{
        id: number;
        sku: string;
        name: string;
        category: string;
        quantity_on_hand: number;
        reorder_level: number;
        suggested_restock_qty: number;
        severity: string;
    }>;
    historical_unit_cost_tracking: Array<{
        item_id: number;
        sku: string;
        name: string;
        current_wac: number;
        earliest_period_cost: number;
        latest_period_cost: number;
        cost_change_pct: number | null;
        stock_in_events: number;
    }>;
}

interface CustomersPayload {
    can_view_pii: boolean;
    overview: {
        total_registered_customers: number;
        new_registered_period: number;
        active_ordering_customers_period: number;
        new_ordering_customers_period: number;
        returning_customers_period: number;
        repeat_service_rate: number | null;
        avg_service_frequency: number | null;
        avg_lifetime_value: number | null;
        total_lifetime_invoiced_value: number;
        registered_devices_total: number;
        warranties_issued_period: number;
        active_warranties_total: number;
        warranty_claims_period: number;
        warranty_claim_rate_pct: number | null;
    };
    acquisition_trends: Array<{
        key: string;
        label: string;
        new_registered_customers: number;
        active_ordering_customers: number;
    }>;
    most_requested_services: Array<{
        service_id: number;
        code: string;
        name: string;
        orders_count: number;
    }>;
    top_lifetime_segments: Array<{
        customer_label: string;
        invoices_count: number;
        lifetime_invoiced: number;
        lifetime_paid: number;
    }>;
    device_categories: Array<{ category: string; count: number }>;
    warranty_claims_by_status: Record<string, number>;
}

interface ActivityContext {
    latest_order_date: string | null;
    latest_transaction_date: string | null;
    latest_movement_date: string | null;
    has_outside_period_data: boolean;
}

interface AnalyticsPageProps {
    period: PeriodInfo;
    activeSection: SectionKey;
    capabilities: Capabilities;
    activityContext?: ActivityContext;
    kpiDefinitions: Record<string, string>;
    insights: BusinessInsight[];
    executive: ExecutivePayload | null;
    financial: FinancialPayload | null;
    services: ServicesPayload | null;
    workforce: WorkforcePayload | null;
    inventory: InventoryPayload | null;
    customers: CustomersPayload | null;
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Analitik & BI',
        href: analyticsIndex.url(),
    },
];

const PRESET_OPTIONS = [
    { key: 'daily', label: 'Hari Ini' },
    { key: 'weekly', label: '7 Hari' },
    { key: 'monthly', label: '30 Hari' },
    { key: 'quarterly', label: '90 Hari' },
    { key: 'yearly', label: '12 Bulan' },
    { key: 'custom', label: 'Kustom' },
];

const EXPORT_LABELS: Record<string, string> = {
    executive: 'Ringkasan Eksekutif (CSV)',
    financial: 'Tren Keuangan (CSV)',
    'order-profitability': 'Profitabilitas Order (CSV)',
    services: 'Performa Layanan & Margin (CSV)',
    workforce: 'Kinerja Workforce (CSV)',
    inventory: 'Analisis Inventori (CSV)',
    customers: 'Segmen Pelanggan (CSV)',
};

const PALETTE = [
    '#dc2626',
    '#2563eb',
    '#059669',
    '#d97706',
    '#7c3aed',
    '#0891b2',
];

export default function AnalyticsIndex({
    period,
    activeSection,
    capabilities,
    activityContext,
    insights,
    executive,
    financial,
    services,
    workforce,
    inventory,
    customers,
}: AnalyticsPageProps) {
    setLayoutProps({ breadcrumbs });

    const [customStart, setCustomStart] = useState(period.start_date);
    const [customEnd, setCustomEnd] = useState(period.end_date);
    const [showCustomInputs, setShowCustomInputs] = useState(
        period.preset === 'custom',
    );
    const [insightFilter, setInsightFilter] = useState<string>('all');

    const navigateAnalytics = (params: {
        section?: SectionKey;
        preset?: string;
        startDate?: string;
        endDate?: string;
        page?: number;
    }) => {
        const targetSection = params.section ?? activeSection;
        const targetPreset = params.preset ?? period.preset;
        const query: Record<string, string | number> = {
            section: targetSection,
            period: targetPreset,
        };

        if (targetPreset === 'custom') {
            query.start_date = params.startDate ?? customStart;
            query.end_date = params.endDate ?? customEnd;
        }
        if (params.page && params.page > 1) {
            query.page = params.page;
        }

        router.get(analyticsIndex.url({ query }), {}, { preserveScroll: true });
    };

    const sectionMeta: Record<
        SectionKey,
        { label: string; icon: ComponentType<{ className?: string }> }
    > = {
        executive: { label: 'Ringkasan Eksekutif', icon: BarChart3 },
        financial: { label: 'Keuangan & Margin', icon: Landmark },
        services: { label: 'Layanan & Penjualan', icon: Briefcase },
        workforce: {
            label: capabilities.scoped_technician_id
                ? 'Kinerja Saya'
                : 'Workforce & SLA',
            icon: Wrench,
        },
        inventory: { label: 'Inventori & Stok', icon: Boxes },
        customers: { label: 'Pelanggan & Garansi', icon: Users },
        insights: { label: `Insight (${insights.length})`, icon: Lightbulb },
    };

    const criticalInsightsCount = insights.filter(
        (item) => item.severity === 'critical',
    ).length;
    const filteredInsights =
        insightFilter === 'all'
            ? insights
            : insights.filter((item) => item.severity === insightFilter);

    return (
        <>
            <Head title="Analitik & Business Intelligence" />

            <div className="mx-auto flex w-full max-w-[1440px] flex-col gap-6 p-4 sm:p-6">
                {/* Header & Liquid Glass Controls */}
                <div className="border-border/70 bg-card/80 flex flex-col gap-4 rounded-2xl border p-4 shadow-xs backdrop-blur-md lg:flex-row lg:items-center lg:justify-between">
                    <div className="space-y-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <h1 className="text-xl font-semibold tracking-tight sm:text-2xl">
                                Analitik & Business Intelligence
                            </h1>
                            <Badge
                                variant="outline"
                                className="text-xs font-medium capitalize"
                            >
                                Peran: {capabilities.role}
                            </Badge>
                            {criticalInsightsCount > 0 && (
                                <Badge
                                    variant="destructive"
                                    className="text-xs"
                                >
                                    {criticalInsightsCount} Peringatan Kritis
                                </Badge>
                            )}
                        </div>
                        <p className="text-muted-foreground text-xs sm:text-sm">
                            Periode aktif{' '}
                            <span className="text-foreground font-medium">
                                {period.label}
                            </span>{' '}
                            dibanding <span>{period.comparison_label}</span> (
                            {period.timezone})
                        </p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <div className="border-border/70 bg-background/80 inline-flex flex-wrap items-center gap-1 rounded-xl border p-1 backdrop-blur-md">
                            {PRESET_OPTIONS.map((opt) => {
                                const isActive =
                                    opt.key === 'custom'
                                        ? showCustomInputs ||
                                          period.preset === 'custom'
                                        : !showCustomInputs &&
                                          period.preset === opt.key;

                                return (
                                    <button
                                        key={opt.key}
                                        type="button"
                                        onClick={() => {
                                            if (opt.key === 'custom') {
                                                setShowCustomInputs(true);
                                                return;
                                            }
                                            setShowCustomInputs(false);
                                            navigateAnalytics({
                                                preset: opt.key,
                                            });
                                        }}
                                        className={cn(
                                            'rounded-lg px-2.5 py-1 text-xs font-medium transition-colors',
                                            isActive
                                                ? 'bg-primary text-primary-foreground shadow-xs'
                                                : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                                        )}
                                    >
                                        {opt.label}
                                    </button>
                                );
                            })}
                        </div>

                        {capabilities.allowed_exports.length > 0 && (
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="bg-background/80 gap-2 backdrop-blur-md"
                                    >
                                        <Download className="size-4" />
                                        Ekspor CSV
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent
                                    align="end"
                                    className="w-64"
                                >
                                    {capabilities.allowed_exports.map(
                                        (exportType) => {
                                            const exportUrl =
                                                analyticsExport.url({
                                                    query: {
                                                        type: exportType,
                                                        period: period.preset,
                                                        ...(period.preset ===
                                                        'custom'
                                                            ? {
                                                                  start_date:
                                                                      period.start_date,
                                                                  end_date:
                                                                      period.end_date,
                                                              }
                                                            : {}),
                                                    },
                                                });

                                            return (
                                                <DropdownMenuItem
                                                    key={exportType}
                                                    asChild
                                                >
                                                    <a
                                                        href={exportUrl}
                                                        className="flex w-full items-center gap-2"
                                                    >
                                                        <Download className="text-muted-foreground size-3.5" />
                                                        <span>
                                                            {EXPORT_LABELS[
                                                                exportType
                                                            ] ?? exportType}
                                                        </span>
                                                    </a>
                                                </DropdownMenuItem>
                                            );
                                        },
                                    )}
                                </DropdownMenuContent>
                            </DropdownMenu>
                        )}
                    </div>
                </div>

                {/* Custom Date Range Strip */}
                {showCustomInputs && (
                    <div className="border-border/70 bg-card/80 flex flex-wrap items-end gap-3 rounded-xl border p-3 backdrop-blur-md">
                        <div className="text-muted-foreground flex items-center gap-2 text-xs font-medium">
                            <CalendarRange className="text-primary size-4" />
                            Rentang Kustom
                        </div>
                        <div className="flex flex-col gap-1">
                            <label
                                htmlFor="analytics-start-date"
                                className="text-muted-foreground text-[11px]"
                            >
                                Dari Tanggal
                            </label>
                            <Input
                                id="analytics-start-date"
                                type="date"
                                value={customStart}
                                onChange={(e) => setCustomStart(e.target.value)}
                                className="h-8 w-40 text-xs"
                            />
                        </div>
                        <div className="flex flex-col gap-1">
                            <label
                                htmlFor="analytics-end-date"
                                className="text-muted-foreground text-[11px]"
                            >
                                Sampai Tanggal
                            </label>
                            <Input
                                id="analytics-end-date"
                                type="date"
                                value={customEnd}
                                onChange={(e) => setCustomEnd(e.target.value)}
                                className="h-8 w-40 text-xs"
                            />
                        </div>
                        <Button
                            size="sm"
                            onClick={() =>
                                navigateAnalytics({
                                    preset: 'custom',
                                    startDate: customStart,
                                    endDate: customEnd,
                                })
                            }
                        >
                            Terapkan Rentang
                        </Button>
                    </div>
                )}

                {/* Role-Scoped Navigation Tabs */}
                <div className="flex items-center gap-1.5 overflow-x-auto pb-1">
                    {capabilities.allowed_sections.map((section) => {
                        const meta = sectionMeta[section];
                        const Icon = meta.icon;
                        const active = section === activeSection;

                        return (
                            <button
                                key={section}
                                type="button"
                                onClick={() => navigateAnalytics({ section })}
                                className={cn(
                                    'inline-flex shrink-0 items-center gap-2 rounded-xl border px-3.5 py-2 text-xs font-medium transition-colors',
                                    active
                                        ? 'border-primary/40 bg-primary text-primary-foreground shadow-xs'
                                        : 'border-border/70 bg-card/75 text-muted-foreground hover:bg-muted/70 hover:text-foreground',
                                )}
                            >
                                <Icon className="size-3.5" />
                                <span>{meta.label}</span>
                            </button>
                        );
                    })}
                </div>

                {/* Empty-Period Activity Context Banner */}
                {activityContext?.has_outside_period_data && (
                    <Alert className="border-amber-500/35 bg-amber-500/[0.06]">
                        <Info className="size-4" />
                        <AlertTitle>
                            Belum ada transaksi atau order baru pada rentang{' '}
                            {period.label}
                        </AlertTitle>
                        <AlertDescription className="mt-1 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <span>
                                Seluruh KPI periode di rentang ini bernilai 0
                                karena memang belum ada record pada tanggal
                                tersebut. Data operasional tercatat di luar
                                rentang ini (
                                {[
                                    activityContext.latest_order_date
                                        ? `order terakhir ${activityContext.latest_order_date}`
                                        : null,
                                    activityContext.latest_transaction_date
                                        ? `transaksi terakhir ${activityContext.latest_transaction_date}`
                                        : null,
                                    activityContext.latest_movement_date
                                        ? `pergerakan stok ${activityContext.latest_movement_date}`
                                        : null,
                                ]
                                    .filter(Boolean)
                                    .join(', ')}
                                ).
                            </span>
                            <div className="flex shrink-0 flex-wrap gap-2">
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        navigateAnalytics({
                                            preset: 'quarterly',
                                        })
                                    }
                                >
                                    Tampilkan 90 Hari
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        navigateAnalytics({ preset: 'yearly' })
                                    }
                                >
                                    Tampilkan 12 Bulan
                                </Button>
                            </div>
                        </AlertDescription>
                    </Alert>
                )}

                {/* EXECUTIVE SECTION */}
                {activeSection === 'executive' && executive && (
                    <div className="space-y-6">
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <KpiStatCard
                                title="Total Order Servis"
                                value={formatNumber(
                                    executive.kpis.total_orders?.value,
                                )}
                                delta={executive.kpis.total_orders?.delta}
                                deltaUnit="pct"
                                subtitle={`${formatNumber(executive.kpis.new_orders?.value)} baru · ${formatNumber(executive.kpis.active_orders?.value)} aktif`}
                                href="/dashboard"
                            />
                            <KpiStatCard
                                title="Tingkat Penyelesaian"
                                value={
                                    executive.kpis.completion_rate?.value !==
                                        null &&
                                    executive.kpis.completion_rate?.value !==
                                        undefined
                                        ? `${executive.kpis.completion_rate.value.toFixed(1)}%`
                                        : '—'
                                }
                                delta={executive.kpis.completion_rate?.delta}
                                deltaUnit="pp"
                                subtitle={`${formatNumber(executive.kpis.completed_orders?.value)} selesai · ${formatNumber(executive.kpis.cancelled_orders?.value)} batal`}
                            />
                            <KpiStatCard
                                title="Kepatuhan SLA"
                                value={
                                    executive.kpis.sla_compliance_rate
                                        ?.value !== null &&
                                    executive.kpis.sla_compliance_rate
                                        ?.value !== undefined
                                        ? `${executive.kpis.sla_compliance_rate.value.toFixed(1)}%`
                                        : '—'
                                }
                                delta={
                                    executive.kpis.sla_compliance_rate?.delta
                                }
                                deltaUnit="pp"
                                subtitle={`Rata-rata resolusi ${formatNumber(executive.kpis.avg_resolution_minutes?.value, ' mnt')}`}
                            />
                            <KpiStatCard
                                title="Pelanggan Aktif"
                                value={formatNumber(
                                    executive.kpis.active_customers?.value,
                                )}
                                delta={executive.kpis.active_customers?.delta}
                                deltaUnit="pct"
                                subtitle={`Repeat ${executive.kpis.repeat_customer_rate?.value !== null ? `${executive.kpis.repeat_customer_rate.value.toFixed(1)}%` : '—'} · Total akun ${formatNumber(executive.kpis.total_registered_customers?.value)}`}
                            />
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                            <KpiStatCard
                                title="Pendapatan Tertagih"
                                basisLabel="Akrual"
                                value={formatIdr(
                                    executive.kpis.invoiced_revenue?.value,
                                )}
                                delta={executive.kpis.invoiced_revenue?.delta}
                                deltaUnit="pct"
                                subtitle={`HPP Spare-Part ${formatIdr(executive.kpis.spare_part_cogs?.value)}`}
                                href="/analytics?section=financial"
                            />
                            <KpiStatCard
                                title="Kas Masuk Terverifikasi"
                                basisLabel="Kas"
                                value={formatIdr(
                                    executive.kpis.cash_received?.value,
                                )}
                                delta={executive.kpis.cash_received?.delta}
                                deltaUnit="pct"
                                subtitle={`Arus Kas Bersih ${formatIdr(executive.kpis.net_cash_flow?.value)}`}
                            />
                            <KpiStatCard
                                title="Laba Kotor (Akrual)"
                                basisLabel="Akrual"
                                value={formatIdr(
                                    executive.kpis.gross_profit_accrual?.value,
                                )}
                                delta={
                                    executive.kpis.gross_profit_accrual?.delta
                                }
                                deltaUnit="pct"
                                tone={
                                    (executive.kpis.gross_profit_accrual
                                        ?.value ?? 0) < 0
                                        ? 'danger'
                                        : 'positive'
                                }
                                subtitle={`Beban Ops ${formatIdr(executive.kpis.operational_expenses?.value)}`}
                            />
                            <KpiStatCard
                                title="Piutang Usaha (AR)"
                                basisLabel="Snapshot"
                                value={formatIdr(
                                    executive.kpis.outstanding_receivables
                                        ?.value,
                                )}
                                tone={
                                    (executive.kpis.outstanding_receivables
                                        ?.overdue ?? 0) > 0
                                        ? 'warning'
                                        : 'default'
                                }
                                subtitle={`Jatuh tempo ${formatIdr(executive.kpis.outstanding_receivables?.overdue)} (${formatNumber(executive.kpis.outstanding_receivables?.overdue_count)} inv)`}
                                href="/finance"
                            />
                            <KpiStatCard
                                title="Valuasi Aset Inventori"
                                basisLabel="Snapshot"
                                value={formatIdr(
                                    executive.kpis.inventory_valuation?.value,
                                )}
                                subtitle={`${formatNumber(executive.kpis.inventory_valuation?.units)} unit · ${formatNumber(executive.kpis.inventory_valuation?.low_stock)} stok menipis`}
                                href="/inventory"
                            />
                        </div>

                        <div className="grid gap-4 lg:grid-cols-2">
                            <InteractiveTimeSeriesChart
                                title="Tren Pendapatan Akrual vs Kas Masuk"
                                description="Memisahkan nilai tagihan terbit (akrual) dan kas terverifikasi (kas)."
                                data={executive.trends}
                                valueType="currency"
                                series={[
                                    {
                                        key: 'invoiced_revenue',
                                        label: 'Tertagih (Akrual)',
                                        color: '#dc2626',
                                    },
                                    {
                                        key: 'cash_received',
                                        label: 'Kas Masuk (Kas)',
                                        color: '#059669',
                                    },
                                    {
                                        key: 'gross_profit',
                                        label: 'Laba Kotor',
                                        color: '#2563eb',
                                    },
                                ]}
                            />
                            <InteractiveTimeSeriesChart
                                title="Tren Volume Order & Penyelesaian"
                                description="Jumlah order masuk dibandingkan penyelesaian per bucket periode."
                                data={executive.trends}
                                valueType="count"
                                series={[
                                    {
                                        key: 'orders_total',
                                        label: 'Order Masuk',
                                        color: '#dc2626',
                                    },
                                    {
                                        key: 'orders_completed',
                                        label: 'Order Selesai',
                                        color: '#059669',
                                    },
                                ]}
                            />
                        </div>

                        <div className="grid gap-4 lg:grid-cols-3">
                            <DistributionDonutChart
                                title="Distribusi Status Order"
                                description="Komposisi status order dalam periode terpilih."
                                centerLabel="Order"
                                slices={executive.status_distribution.map(
                                    (row, idx) => ({
                                        label: row.status,
                                        value: row.count,
                                        color: PALETTE[idx % PALETTE.length],
                                    }),
                                )}
                            />

                            <Card className="border-border/70 bg-card/95 lg:col-span-2">
                                <CardHeader className="flex flex-row items-center justify-between pb-3">
                                    <div>
                                        <CardTitle className="text-base">
                                            Ringkasan Insight Otomatis
                                        </CardTitle>
                                        <CardDescription>
                                            Deteksi berbasis ambang data nyata
                                            dari operasional AD Com.
                                        </CardDescription>
                                    </div>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() =>
                                            navigateAnalytics({
                                                section: 'insights',
                                            })
                                        }
                                    >
                                        Lihat Semua
                                        <ArrowRight className="ml-1 size-3.5" />
                                    </Button>
                                </CardHeader>
                                <CardContent className="space-y-2.5">
                                    {insights.length === 0 ? (
                                        <p className="border-border/70 bg-muted/20 text-muted-foreground rounded-xl border border-dashed p-4 text-sm">
                                            Tidak ada anomali atau deviasi
                                            signifikan yang melampaui ambang
                                            batas pada periode ini.
                                        </p>
                                    ) : (
                                        insights
                                            .slice(0, 3)
                                            .map((insight) => (
                                                <InsightRow
                                                    key={insight.id}
                                                    insight={insight}
                                                />
                                            ))
                                    )}
                                </CardContent>
                            </Card>
                        </div>
                    </div>
                )}

                {/* FINANCIAL SECTION */}
                {activeSection === 'financial' && financial && (
                    <div className="space-y-6">
                        {!financial.can_view_raw_ledger && (
                            <Alert>
                                <Info className="size-4" />
                                <AlertTitle>
                                    Mode Pengawasan Keuangan (Supervisor)
                                </AlertTitle>
                                <AlertDescription>
                                    Rincian buku kas pengeluaran operasional dan
                                    identitas PII pelanggan disamarkan sesuai
                                    kebijakan akses peran Supervisor.
                                </AlertDescription>
                            </Alert>
                        )}

                        {financial.anomalies.length > 0 && (
                            <div className="grid gap-3 md:grid-cols-2">
                                {financial.anomalies.map((anomaly) => (
                                    <Alert
                                        key={anomaly.code}
                                        variant={
                                            anomaly.severity === 'critical'
                                                ? 'destructive'
                                                : 'default'
                                        }
                                    >
                                        <AlertTriangle className="size-4" />
                                        <AlertTitle>{anomaly.title}</AlertTitle>
                                        <AlertDescription>
                                            {anomaly.detail}
                                        </AlertDescription>
                                    </Alert>
                                ))}
                            </div>
                        )}

                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <KpiStatCard
                                title="Pendapatan Tertagih"
                                basisLabel="Akrual"
                                value={formatIdr(
                                    financial.summary.invoiced_revenue,
                                )}
                                delta={
                                    financial.comparisons.invoiced_revenue
                                        ?.delta_pct
                                }
                                subtitle={`${financial.summary.invoiced_count} invoice terbit`}
                            />
                            <KpiStatCard
                                title="Kas Masuk Terverifikasi"
                                basisLabel="Kas"
                                value={formatIdr(
                                    financial.summary.cash_received,
                                )}
                                delta={
                                    financial.comparisons.cash_received
                                        ?.delta_pct
                                }
                                subtitle={`Pending verifikasi ${formatIdr(financial.summary.pending_income)}`}
                            />
                            <KpiStatCard
                                title="Laba Kotor (Akrual)"
                                basisLabel="Akrual"
                                value={formatIdr(
                                    financial.summary.gross_profit_accrual,
                                )}
                                delta={
                                    financial.comparisons.gross_profit_accrual
                                        ?.delta_pct
                                }
                                subtitle={`HPP Spare-Part ${formatIdr(financial.summary.spare_part_cogs)}`}
                                tone={
                                    financial.summary.gross_profit_accrual < 0
                                        ? 'danger'
                                        : 'positive'
                                }
                            />
                            <KpiStatCard
                                title="Piutang Usaha (AR)"
                                basisLabel="Snapshot"
                                value={formatIdr(
                                    financial.summary.outstanding_receivables,
                                )}
                                tone={
                                    financial.summary.overdue_receivables > 0
                                        ? 'warning'
                                        : 'default'
                                }
                                subtitle={`Overdue ${formatIdr(financial.summary.overdue_receivables)} (${financial.summary.overdue_invoices_count} inv)`}
                            />
                        </div>

                        <InteractiveTimeSeriesChart
                            title="Tren Pendapatan, Kas Masuk, dan Arus Kas Bersih"
                            description="Perbandingan kinerja akrual vs realisasi kas masuk & keluar per periode."
                            data={financial.trends}
                            valueType="currency"
                            series={[
                                {
                                    key: 'invoiced_revenue',
                                    label: 'Pendapatan Tertagih (Akrual)',
                                    color: '#dc2626',
                                },
                                {
                                    key: 'cash_received',
                                    label: 'Kas Masuk (Kas)',
                                    color: '#059669',
                                },
                                {
                                    key: 'net_cash_flow',
                                    label: 'Arus Kas Bersih',
                                    color: '#2563eb',
                                },
                            ]}
                        />

                        <div className="grid gap-4 lg:grid-cols-2">
                            <ComparativeBarChart
                                title="Umur Piutang Usaha (AR Aging)"
                                description="Distribusi saldo tagihan terbuka berdasarkan tanggal jatuh tempo."
                                valueType="currency"
                                items={financial.receivables_aging.map(
                                    (b, idx) => ({
                                        label: `${b.label} (${b.count} inv)`,
                                        value: b.amount,
                                        color: idx >= 2 ? '#dc2626' : '#d97706',
                                    }),
                                )}
                            />
                            <ComparativeBarChart
                                title="Pendapatan & Laba Kotor per Layanan"
                                description="Laba kotor = Pendapatan Tertagih − HPP Spare-Part − Beban Langsung."
                                valueType="currency"
                                items={financial.revenue_by_service.map(
                                    (s) => ({
                                        label: `${s.name} (${s.code})`,
                                        value: s.gross_profit,
                                        secondaryLabel: `Tagihan ${formatIdr(s.invoiced_revenue)} · HPP ${formatIdr(s.spare_part_cogs)} · Margin ${s.margin_pct !== null ? `${s.margin_pct}%` : '—'}`,
                                        color:
                                            s.gross_profit < 0
                                                ? '#dc2626'
                                                : '#059669',
                                    }),
                                )}
                            />
                        </div>

                        <div className="grid gap-4 lg:grid-cols-3">
                            <Card className="border-border/70 bg-card/95">
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-base">
                                        Estimasi vs Realisasi Order
                                    </CardTitle>
                                    <CardDescription>
                                        {
                                            financial.estimated_vs_actual
                                                .orders_count
                                        }{' '}
                                        order non-batal pada periode ini.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-3 text-sm">
                                    <MetricRow
                                        label="Total Estimasi Quotation"
                                        value={formatIdr(
                                            financial.estimated_vs_actual
                                                .total_estimated,
                                        )}
                                    />
                                    <MetricRow
                                        label="Total Tertagih Aktual"
                                        value={formatIdr(
                                            financial.estimated_vs_actual
                                                .total_invoiced,
                                        )}
                                    />
                                    <MetricRow
                                        label="Total Biaya Aktual (HPP + Langsung)"
                                        value={formatIdr(
                                            financial.estimated_vs_actual
                                                .total_actual_cost,
                                        )}
                                    />
                                    <MetricRow
                                        label="Selisih Tagihan vs Estimasi"
                                        value={formatIdr(
                                            financial.estimated_vs_actual
                                                .variance_estimated_vs_invoiced,
                                        )}
                                    />
                                </CardContent>
                            </Card>

                            <Card className="border-border/70 bg-card/95">
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-base">
                                        Top Pelanggan (Berdasarkan Tagihan)
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-2.5 text-xs">
                                    {financial.top_customers.length === 0 ? (
                                        <p className="text-muted-foreground">
                                            Belum ada invoice pada periode ini.
                                        </p>
                                    ) : (
                                        financial.top_customers.map(
                                            (c, idx) => (
                                                <div
                                                    key={idx}
                                                    className="border-border/50 flex items-center justify-between gap-2 border-b pb-2 last:border-0"
                                                >
                                                    <div>
                                                        <div className="text-foreground font-medium">
                                                            {c.customer_label}
                                                        </div>
                                                        <div className="text-muted-foreground">
                                                            {c.invoice_count}{' '}
                                                            invoice
                                                        </div>
                                                    </div>
                                                    <div className="text-right font-semibold tabular-nums">
                                                        {formatIdr(
                                                            c.invoiced_revenue,
                                                        )}
                                                    </div>
                                                </div>
                                            ),
                                        )
                                    )}
                                </CardContent>
                            </Card>

                            <Card className="border-border/70 bg-card/95">
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-base">
                                        {financial.can_view_raw_ledger
                                            ? 'Rincian Pengeluaran Terverifikasi'
                                            : 'Top Konsumsi HPP Spare-Part'}
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-2.5 text-xs">
                                    {financial.can_view_raw_ledger ? (
                                        financial.expense_breakdown.length ===
                                        0 ? (
                                            <p className="text-muted-foreground">
                                                Belum ada pengeluaran
                                                terverifikasi.
                                            </p>
                                        ) : (
                                            financial.expense_breakdown.map(
                                                (exp) => (
                                                    <div
                                                        key={exp.category}
                                                        className="border-border/50 flex items-center justify-between gap-2 border-b pb-2 last:border-0"
                                                    >
                                                        <div>
                                                            <div className="text-foreground font-medium capitalize">
                                                                {exp.category.replace(
                                                                    /_/g,
                                                                    ' ',
                                                                )}
                                                            </div>
                                                            <div className="text-muted-foreground">
                                                                {exp.count}{' '}
                                                                transaksi
                                                            </div>
                                                        </div>
                                                        <div className="font-semibold tabular-nums">
                                                            {formatIdr(
                                                                exp.total_amount,
                                                            )}
                                                        </div>
                                                    </div>
                                                ),
                                            )
                                        )
                                    ) : financial.spare_part_cogs_analysis
                                          .length === 0 ? (
                                        <p className="text-muted-foreground">
                                            Belum ada HPP spare-part tercatat.
                                        </p>
                                    ) : (
                                        financial.spare_part_cogs_analysis.map(
                                            (part) => (
                                                <div
                                                    key={part.item_id}
                                                    className="border-border/50 flex items-center justify-between gap-2 border-b pb-2 last:border-0"
                                                >
                                                    <div>
                                                        <div className="text-foreground font-medium">
                                                            {part.name}
                                                        </div>
                                                        <div className="text-muted-foreground">
                                                            {part.sku} ·{' '}
                                                            {part.net_units}{' '}
                                                            unit
                                                        </div>
                                                    </div>
                                                    <div className="font-semibold tabular-nums">
                                                        {formatIdr(
                                                            part.total_cogs,
                                                        )}
                                                    </div>
                                                </div>
                                            ),
                                        )
                                    )}
                                </CardContent>
                            </Card>
                        </div>

                        {/* Paginated Order Profitability Table */}
                        <Card className="border-border/70 bg-card/95">
                            <CardHeader className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <CardTitle className="text-base">
                                        Profitabilitas per Order Servis
                                    </CardTitle>
                                    <CardDescription>
                                        Menampilkan{' '}
                                        {financial.order_profitability.total}{' '}
                                        order pada periode terpilih.
                                    </CardDescription>
                                </div>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="overflow-x-auto">
                                    <table className="w-full min-w-[780px] text-left text-xs">
                                        <thead>
                                            <tr className="border-border text-muted-foreground border-b">
                                                <th className="py-2 pr-3 font-medium">
                                                    Tiket
                                                </th>
                                                <th className="py-2 pr-3 font-medium">
                                                    Layanan
                                                </th>
                                                <th className="py-2 pr-3 font-medium">
                                                    Pelanggan
                                                </th>
                                                <th className="py-2 pr-3 text-right font-medium">
                                                    Estimasi
                                                </th>
                                                <th className="py-2 pr-3 text-right font-medium">
                                                    Tertagih
                                                </th>
                                                <th className="py-2 pr-3 text-right font-medium">
                                                    HPP + Biaya
                                                </th>
                                                <th className="py-2 pr-3 text-right font-medium">
                                                    Laba Kotor
                                                </th>
                                                <th className="py-2 text-right font-medium">
                                                    Margin
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-border/60 divide-y">
                                            {financial.order_profitability.data
                                                .length === 0 ? (
                                                <tr>
                                                    <td
                                                        colSpan={8}
                                                        className="text-muted-foreground py-6 text-center"
                                                    >
                                                        Belum ada order pada
                                                        periode ini.
                                                    </td>
                                                </tr>
                                            ) : (
                                                financial.order_profitability.data.map(
                                                    (row) => (
                                                        <tr
                                                            key={
                                                                row.service_order_id
                                                            }
                                                            className="hover:bg-muted/30"
                                                        >
                                                            <td className="py-2.5 pr-3 font-mono font-medium">
                                                                <Link
                                                                    href={`/orders/${row.service_order_id}`}
                                                                    className="text-primary hover:underline"
                                                                >
                                                                    {
                                                                        row.ticket_number
                                                                    }
                                                                </Link>
                                                            </td>
                                                            <td className="py-2.5 pr-3">
                                                                {
                                                                    row.service_name
                                                                }
                                                            </td>
                                                            <td className="text-muted-foreground py-2.5 pr-3">
                                                                {
                                                                    row.customer_label
                                                                }
                                                            </td>
                                                            <td className="py-2.5 pr-3 text-right tabular-nums">
                                                                {formatIdr(
                                                                    row.estimated_total,
                                                                )}
                                                            </td>
                                                            <td className="py-2.5 pr-3 text-right font-medium tabular-nums">
                                                                {formatIdr(
                                                                    row.invoiced_total,
                                                                )}
                                                            </td>
                                                            <td className="text-muted-foreground py-2.5 pr-3 text-right tabular-nums">
                                                                {formatIdr(
                                                                    row.actual_cost_total,
                                                                )}
                                                            </td>
                                                            <td
                                                                className={cn(
                                                                    'py-2.5 pr-3 text-right font-semibold tabular-nums',
                                                                    row.gross_profit <
                                                                        0
                                                                        ? 'text-red-600 dark:text-red-400'
                                                                        : 'text-emerald-600 dark:text-emerald-400',
                                                                )}
                                                            >
                                                                {formatIdr(
                                                                    row.gross_profit,
                                                                )}
                                                            </td>
                                                            <td className="py-2.5 text-right tabular-nums">
                                                                {row.margin_pct !==
                                                                null
                                                                    ? `${row.margin_pct.toFixed(1)}%`
                                                                    : '—'}
                                                            </td>
                                                        </tr>
                                                    ),
                                                )
                                            )}
                                        </tbody>
                                    </table>
                                </div>

                                {financial.order_profitability.last_page >
                                    1 && (
                                    <div className="text-muted-foreground flex items-center justify-between text-xs">
                                        <span>
                                            Halaman{' '}
                                            {
                                                financial.order_profitability
                                                    .current_page
                                            }{' '}
                                            dari{' '}
                                            {
                                                financial.order_profitability
                                                    .last_page
                                            }
                                        </span>
                                        <div className="flex items-center gap-2">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                disabled={
                                                    financial
                                                        .order_profitability
                                                        .current_page <= 1
                                                }
                                                onClick={() =>
                                                    navigateAnalytics({
                                                        page:
                                                            financial
                                                                .order_profitability
                                                                .current_page -
                                                            1,
                                                    })
                                                }
                                            >
                                                <ChevronLeft className="size-4" />
                                            </Button>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                disabled={
                                                    financial
                                                        .order_profitability
                                                        .current_page >=
                                                    financial
                                                        .order_profitability
                                                        .last_page
                                                }
                                                onClick={() =>
                                                    navigateAnalytics({
                                                        page:
                                                            financial
                                                                .order_profitability
                                                                .current_page +
                                                            1,
                                                    })
                                                }
                                            >
                                                <ChevronRight className="size-4" />
                                            </Button>
                                        </div>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </div>
                )}

                {/* SERVICES & SALES SECTION */}
                {activeSection === 'services' && services && (
                    <div className="space-y-6">
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <KpiStatCard
                                title="Total Permintaan"
                                value={formatNumber(
                                    services.overview.total_requests,
                                )}
                                subtitle={`Tingkat pembatalan ${services.overview.cancellation_rate !== null ? `${services.overview.cancellation_rate.toFixed(1)}%` : '—'}`}
                            />
                            <KpiStatCard
                                title="Persetujuan Quotation"
                                value={
                                    services.overview
                                        .quotation_approval_rate !== null
                                        ? `${services.overview.quotation_approval_rate.toFixed(1)}%`
                                        : '—'
                                }
                                delta={
                                    services.overview
                                        .quotation_approval_delta_pp
                                }
                                deltaUnit="pp"
                                subtitle={`Konversi ke invoice ${services.overview.quotation_conversion_rate !== null ? `${services.overview.quotation_conversion_rate.toFixed(1)}%` : '—'}`}
                            />
                            <KpiStatCard
                                title="Rata-rata Nilai Quotation"
                                value={formatIdr(
                                    services.overview.avg_quotation_value,
                                )}
                                subtitle={`Rata-rata Invoice ${formatIdr(services.overview.avg_invoice_value)}`}
                            />
                            <KpiStatCard
                                title="Konversi Booking -> Invoice"
                                value={
                                    services.overview
                                        .booking_to_invoice_conversion_rate !==
                                    null
                                        ? `${services.overview.booking_to_invoice_conversion_rate.toFixed(1)}%`
                                        : '—'
                                }
                                subtitle={`Repeat order ${services.overview.repeat_service_rate !== null ? `${services.overview.repeat_service_rate.toFixed(1)}%` : '—'}`}
                            />
                        </div>

                        <div className="grid gap-4 lg:grid-cols-2">
                            <ComparativeBarChart
                                title="Layanan Paling Dimintai"
                                description="Volume order dan perubahan permintaan dibanding periode sebelumnya."
                                valueType="count"
                                items={services.most_requested.map((s) => ({
                                    label: `${s.name} (${s.code})`,
                                    value: s.requests_count,
                                    secondaryLabel: `Pertumbuhan: ${s.demand_change_pct !== null ? `${s.demand_change_pct > 0 ? '+' : ''}${s.demand_change_pct.toFixed(1)}%` : 'Pembanding 0'} · Batal ${s.cancelled_count}`,
                                }))}
                            />
                            <ComparativeBarChart
                                title="Layanan Paling Menguntungkan (Net Gross Profit)"
                                description="Diperingkatkan berdasarkan laba bersih aktual (bukan omzet kotor)."
                                valueType="currency"
                                items={services.most_profitable.map((s) => ({
                                    label: `${s.name} (${s.code})`,
                                    value: s.gross_profit,
                                    secondaryLabel: `Omzet ${formatIdr(s.invoiced_revenue)} · HPP ${formatIdr(s.spare_part_cogs)} · Margin ${s.margin_pct !== null ? `${s.margin_pct.toFixed(1)}%` : '—'}`,
                                    color:
                                        s.gross_profit < 0
                                            ? '#dc2626'
                                            : '#059669',
                                }))}
                            />
                        </div>

                        <div className="grid gap-4 lg:grid-cols-2">
                            <DistributionDonutChart
                                title="Distribusi Mode Layanan"
                                centerLabel="Order"
                                slices={services.service_mode_distribution.map(
                                    (m, idx) => ({
                                        label: m.mode,
                                        value: m.count,
                                        color: PALETTE[idx % PALETTE.length],
                                    }),
                                )}
                            />
                            <DistributionDonutChart
                                title="Distribusi Status Quotation"
                                centerLabel="Quotation"
                                slices={Object.entries(
                                    services.quotations.by_status,
                                ).map(([status, count], idx) => ({
                                    label: status,
                                    value: count,
                                    color: PALETTE[idx % PALETTE.length],
                                }))}
                            />
                        </div>

                        <Card className="border-border/70 bg-card/95">
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Matriks Lengkap Layanan & Margin
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="overflow-x-auto">
                                    <table className="w-full min-w-[740px] text-left text-xs">
                                        <thead>
                                            <tr className="border-border text-muted-foreground border-b">
                                                <th className="py-2 pr-3 font-medium">
                                                    Layanan
                                                </th>
                                                <th className="py-2 pr-3 font-medium">
                                                    Mode
                                                </th>
                                                <th className="py-2 pr-3 text-right font-medium">
                                                    Order
                                                </th>
                                                <th className="py-2 pr-3 text-right font-medium">
                                                    Tren
                                                </th>
                                                <th className="py-2 pr-3 text-right font-medium">
                                                    Tertagih
                                                </th>
                                                <th className="py-2 pr-3 text-right font-medium">
                                                    HPP + Langsung
                                                </th>
                                                <th className="py-2 pr-3 text-right font-medium">
                                                    Laba Kotor
                                                </th>
                                                <th className="py-2 text-right font-medium">
                                                    Margin
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-border/60 divide-y">
                                            {services.services.map((s) => (
                                                <tr
                                                    key={s.service_id}
                                                    className="hover:bg-muted/30"
                                                >
                                                    <td className="py-2.5 pr-3 font-medium">
                                                        {s.name}{' '}
                                                        <span className="text-muted-foreground">
                                                            ({s.code})
                                                        </span>
                                                    </td>
                                                    <td className="py-2.5 pr-3 capitalize">
                                                        {s.service_mode}
                                                    </td>
                                                    <td className="py-2.5 pr-3 text-right tabular-nums">
                                                        {s.requests_count}
                                                    </td>
                                                    <td className="py-2.5 pr-3 text-right tabular-nums">
                                                        {s.demand_change_pct !==
                                                        null
                                                            ? `${s.demand_change_pct > 0 ? '+' : ''}${s.demand_change_pct.toFixed(1)}%`
                                                            : '—'}
                                                    </td>
                                                    <td className="py-2.5 pr-3 text-right tabular-nums">
                                                        {formatIdr(
                                                            s.invoiced_revenue,
                                                        )}
                                                    </td>
                                                    <td className="text-muted-foreground py-2.5 pr-3 text-right tabular-nums">
                                                        {formatIdr(
                                                            s.spare_part_cogs +
                                                                s.direct_expenses,
                                                        )}
                                                    </td>
                                                    <td className="py-2.5 pr-3 text-right font-semibold tabular-nums">
                                                        {formatIdr(
                                                            s.gross_profit,
                                                        )}
                                                    </td>
                                                    <td className="py-2.5 text-right tabular-nums">
                                                        {s.margin_pct !== null
                                                            ? `${s.margin_pct.toFixed(1)}%`
                                                            : '—'}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                )}

                {/* WORKFORCE SECTION */}
                {activeSection === 'workforce' && workforce && (
                    <div className="space-y-6">
                        {workforce.is_personal_scope && (
                            <Alert>
                                <Info className="size-4" />
                                <AlertTitle>
                                    Cakupan Kinerja Pribadi Teknisi
                                </AlertTitle>
                                <AlertDescription>
                                    Menampilkan metrik pekerjaan, durasi sesi
                                    kerja, dan kepatuhan SLA milik Anda sendiri
                                    tanpa perbandingan rekan atau data keuangan
                                    perusahaan.
                                </AlertDescription>
                            </Alert>
                        )}

                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <KpiStatCard
                                title="Order Ditugaskan"
                                value={formatNumber(
                                    workforce.overview.assigned_jobs_period,
                                )}
                                subtitle={`${formatNumber(workforce.overview.completed_jobs_period)} selesai pada periode ini`}
                            />
                            <KpiStatCard
                                title="Kepatuhan SLA"
                                value={
                                    workforce.overview
                                        .avg_sla_compliance_rate !== null
                                        ? `${workforce.overview.avg_sla_compliance_rate.toFixed(1)}%`
                                        : '—'
                                }
                                subtitle={`${formatNumber(workforce.overview.reopened_jobs_period)} order di-reopen`}
                            />
                            <KpiStatCard
                                title="Rata-rata Resolusi"
                                value={formatNumber(
                                    workforce.overview.avg_resolution_minutes,
                                    ' mnt',
                                )}
                                subtitle={`Rata-rata sesi kerja ${formatNumber(workforce.overview.avg_work_session_minutes, ' mnt')}`}
                            />
                            <KpiStatCard
                                title={
                                    workforce.is_personal_scope
                                        ? 'Status Akun Anda'
                                        : 'Total Teknisi Aktif'
                                }
                                value={formatNumber(
                                    workforce.overview.technicians_count,
                                )}
                                subtitle={
                                    workforce.is_personal_scope
                                        ? (workforce.technicians[0]
                                              ?.availability_label_id ??
                                          'Tersedia')
                                        : `Tersedia: ${workforce.availability_distribution.available ?? 0} · Sibuk: ${workforce.availability_distribution.busy ?? 0}`
                                }
                            />
                        </div>

                        {!workforce.is_personal_scope && (
                            <div className="grid gap-4 lg:grid-cols-2">
                                <DistributionDonutChart
                                    title="Ketersediaan Teknisi Saat Ini"
                                    centerLabel="Teknisi"
                                    slices={Object.entries(
                                        workforce.availability_distribution,
                                    ).map(([k, v], idx) => ({
                                        label: k,
                                        value: v,
                                        color: PALETTE[idx % PALETTE.length],
                                    }))}
                                />
                                <DistributionDonutChart
                                    title="Distribusi Beban Kerja Aktif"
                                    centerLabel="Teknisi"
                                    slices={Object.entries(
                                        workforce.workload_distribution,
                                    ).map(([k, v], idx) => ({
                                        label: k,
                                        value: v,
                                        color: PALETTE[
                                            (idx + 2) % PALETTE.length
                                        ],
                                    }))}
                                />
                            </div>
                        )}

                        <Card className="border-border/70 bg-card/95">
                            <CardHeader>
                                <CardTitle className="text-base">
                                    {workforce.is_personal_scope
                                        ? 'Rincian Kinerja & Beban Tugas Saya'
                                        : 'Kinerja Teknisi dengan Konteks Kompleksitas & SLA'}
                                </CardTitle>
                                <CardDescription>
                                    Evaluasi mempertimbangkan porsi pekerjaan
                                    risiko/urgensi tinggi dan beban kerja aktif.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div className="overflow-x-auto">
                                    <table className="w-full min-w-[780px] text-left text-xs">
                                        <thead>
                                            <tr className="border-border text-muted-foreground border-b">
                                                <th className="py-2 pr-3 font-medium">
                                                    Teknisi
                                                </th>
                                                <th className="py-2 pr-3 font-medium">
                                                    Status
                                                </th>
                                                <th className="py-2 pr-3 text-right font-medium">
                                                    Ditugaskan
                                                </th>
                                                <th className="py-2 pr-3 text-right font-medium">
                                                    Selesai
                                                </th>
                                                <th className="py-2 pr-3 text-right font-medium">
                                                    Jam Kerja
                                                </th>
                                                <th className="py-2 pr-3 text-right font-medium">
                                                    SLA
                                                </th>
                                                <th className="py-2 pr-3 text-right font-medium">
                                                    Beban Aktif
                                                </th>
                                                <th className="py-2 font-medium">
                                                    Konteks Kompleksitas
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-border/60 divide-y">
                                            {workforce.technicians.map(
                                                (tech) => (
                                                    <tr
                                                        key={tech.id}
                                                        className="hover:bg-muted/30"
                                                    >
                                                        <td className="py-2.5 pr-3">
                                                            <div className="text-foreground font-medium">
                                                                {tech.name}
                                                            </div>
                                                            <div className="text-muted-foreground">
                                                                {tech.specialty}
                                                            </div>
                                                        </td>
                                                        <td className="py-2.5 pr-3">
                                                            <Badge
                                                                variant="outline"
                                                                className="text-[11px]"
                                                            >
                                                                {
                                                                    tech.availability_label_id
                                                                }
                                                            </Badge>
                                                        </td>
                                                        <td className="py-2.5 pr-3 text-right tabular-nums">
                                                            {
                                                                tech.assigned_jobs_period
                                                            }
                                                        </td>
                                                        <td className="py-2.5 pr-3 text-right tabular-nums">
                                                            {
                                                                tech.completed_jobs_period
                                                            }
                                                        </td>
                                                        <td className="py-2.5 pr-3 text-right tabular-nums">
                                                            {tech.total_work_hours_period.toFixed(
                                                                1,
                                                            )}{' '}
                                                            jam
                                                        </td>
                                                        <td className="py-2.5 pr-3 text-right font-medium tabular-nums">
                                                            {tech.sla_compliance_rate_period !==
                                                            null
                                                                ? `${tech.sla_compliance_rate_period.toFixed(1)}%`
                                                                : '—'}
                                                        </td>
                                                        <td className="py-2.5 pr-3 text-right tabular-nums">
                                                            {
                                                                tech.current_workload
                                                            }{' '}
                                                            (
                                                            {
                                                                tech.sla_risk_assignments_count
                                                            }{' '}
                                                            risiko)
                                                        </td>
                                                        <td className="text-muted-foreground py-2.5">
                                                            {tech.context_note}
                                                        </td>
                                                    </tr>
                                                ),
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                )}

                {/* INVENTORY SECTION */}
                {activeSection === 'inventory' && inventory && (
                    <div className="space-y-6">
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <KpiStatCard
                                title="Valuasi Aset Inventori"
                                basisLabel="WAC"
                                value={formatIdr(
                                    inventory.overview.asset_value,
                                )}
                                subtitle={`${formatNumber(inventory.overview.item_types)} SKU aktif · ${formatNumber(inventory.overview.units_on_hand)} unit`}
                                href="/inventory"
                            />
                            <KpiStatCard
                                title="Rasio Turnover Stok"
                                value={
                                    inventory.overview.stock_turnover_ratio !==
                                    null
                                        ? `${inventory.overview.stock_turnover_ratio.toFixed(2)}x`
                                        : '—'
                                }
                                subtitle={`${formatNumber(inventory.overview.outbound_units_period)} unit keluar periode ini`}
                            />
                            <KpiStatCard
                                title="HPP Spare-Part Terpakai"
                                value={formatIdr(
                                    inventory.overview
                                        .realized_spare_part_cogs_period,
                                )}
                                subtitle={
                                    inventory.can_view_finance_ledger &&
                                    inventory.overview
                                        .verified_purchase_expenses_period !==
                                        null
                                        ? `Pembelian terverifikasi ${formatIdr(inventory.overview.verified_purchase_expenses_period)}`
                                        : `Nilai stok masuk ${formatIdr(inventory.overview.inbound_value_period)}`
                                }
                            />
                            <KpiStatCard
                                title="Indikator Reorder"
                                value={`${inventory.overview.low_stock_count} SKU`}
                                tone={
                                    inventory.overview.out_of_stock_count > 0
                                        ? 'danger'
                                        : 'warning'
                                }
                                subtitle={`${inventory.overview.out_of_stock_count} item habis (0 unit)`}
                            />
                        </div>

                        <div className="grid gap-4 lg:grid-cols-2">
                            <InteractiveTimeSeriesChart
                                title="Tren Pergerakan Stok (Masuk vs Keluar)"
                                description="Unit masuk melalui stok masuk vs keluar (spare-part issue & stock-out)."
                                data={inventory.movement_trends}
                                valueType="count"
                                series={[
                                    {
                                        key: 'stock_in',
                                        label: 'Stok Masuk',
                                        color: '#059669',
                                    },
                                    {
                                        key: 'net_outbound',
                                        label: 'Keluar Bersih',
                                        color: '#dc2626',
                                    },
                                ]}
                            />
                            <ComparativeBarChart
                                title="Valuasi Aset per Kategori"
                                valueType="currency"
                                items={inventory.valuation_by_category.map(
                                    (cat) => ({
                                        label: `${cat.category} (${cat.items_count} SKU)`,
                                        value: cat.asset_value,
                                        secondaryLabel: `${formatNumber(cat.units)} unit tersedia`,
                                    }),
                                )}
                            />
                        </div>

                        <div className="grid gap-4 lg:grid-cols-3">
                            <Card className="border-border/70 bg-card/95">
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-base">
                                        Item Cepat Bergerak (Fast-Moving)
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-2 text-xs">
                                    {inventory.fast_moving.length === 0 ? (
                                        <p className="text-muted-foreground">
                                            Belum ada item fast-moving pada
                                            periode ini.
                                        </p>
                                    ) : (
                                        inventory.fast_moving.map((item) => (
                                            <div
                                                key={item.id}
                                                className="border-border/50 flex justify-between gap-2 border-b pb-2 last:border-0"
                                            >
                                                <div>
                                                    <div className="font-medium">
                                                        {item.name}
                                                    </div>
                                                    <div className="text-muted-foreground">
                                                        {item.sku} · Sisa{' '}
                                                        {item.quantity_on_hand}{' '}
                                                        {item.unit}
                                                    </div>
                                                </div>
                                                <div className="text-right font-semibold tabular-nums">
                                                    {item.outbound_units_period}{' '}
                                                    keluar (
                                                    {item.turnover_ratio}x)
                                                </div>
                                            </div>
                                        ))
                                    )}
                                </CardContent>
                            </Card>

                            <Card className="border-border/70 bg-card/95">
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-base">
                                        Kandidat Slow / Dead-Stock
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-2 text-xs">
                                    {[
                                        ...inventory.dead_stock_candidates,
                                        ...inventory.slow_moving,
                                    ].length === 0 ? (
                                        <p className="text-muted-foreground">
                                            Tidak ada kandidat slow/dead-stock
                                            terdeteksi.
                                        </p>
                                    ) : (
                                        [
                                            ...inventory.dead_stock_candidates,
                                            ...inventory.slow_moving,
                                        ]
                                            .slice(0, 6)
                                            .map((item) => (
                                                <div
                                                    key={item.id}
                                                    className="border-border/50 flex justify-between gap-2 border-b pb-2 last:border-0"
                                                >
                                                    <div>
                                                        <div className="font-medium">
                                                            {item.name}
                                                        </div>
                                                        <div className="text-muted-foreground">
                                                            {
                                                                item.days_since_last_outbound
                                                            }{' '}
                                                            hari pasif ·{' '}
                                                            {
                                                                item.velocity_class
                                                            }
                                                        </div>
                                                    </div>
                                                    <div className="text-right font-semibold tabular-nums">
                                                        {formatIdr(
                                                            item.asset_value,
                                                        )}
                                                    </div>
                                                </div>
                                            ))
                                    )}
                                </CardContent>
                            </Card>

                            <Card className="border-border/70 bg-card/95">
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-base">
                                        Prioritas Reorder Manual
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-2 text-xs">
                                    {inventory.reorder_indicators.length ===
                                    0 ? (
                                        <p className="text-muted-foreground">
                                            Semua item berada di atas batas
                                            minimum.
                                        </p>
                                    ) : (
                                        inventory.reorder_indicators
                                            .slice(0, 6)
                                            .map((item) => (
                                                <div
                                                    key={item.id}
                                                    className="border-border/50 flex justify-between gap-2 border-b pb-2 last:border-0"
                                                >
                                                    <div>
                                                        <div className="font-medium">
                                                            {item.name}
                                                        </div>
                                                        <div className="text-muted-foreground">
                                                            Sisa{' '}
                                                            {
                                                                item.quantity_on_hand
                                                            }{' '}
                                                            / Min{' '}
                                                            {item.reorder_level}
                                                        </div>
                                                    </div>
                                                    <Badge
                                                        variant={
                                                            item.severity ===
                                                            'out_of_stock'
                                                                ? 'destructive'
                                                                : 'outline'
                                                        }
                                                    >
                                                        +
                                                        {
                                                            item.suggested_restock_qty
                                                        }{' '}
                                                        unit
                                                    </Badge>
                                                </div>
                                            ))
                                    )}
                                </CardContent>
                            </Card>
                        </div>
                    </div>
                )}

                {/* CUSTOMERS SECTION */}
                {activeSection === 'customers' && customers && (
                    <div className="space-y-6">
                        {!customers.can_view_pii && (
                            <Alert>
                                <Info className="size-4" />
                                <AlertTitle>
                                    Agregasi Privasi Pelanggan Aktif
                                </AlertTitle>
                                <AlertDescription>
                                    Detail email, telepon, dan nama lengkap
                                    pelanggan disembunyikan sesuai kebijakan
                                    akses peran Anda.
                                </AlertDescription>
                            </Alert>
                        )}

                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <KpiStatCard
                                title="Pelanggan Terdaftar"
                                value={formatNumber(
                                    customers.overview
                                        .total_registered_customers,
                                )}
                                subtitle={`+${formatNumber(customers.overview.new_registered_period)} akun baru periode ini`}
                            />
                            <KpiStatCard
                                title="Repeat Service Rate"
                                value={
                                    customers.overview.repeat_service_rate !==
                                    null
                                        ? `${customers.overview.repeat_service_rate.toFixed(1)}%`
                                        : '—'
                                }
                                subtitle={`${customers.overview.returning_customers_period} kembali · ${customers.overview.new_ordering_customers_period} baru pesan`}
                            />
                            <KpiStatCard
                                title="Rata-rata Lifetime Value"
                                value={formatIdr(
                                    customers.overview.avg_lifetime_value,
                                )}
                                subtitle={`Frekuensi ${customers.overview.avg_service_frequency ?? '—'} order / pelanggan`}
                            />
                            <KpiStatCard
                                title="Garansi & Klaim"
                                value={`${customers.overview.active_warranties_total} aktif`}
                                subtitle={`${customers.overview.warranty_claims_period} klaim periode ini (${customers.overview.warranty_claim_rate_pct !== null ? `${customers.overview.warranty_claim_rate_pct.toFixed(1)}%` : '—'})`}
                                href="/warranties"
                            />
                        </div>

                        <div className="grid gap-4 lg:grid-cols-2">
                            <InteractiveTimeSeriesChart
                                title="Tren Akuisisi & Pelanggan Memesan"
                                data={customers.acquisition_trends}
                                valueType="count"
                                series={[
                                    {
                                        key: 'active_ordering_customers',
                                        label: 'Pelanggan Memesan',
                                        color: '#dc2626',
                                    },
                                    {
                                        key: 'new_registered_customers',
                                        label: 'Registrasi Baru',
                                        color: '#2563eb',
                                    },
                                ]}
                            />
                            <ComparativeBarChart
                                title="Layanan Paling Sering Diminta Pelanggan"
                                valueType="count"
                                items={customers.most_requested_services.map(
                                    (s) => ({
                                        label: `${s.name} (${s.code})`,
                                        value: s.orders_count,
                                    }),
                                )}
                            />
                        </div>

                        <div className="grid gap-4 lg:grid-cols-2">
                            <Card className="border-border/70 bg-card/95">
                                <CardHeader>
                                    <CardTitle className="text-base">
                                        Segmen Nilai Layanan Tertinggi (LTV)
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-2.5 text-xs">
                                    {customers.top_lifetime_segments.length ===
                                    0 ? (
                                        <p className="text-muted-foreground">
                                            Belum ada histori tagihan pelanggan.
                                        </p>
                                    ) : (
                                        customers.top_lifetime_segments.map(
                                            (seg, idx) => (
                                                <div
                                                    key={idx}
                                                    className="border-border/50 flex justify-between gap-2 border-b pb-2 last:border-0"
                                                >
                                                    <div>
                                                        <div className="text-foreground font-medium">
                                                            {seg.customer_label}
                                                        </div>
                                                        <div className="text-muted-foreground">
                                                            {seg.invoices_count}{' '}
                                                            invoice
                                                        </div>
                                                    </div>
                                                    <div className="text-right">
                                                        <div className="font-semibold tabular-nums">
                                                            {formatIdr(
                                                                seg.lifetime_invoiced,
                                                            )}
                                                        </div>
                                                        <div className="text-muted-foreground tabular-nums">
                                                            Lunas{' '}
                                                            {formatIdr(
                                                                seg.lifetime_paid,
                                                            )}
                                                        </div>
                                                    </div>
                                                </div>
                                            ),
                                        )
                                    )}
                                </CardContent>
                            </Card>

                            <DistributionDonutChart
                                title="Kategori Perangkat Terdaftar"
                                description={`Total ${customers.overview.registered_devices_total} perangkat tercatat.`}
                                centerLabel="Unit"
                                slices={customers.device_categories.map(
                                    (d, idx) => ({
                                        label: d.category,
                                        value: d.count,
                                        color: PALETTE[idx % PALETTE.length],
                                    }),
                                )}
                            />
                        </div>
                    </div>
                )}

                {/* BUSINESS INSIGHTS SECTION */}
                {activeSection === 'insights' && (
                    <div className="space-y-4">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h2 className="text-lg font-semibold">
                                    Mesin Insight Bisnis Deterministik
                                </h2>
                                <p className="text-muted-foreground text-xs">
                                    Semua insight diturunkan langsung dari
                                    ambang batas statistik terukur & data real
                                    AD Com.
                                </p>
                            </div>
                            <div className="flex items-center gap-1.5">
                                {[
                                    { key: 'all', label: 'Semua' },
                                    { key: 'critical', label: 'Kritis' },
                                    { key: 'warning', label: 'Peringatan' },
                                    { key: 'info', label: 'Info' },
                                ].map((f) => (
                                    <Button
                                        key={f.key}
                                        variant={
                                            insightFilter === f.key
                                                ? 'default'
                                                : 'outline'
                                        }
                                        size="sm"
                                        onClick={() => setInsightFilter(f.key)}
                                    >
                                        {f.label}
                                    </Button>
                                ))}
                            </div>
                        </div>

                        {filteredInsights.length === 0 ? (
                            <Card className="border-border/70 bg-card/95">
                                <CardContent className="flex flex-col items-center justify-center gap-2 py-12 text-center">
                                    <TrendingUp className="text-muted-foreground/70 size-8" />
                                    <div className="text-sm font-medium">
                                        Tidak ada insight untuk filter ini
                                    </div>
                                    <p className="text-muted-foreground max-w-md text-xs">
                                        Operasional berada dalam ambang normal
                                        pada periode {period.label}.
                                    </p>
                                </CardContent>
                            </Card>
                        ) : (
                            <div className="grid gap-4 md:grid-cols-2">
                                {filteredInsights.map((insight) => (
                                    <InsightCard
                                        key={insight.id}
                                        insight={insight}
                                    />
                                ))}
                            </div>
                        )}
                    </div>
                )}
            </div>
        </>
    );
}

function MetricRow({ label, value }: { label: string; value: string }) {
    return (
        <div className="border-border/50 flex items-center justify-between gap-2 border-b pb-2 last:border-0">
            <span className="text-muted-foreground">{label}</span>
            <span className="font-semibold tabular-nums">{value}</span>
        </div>
    );
}

function InsightRow({ insight }: { insight: BusinessInsight }) {
    return (
        <div className="border-border/70 bg-background/70 flex flex-col justify-between gap-2 rounded-xl border p-3 sm:flex-row sm:items-center">
            <div className="space-y-1">
                <div className="flex flex-wrap items-center gap-2">
                    <Badge
                        variant={
                            insight.severity === 'critical'
                                ? 'destructive'
                                : 'outline'
                        }
                        className="text-[10px] uppercase"
                    >
                        {insight.severity}
                    </Badge>
                    <span className="text-sm font-medium">{insight.title}</span>
                </div>
                <p className="text-muted-foreground text-xs">
                    {insight.description}
                </p>
            </div>
            <Link
                href={insight.href}
                className="text-primary inline-flex shrink-0 items-center gap-1 text-xs font-medium hover:underline"
            >
                Tindak Lanjut
                <ArrowRight className="size-3.5" />
            </Link>
        </div>
    );
}

function InsightCard({ insight }: { insight: BusinessInsight }) {
    return (
        <Card
            className={cn(
                'border-border/70 bg-card/95',
                insight.severity === 'critical' &&
                    'border-red-500/40 bg-red-500/[0.03]',
                insight.severity === 'warning' &&
                    'border-amber-500/40 bg-amber-500/[0.03]',
            )}
        >
            <CardHeader className="pb-2">
                <div className="flex items-start justify-between gap-2">
                    <div className="space-y-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <Badge
                                variant={
                                    insight.severity === 'critical'
                                        ? 'destructive'
                                        : 'outline'
                                }
                                className="text-[10px] uppercase"
                            >
                                {insight.severity}
                            </Badge>
                            <Badge
                                variant="secondary"
                                className="text-[10px] uppercase"
                            >
                                {insight.category}
                            </Badge>
                        </div>
                        <CardTitle className="flex items-center gap-2 text-base">
                            {insight.severity === 'critical' && (
                                <ShieldAlert className="size-4 text-red-500" />
                            )}
                            {insight.title}
                        </CardTitle>
                    </div>
                </div>
            </CardHeader>
            <CardContent className="space-y-3 text-xs">
                <p className="text-foreground text-sm">{insight.description}</p>
                <div className="border-border/60 bg-muted/30 rounded-lg border p-2.5">
                    <div className="text-muted-foreground mb-1 font-medium">
                        Rekomendasi Aksi:
                    </div>
                    <p className="text-foreground">
                        {insight.recommended_action}
                    </p>
                </div>
                <div className="text-muted-foreground flex flex-wrap items-center justify-between gap-2 pt-1 text-[11px]">
                    <span>
                        Sumber: {insight.source_label} ·{' '}
                        {insight.comparison_period}
                    </span>
                    <Link
                        href={insight.href}
                        className="text-primary inline-flex items-center gap-1 font-medium hover:underline"
                    >
                        Buka Modul
                        <ArrowRight className="size-3" />
                    </Link>
                </div>
            </CardContent>
        </Card>
    );
}
