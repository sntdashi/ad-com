<?php

namespace App\Http\Controllers;

use App\Models\FinancialTransaction;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\Analytics\AnalyticsAccess;
use App\Services\Analytics\AnalyticsCacheManager;
use App\Services\Analytics\AnalyticsExportService;
use App\Services\Analytics\AnalyticsPeriod;
use App\Services\Analytics\BusinessInsightsEngine;
use App\Services\Analytics\CustomerAnalyticsService;
use App\Services\Analytics\ExecutiveAnalyticsService;
use App\Services\Analytics\FinancialAnalyticsService;
use App\Services\Analytics\InventoryAnalyticsService;
use App\Services\Analytics\ServiceSalesAnalyticsService;
use App\Services\Analytics\WorkforceAnalyticsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnalyticsController extends Controller
{
    public function __construct(
        public AnalyticsAccess $access,
        public AnalyticsCacheManager $cacheManager,
        public ExecutiveAnalyticsService $executiveService,
        public FinancialAnalyticsService $financialService,
        public ServiceSalesAnalyticsService $serviceSalesService,
        public WorkforceAnalyticsService $workforceService,
        public InventoryAnalyticsService $inventoryService,
        public CustomerAnalyticsService $customerService,
        public BusinessInsightsEngine $insightsEngine,
        public AnalyticsExportService $exportService,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('view-analytics');

        /** @var User $viewer */
        $viewer = $request->user();

        $validated = $request->validate([
            'section' => ['nullable', 'string', Rule::in(AnalyticsAccess::ALL_SECTIONS)],
            'period' => ['nullable', 'string', Rule::in(AnalyticsPeriod::PRESETS)],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $requestedSection = $validated['section'] ?? null;
        if ($requestedSection !== null) {
            abort_unless($this->access->canViewSection($viewer, $requestedSection), 403);
            $activeSection = $requestedSection;
        } else {
            $activeSection = $this->access->defaultSection($viewer);
        }

        $period = AnalyticsPeriod::resolve(
            preset: $validated['period'] ?? 'monthly',
            startDate: $validated['start_date'] ?? null,
            endDate: $validated['end_date'] ?? null,
        );

        $page = (int) ($validated['page'] ?? 1);
        $capabilities = $this->access->toCapabilityArray($viewer);

        $sectionData = match ($activeSection) {
            'executive' => ['executive' => $this->executiveService->summary($period, $viewer)],
            'financial' => ['financial' => $this->financialService->report($period, $viewer, $page)],
            'services' => ['services' => $this->serviceSalesService->summary($period)],
            'workforce' => ['workforce' => $this->workforceService->summary($period, $viewer)],
            'inventory' => ['inventory' => $this->inventoryService->summary($period, $viewer)],
            'customers' => ['customers' => $this->customerService->summary($period, $viewer)],
            default => [],
        };

        $insights = $this->insightsEngine->generate($period, $viewer);
        $activityContext = $this->resolveActivityContext($viewer, $period);

        return Inertia::render('analytics/index', [
            'period' => $period->toArray(),
            'activeSection' => $activeSection,
            'capabilities' => $capabilities,
            'activityContext' => $activityContext,
            'kpiDefinitions' => config('analytics.kpi_definitions', []),
            'insights' => $insights,
            'executive' => $sectionData['executive'] ?? null,
            'financial' => $sectionData['financial'] ?? null,
            'services' => $sectionData['services'] ?? null,
            'workforce' => $sectionData['workforce'] ?? null,
            'inventory' => $sectionData['inventory'] ?? null,
            'customers' => $sectionData['customers'] ?? null,
        ]);
    }

    /**
     * Summarize persisted record boundaries so empty selected windows are transparent to operators.
     *
     * @return array{
     *     latest_order_date: string|null,
     *     latest_transaction_date: string|null,
     *     latest_movement_date: string|null,
     *     has_outside_period_data: bool
     * }
     */
    private function resolveActivityContext(User $viewer, AnalyticsPeriod $period): array
    {
        $scopedTechId = $this->access->scopedTechnicianId($viewer);
        $canViewOps = $this->access->canViewFinancialSummary($viewer);

        return $this->cacheManager->remember(
            namespace: 'activity_context',
            domains: ['orders', 'finance', 'inventory'],
            period: $period,
            extraKey: sprintf('%s:%s', $viewer->role, (string) ($scopedTechId ?? 'all')),
            resolver: function () use ($viewer, $period, $scopedTechId, $canViewOps): array {
                $timezone = $period->timezone;

                if ($viewer->role === 'warehouse') {
                    $latestMoveRaw = InventoryMovement::query()->max('occurred_at');
                    $inPeriodMoves = InventoryMovement::query()
                        ->whereBetween('occurred_at', [$period->start, $period->end])
                        ->count();

                    return [
                        'latest_order_date' => null,
                        'latest_transaction_date' => null,
                        'latest_movement_date' => $latestMoveRaw !== null
                            ? CarbonImmutable::parse((string) $latestMoveRaw, $timezone)->translatedFormat('d M Y')
                            : null,
                        'has_outside_period_data' => $inPeriodMoves === 0 && $latestMoveRaw !== null,
                    ];
                }

                if ($scopedTechId !== null) {
                    $latestOrderRaw = ServiceOrder::query()
                        ->where('assigned_technician_id', $scopedTechId)
                        ->max('created_at');
                    $inPeriodOrders = ServiceOrder::query()
                        ->where('assigned_technician_id', $scopedTechId)
                        ->whereBetween('created_at', [$period->start, $period->end])
                        ->count();

                    return [
                        'latest_order_date' => $latestOrderRaw !== null
                            ? CarbonImmutable::parse((string) $latestOrderRaw, $timezone)->translatedFormat('d M Y')
                            : null,
                        'latest_transaction_date' => null,
                        'latest_movement_date' => null,
                        'has_outside_period_data' => $inPeriodOrders === 0 && $latestOrderRaw !== null,
                    ];
                }

                if (! $canViewOps) {
                    return [
                        'latest_order_date' => null,
                        'latest_transaction_date' => null,
                        'latest_movement_date' => null,
                        'has_outside_period_data' => false,
                    ];
                }

                $latestOrderRaw = ServiceOrder::query()->max('created_at');
                $latestTxRaw = FinancialTransaction::query()->where('status', 'verified')->max('occurred_at');
                $latestMoveRaw = InventoryMovement::query()->max('occurred_at');

                $inPeriodOrders = ServiceOrder::query()
                    ->whereBetween('created_at', [$period->start, $period->end])
                    ->count();
                $inPeriodInvoices = Invoice::query()
                    ->whereNotIn('status', ['draft', 'cancelled'])
                    ->whereDate('issue_date', '>=', $period->start->toDateString())
                    ->whereDate('issue_date', '<=', $period->end->toDateString())
                    ->count();
                $inPeriodTx = FinancialTransaction::query()
                    ->where('status', 'verified')
                    ->whereBetween('occurred_at', [$period->start, $period->end])
                    ->count();

                $hasAnyInPeriod = ($inPeriodOrders + $inPeriodInvoices + $inPeriodTx) > 0;
                $hasAnyAllTime = $latestOrderRaw !== null || $latestTxRaw !== null || $latestMoveRaw !== null;

                return [
                    'latest_order_date' => $latestOrderRaw !== null
                        ? CarbonImmutable::parse((string) $latestOrderRaw, $timezone)->translatedFormat('d M Y')
                        : null,
                    'latest_transaction_date' => $latestTxRaw !== null
                        ? CarbonImmutable::parse((string) $latestTxRaw, $timezone)->translatedFormat('d M Y')
                        : null,
                    'latest_movement_date' => $latestMoveRaw !== null
                        ? CarbonImmutable::parse((string) $latestMoveRaw, $timezone)->translatedFormat('d M Y')
                        : null,
                    'has_outside_period_data' => ! $hasAnyInPeriod && $hasAnyAllTime,
                ];
            },
        );
    }

    public function export(Request $request): StreamedResponse
    {
        Gate::authorize('view-analytics');

        /** @var User $viewer */
        $viewer = $request->user();

        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in(AnalyticsAccess::ALL_EXPORT_TYPES)],
            'period' => ['nullable', 'string', Rule::in(AnalyticsPeriod::PRESETS)],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
        ]);

        $type = (string) $validated['type'];
        Gate::authorize('export-analytics', $type);

        $period = AnalyticsPeriod::resolve(
            preset: $validated['period'] ?? 'monthly',
            startDate: $validated['start_date'] ?? null,
            endDate: $validated['end_date'] ?? null,
        );

        return $this->exportService->downloadCsv($type, $period, $viewer);
    }
}
