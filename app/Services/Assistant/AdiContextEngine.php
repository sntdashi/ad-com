<?php

namespace App\Services\Assistant;

use App\Models\CustomerAsset;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Models\Warranty;
use App\Services\Analytics\AnalyticsAccess;
use App\Services\Analytics\AnalyticsPeriod;
use App\Services\Analytics\BusinessInsightsEngine;
use App\Services\Analytics\CustomerAnalyticsService;
use App\Services\Analytics\ExecutiveAnalyticsService;
use App\Services\Analytics\FinancialAnalyticsService;
use App\Services\Analytics\InventoryAnalyticsService;
use App\Services\Analytics\ServiceSalesAnalyticsService;
use App\Services\Analytics\WorkforceAnalyticsService;
use App\Services\Sla\SlaService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class AdiContextEngine
{
    public function __construct(
        private UserContextResolver $userContextResolver,
        private AnalyticsAccess $analyticsAccess,
        private ExecutiveAnalyticsService $executiveService,
        private FinancialAnalyticsService $financialService,
        private ServiceSalesAnalyticsService $serviceSalesService,
        private WorkforceAnalyticsService $workforceService,
        private InventoryAnalyticsService $inventoryService,
        private CustomerAnalyticsService $customerService,
        private BusinessInsightsEngine $insightsEngine,
        private SlaService $slaService,
    ) {}

    /**
     * Assemble a role-scoped, bounded, PII/secret-safe operational context snapshot.
     *
     * @param  array{
     *     route?: string|null,
     *     order_id?: string|null,
     *     period?: string|null,
     *     section?: string|null,
     *     start_date?: string|null,
     *     end_date?: string|null
     * }  $pageContext
     * @return array{
     *     generated_at: string,
     *     timezone: string,
     *     user: array<string, mixed>,
     *     capabilities: array<string, mixed>|null,
     *     period: array<string, mixed>,
     *     fact_boundary: array{
     *         verified_facts_rule: string,
     *         inferred_conclusions_rule: string
     *     },
     *     operational_snapshot: array<string, mixed>,
     *     insights_summary: list<array<string, mixed>>,
     *     catalog: list<array<string, mixed>>
     * }
     */
    public function build(?User $user, array $pageContext = [], ?AnalyticsPeriod $explicitPeriod = null): array
    {
        $timezone = (string) config('app.timezone', 'Asia/Jakarta');
        $generatedAt = CarbonImmutable::now($timezone)->toIso8601String();
        $userContext = $this->userContextResolver->resolve($user, $pageContext);
        $capabilities = $user !== null ? $this->analyticsAccess->toCapabilityArray($user) : null;

        $period = $explicitPeriod ?? AnalyticsPeriod::resolve(
            preset: $pageContext['period'] ?? 'monthly',
            startDate: $pageContext['start_date'] ?? null,
            endDate: $pageContext['end_date'] ?? null,
        );

        $snapshot = $this->buildOperationalSnapshot($user, $period);
        $insightsSummary = $this->buildInsightsSummary($user, $period);
        $catalog = $this->boundedCatalog();

        return [
            'generated_at' => $generatedAt,
            'timezone' => $timezone,
            'user' => $userContext,
            'capabilities' => $capabilities,
            'period' => [
                'preset' => $period->preset,
                'label' => $period->label(),
                'comparison_label' => $period->comparisonLabel(),
                'start_date' => $period->start->toDateString(),
                'end_date' => $period->end->toDateString(),
            ],
            'fact_boundary' => [
                'verified_facts_rule' => 'Angka KPI, jumlah order, status SLA, stok, dan nominal rupiah pada operational_snapshot adalah fakta terverifikasi backend untuk periode tertera.',
                'inferred_conclusions_rule' => 'Saran tindak lanjut atau hipotesis penyebab bisnis adalah analisis inferensial, bukan jaminan atau mutasi data otomatis.',
            ],
            'operational_snapshot' => $snapshot,
            'insights_summary' => $insightsSummary,
            'catalog' => $catalog,
        ];
    }

    /**
     * Render a compact JSON string safe for model prompt instructions.
     *
     * @param  array<string, mixed>  $context
     */
    public function toPromptJson(array $context): string
    {
        $compact = [
            'generated_at' => $context['generated_at'] ?? null,
            'timezone' => $context['timezone'] ?? 'Asia/Jakarta',
            'user' => $context['user'] ?? [],
            'capabilities' => $context['capabilities'] ?? null,
            'period' => $context['period'] ?? [],
            'operational_snapshot' => $context['operational_snapshot'] ?? [],
            'insights_summary' => $context['insights_summary'] ?? [],
        ];

        return json_encode($compact, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOperationalSnapshot(?User $user, AnalyticsPeriod $period): array
    {
        if ($user === null || ! $user->is_active) {
            return [
                'scope' => 'public',
                'note' => 'Pengunjung publik hanya memiliki akses ke katalog layanan dan panduan umum.',
            ];
        }

        return match ($user->role) {
            'customer' => $this->customerSnapshot($user),
            'technician' => $this->technicianSnapshot($user, $period),
            'warehouse' => $this->warehouseSnapshot($user, $period),
            'supervisor' => $this->managementSnapshot($user, $period, includeRawFinance: false, includeServiceAndCustomers: true),
            'admin', 'operator' => $this->managementSnapshot($user, $period, includeRawFinance: true, includeServiceAndCustomers: true),
            default => ['scope' => 'restricted'],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function customerSnapshot(User $customer): array
    {
        $orders = ServiceOrder::query()
            ->with('service:id,code,name')
            ->where('customer_id', $customer->id)
            ->latest()
            ->limit(3)
            ->get(['id', 'ticket_number', 'service_id', 'status', 'urgency', 'created_at', 'resolution_due_at']);

        $activeAssets = CustomerAsset::query()
            ->where('customer_id', $customer->id)
            ->count();

        $activeWarranties = Warranty::query()
            ->where('customer_id', $customer->id)
            ->where('status', 'active')
            ->count();

        return [
            'scope' => 'customer_own',
            'own_orders_recent' => $orders->map(fn (ServiceOrder $order): array => [
                'ticket_number' => $order->ticket_number,
                'service' => $order->service?->name,
                'status' => $order->status,
                'created_at' => $order->created_at->timezone('Asia/Jakarta')->translatedFormat('d M Y H:i'),
            ])->values()->all(),
            'registered_devices_count' => $activeAssets,
            'active_warranties_count' => $activeWarranties,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function technicianSnapshot(User $technician, AnalyticsPeriod $period): array
    {
        $workforce = $this->workforceService->summary($period, $technician);
        $selfRow = $workforce['technicians'][0] ?? null;

        $assignedOrders = ServiceOrder::query()
            ->whereNotIn('status', SlaService::TERMINAL_STATUSES)
            ->where(function (Builder $query) use ($technician): void {
                $query->where('assigned_technician_id', $technician->id)
                    ->orWhereHas('activeTechnicianAssignments', fn (Builder $q) => $q->where('user_id', $technician->id));
            })
            ->latest()
            ->limit(5)
            ->get(['id', 'ticket_number', 'status', 'urgency', 'risk_level', 'created_at', 'first_responded_at', 'response_due_at', 'resolution_due_at', 'sla_paused_at', 'sla_paused_seconds', 'completed_at']);

        $activeOrderItems = [];
        foreach ($assignedOrders as $order) {
            $sla = $this->slaService->evaluate($order);
            $activeOrderItems[] = [
                'ticket_number' => $order->ticket_number,
                'status' => $order->status,
                'sla_status' => $sla['label_id'],
            ];
        }

        return [
            'scope' => 'technician_self',
            'self_metrics' => $selfRow !== null ? [
                'availability_status' => $selfRow['availability_label_id'],
                'assigned_jobs_period' => $selfRow['assigned_jobs_period'],
                'completed_jobs_period' => $selfRow['completed_jobs_period'],
                'sla_compliance_rate_period' => $selfRow['sla_compliance_rate_period'],
                'avg_resolution_minutes_period' => $selfRow['avg_resolution_minutes_period'],
                'current_workload' => $selfRow['current_workload'],
            ] : null,
            'assigned_active_orders' => $activeOrderItems,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function warehouseSnapshot(User $warehouseUser, AnalyticsPeriod $period): array
    {
        $inventory = $this->inventoryService->summary($period, $warehouseUser);
        $overview = $inventory['overview'];

        $topReorderAlerts = array_map(
            fn (array $row): array => [
                'sku' => $row['sku'],
                'name' => $row['name'],
                'quantity_on_hand' => $row['quantity_on_hand'],
                'reorder_level' => $row['reorder_level'],
                'severity' => $row['severity'],
            ],
            array_slice($inventory['reorder_indicators'], 0, 5),
        );

        return [
            'scope' => 'warehouse_inventory',
            'inventory_overview' => [
                'item_types' => $overview['item_types'],
                'units_on_hand' => $overview['units_on_hand'],
                'asset_value' => $overview['asset_value'],
                'low_stock_count' => $overview['low_stock_count'],
                'out_of_stock_count' => $overview['out_of_stock_count'],
                'stock_turnover_ratio' => $overview['stock_turnover_ratio'],
                'outbound_units_period' => $overview['outbound_units_period'],
            ],
            'top_reorder_alerts' => $topReorderAlerts,
            'dead_stock_count' => count($inventory['dead_stock_candidates']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function managementSnapshot(
        User $viewer,
        AnalyticsPeriod $period,
        bool $includeRawFinance,
        bool $includeServiceAndCustomers,
    ): array {
        $exec = $this->executiveService->summary($period, $viewer);
        $kpis = $exec['kpis'] ?? [];

        $snapshot = [
            'scope' => $includeRawFinance ? 'management_full' : 'supervisor_oversight',
            'executive_kpis' => [
                'total_orders' => $kpis['total_orders']['value'] ?? 0,
                'active_orders' => $kpis['active_orders']['value'] ?? 0,
                'completed_orders' => $kpis['completed_orders']['value'] ?? 0,
                'completion_rate_pct' => $kpis['completion_rate']['value'] ?? null,
                'sla_compliance_rate_pct' => $kpis['sla_compliance_rate']['value'] ?? null,
                'invoiced_revenue' => $kpis['invoiced_revenue']['value'] ?? 0,
                'cash_received' => $kpis['cash_received']['value'] ?? 0,
                'gross_profit_accrual' => $kpis['gross_profit_accrual']['value'] ?? 0,
                'outstanding_receivables' => $kpis['outstanding_receivables']['value'] ?? 0,
                'inventory_valuation' => $kpis['inventory_valuation']['value'] ?? 0,
                'active_customers' => $kpis['active_customers']['value'] ?? 0,
                'repeat_customer_rate_pct' => $kpis['repeat_customer_rate']['value'] ?? null,
            ],
            'sla_snapshot' => $exec['sla_snapshot'] ?? [],
        ];

        if ($includeRawFinance) {
            $financial = $this->financialService->report($period, $viewer, 1, 5);
            $snapshot['financial_summary'] = $financial['summary'] ?? [];
            $inventory = $this->inventoryService->summary($period, $viewer);
            $snapshot['inventory_alerts_count'] = [
                'low_stock' => $inventory['overview']['low_stock_count'] ?? 0,
                'out_of_stock' => $inventory['overview']['out_of_stock_count'] ?? 0,
                'dead_stock_candidates' => count($inventory['dead_stock_candidates'] ?? []),
            ];
        }

        if ($includeServiceAndCustomers) {
            $servicesSummary = $this->serviceSalesService->summary($period);
            $sortedServices = $servicesSummary['services'];
            usort($sortedServices, fn (array $a, array $b): int => ($b['gross_profit'] <=> $a['gross_profit']));
            $snapshot['top_services'] = array_map(
                fn (array $row): array => [
                    'code' => $row['code'],
                    'name' => $row['name'],
                    'requests_count' => $row['requests_count'],
                    'invoiced_revenue' => $row['invoiced_revenue'],
                    'gross_profit' => $row['gross_profit'],
                    'margin_pct' => $row['margin_pct'],
                ],
                array_slice($sortedServices, 0, 4),
            );

            $customerSummary = $this->customerService->summary($period, $viewer);
            $snapshot['customer_overview'] = [
                'active_ordering_customers_period' => $customerSummary['overview']['active_ordering_customers_period'],
                'returning_customers_period' => $customerSummary['overview']['returning_customers_period'],
                'repeat_service_rate' => $customerSummary['overview']['repeat_service_rate'],
                'active_warranties_total' => $customerSummary['overview']['active_warranties_total'],
            ];
        }

        return $snapshot;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildInsightsSummary(?User $user, AnalyticsPeriod $period): array
    {
        if ($user === null || ! $this->analyticsAccess->canAccessHub($user)) {
            return [];
        }

        return array_map(
            fn (array $insight): array => [
                'id' => $insight['id'],
                'title' => $insight['title'],
                'category' => $insight['category'],
                'severity' => $insight['severity'],
                'description' => $insight['description'],
                'supporting_metrics' => $insight['supporting_metrics'],
                'recommended_action' => $insight['recommended_action'],
            ],
            array_slice($this->insightsEngine->generate($period, $user), 0, 6),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function boundedCatalog(): array
    {
        $rows = [];
        foreach (Service::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(20)
            ->get(['code', 'slug', 'name', 'summary', 'pricing_label', 'service_mode', 'risk_level']) as $service) {
            $rows[] = $service->toArray();
        }

        return $rows;
    }
}
