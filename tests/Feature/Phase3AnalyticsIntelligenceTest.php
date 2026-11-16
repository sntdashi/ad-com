<?php

use App\Models\CustomerAsset;
use App\Models\Estimate;
use App\Models\FinancialTransaction;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\OrderAssignment;
use App\Models\OrderStatusEvent;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderSparePart;
use App\Models\TechnicianWorkSession;
use App\Models\User;
use App\Models\Warranty;
use App\Models\WarrantyClaim;
use App\Services\Analytics\AnalyticsCacheManager;
use App\Services\Analytics\AnalyticsExportService;
use App\Services\Analytics\AnalyticsPeriod;
use App\Services\Analytics\ExecutiveAnalyticsService;
use App\Services\Analytics\FinancialAnalyticsService;
use App\Services\Finance\FinancialReportService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    Queue::fake();
});

function createVerifiedTwoFactorUser(array $overrides = []): User
{
    return User::factory()->withTwoFactor()->create($overrides);
}

it('enforces the six-role analytics access matrix and blocks unauthorized routes and exports', function (): void {
    $operator = createVerifiedTwoFactorUser(['role' => 'operator']);
    $admin = createVerifiedTwoFactorUser(['role' => 'admin']);
    $supervisor = createVerifiedTwoFactorUser(['role' => 'supervisor']);
    $technician = createVerifiedTwoFactorUser(['role' => 'technician', 'name' => 'Teknisi Satu']);
    $otherTech = createVerifiedTwoFactorUser(['role' => 'technician', 'name' => 'Teknisi Dua']);
    $warehouse = createVerifiedTwoFactorUser(['role' => 'warehouse']);
    $customer = User::factory()->create(['role' => 'customer']);

    ServiceOrder::factory()->create(['assigned_technician_id' => $otherTech->id]);

    // Guest redirected
    $this->get(route('analytics.index'))->assertRedirect(route('login'));

    // Customer completely forbidden
    $this->actingAs($customer)->get(route('analytics.index'))->assertForbidden();
    $this->actingAs($customer)->get(route('analytics.export', ['type' => 'executive']))->assertForbidden();

    // Technician scoped strictly to personal workforce & insights
    $this->actingAs($technician)
        ->get(route('analytics.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('analytics/index')
            ->where('activeSection', 'workforce')
            ->where('capabilities.scoped_technician_id', $technician->id)
            ->where('workforce.is_personal_scope', true)
            ->has('workforce.technicians', 1)
            ->where('workforce.technicians.0.id', $technician->id)
            ->where('executive', null)
            ->where('financial', null)
        );

    $this->actingAs($technician)->get(route('analytics.index', ['section' => 'executive']))->assertForbidden();
    $this->actingAs($technician)->get(route('analytics.index', ['section' => 'financial']))->assertForbidden();
    $this->actingAs($technician)->get(route('analytics.index', ['section' => 'inventory']))->assertForbidden();
    $this->actingAs($technician)->get(route('analytics.export', ['type' => 'workforce']))->assertForbidden();

    // Warehouse scoped strictly to inventory + inventory insights without finance ledger
    $this->actingAs($warehouse)
        ->get(route('analytics.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('analytics/index')
            ->where('activeSection', 'inventory')
            ->where('inventory.can_view_finance_ledger', false)
            ->where('inventory.overview.verified_purchase_expenses_period', null)
        );

    $this->actingAs($warehouse)->get(route('analytics.index', ['section' => 'financial']))->assertForbidden();
    $this->actingAs($warehouse)->get(route('analytics.index', ['section' => 'executive']))->assertForbidden();
    $this->actingAs($warehouse)->get(route('analytics.export', ['type' => 'financial']))->assertForbidden();
    $this->actingAs($warehouse)->get(route('analytics.export', ['type' => 'inventory']))->assertOk();

    // Supervisor can view oversight financial/executive, but not raw expense ledger, customer PII, or inventory tab
    $this->actingAs($supervisor)
        ->get(route('analytics.index', ['section' => 'financial']))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('financial.can_view_raw_ledger', false)
            ->where('financial.expense_breakdown', [])
            ->where('financial.summary.operational_expenses', null)
        );
    $this->actingAs($supervisor)->get(route('analytics.index', ['section' => 'inventory']))->assertForbidden();
    $this->actingAs($supervisor)->get(route('analytics.export', ['type' => 'financial']))->assertForbidden();
    $this->actingAs($supervisor)->get(route('analytics.export', ['type' => 'customers']))->assertForbidden();
    $this->actingAs($supervisor)->get(route('analytics.export', ['type' => 'executive']))->assertOk();

    // Admin & Operator full hub + exports
    $this->actingAs($admin)->get(route('analytics.index', ['section' => 'executive']))->assertOk();
    $this->actingAs($operator)
        ->get(route('analytics.index', ['section' => 'financial']))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('financial.can_view_raw_ledger', true)
        );
    $this->actingAs($operator)->get(route('analytics.export', ['type' => 'financial']))->assertOk();
});

it('reconciles executive and financial BI metrics accurately with FinancialReportService and separates accrual vs cash', function (): void {
    $operator = createVerifiedTwoFactorUser(['role' => 'operator']);
    $customer = User::factory()->create(['role' => 'customer', 'name' => 'Budi Santoso', 'email' => 'budi@example.test']);
    $service = Service::factory()->create(['name' => 'Jaringan Korporat', 'code' => 'NET-01']);

    $today = CarbonImmutable::now('Asia/Jakarta');

    $order = ServiceOrder::factory()->create([
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'customer_name' => $customer->name,
        'email' => $customer->email,
        'status' => 'completed',
        'estimated_total' => 1800000,
        'created_at' => $today->subDays(2),
        'completed_at' => $today->subDay(),
    ]);

    $item = InventoryItem::factory()->create([
        'sku' => 'RTR-MKT-01',
        'name' => 'Router MikroTik RB4011',
        'unit_cost' => 400000,
        'quantity_on_hand' => 5,
    ]);

    ServiceOrderSparePart::query()->create([
        'service_order_id' => $order->id,
        'inventory_item_id' => $item->id,
        'requested_by' => $operator->id,
        'issued_by' => $operator->id,
        'requested_quantity' => 2,
        'issued_quantity' => 2,
        'returned_quantity' => 1,
        'unit_cost' => 400000,
        'status' => 'partially_returned',
        'requested_at' => $today->subDays(2),
        'issued_at' => $today->subDays(2),
    ]);

    $paidInvoice = Invoice::query()->create([
        'invoice_number' => 'INV-PH3-001',
        'service_order_id' => $order->id,
        'customer_id' => $customer->id,
        'customer_name' => $customer->name,
        'customer_email' => $customer->email,
        'created_by' => $operator->id,
        'issued_by' => $operator->id,
        'status' => 'partially_paid',
        'issue_date' => $today->subDay()->toDateString(),
        'due_date' => $today->subDays(15)->toDateString(),
        'subtotal' => 2000000,
        'discount_amount' => 0,
        'tax_rate' => '0.00',
        'tax_amount' => 0,
        'total_amount' => 2000000,
        'paid_amount' => 1250000,
        'issued_at' => $today->subDay(),
    ]);

    FinancialTransaction::query()->create([
        'service_order_id' => $order->id,
        'invoice_id' => $paidInvoice->id,
        'actor_id' => $operator->id,
        'verified_by' => $operator->id,
        'type' => 'income',
        'category' => 'service_payment',
        'amount' => 1250000,
        'payment_method' => 'transfer',
        'status' => 'verified',
        'occurred_at' => $today->subDay(),
        'verified_at' => $today->subDay(),
    ]);

    FinancialTransaction::query()->create([
        'service_order_id' => $order->id,
        'actor_id' => $operator->id,
        'verified_by' => $operator->id,
        'type' => 'expense',
        'category' => 'transport',
        'amount' => 150000,
        'payment_method' => 'cash',
        'status' => 'verified',
        'occurred_at' => $today->subDay(),
        'verified_at' => $today->subDay(),
    ]);

    $period = AnalyticsPeriod::resolve('monthly', null, null, $today);
    $coreSummary = app(FinancialReportService::class)->summary(
        $period->start->toDateString(),
        $period->end->toDateString(),
    );

    $execSummary = app(ExecutiveAnalyticsService::class)->summary($period, $operator);
    $finReport = app(FinancialAnalyticsService::class)->report($period, $operator);

    expect($execSummary['kpis']['invoiced_revenue']['value'])->toBe(2000000)
        ->and($execSummary['kpis']['cash_received']['value'])->toBe(1250000)
        ->and($execSummary['kpis']['outstanding_receivables']['value'])->toBe(750000)
        ->and($execSummary['kpis']['spare_part_cogs']['value'])->toBe(400000)
        ->and($execSummary['kpis']['operational_expenses']['value'])->toBe(150000)
        ->and($execSummary['kpis']['gross_profit_accrual']['value'])->toBe(1450000)
        ->and($finReport['summary']['invoiced_revenue'])->toBe($coreSummary['invoiced_revenue'])
        ->and($finReport['summary']['cash_received'])->toBe($coreSummary['cash_received'])
        ->and($finReport['summary']['gross_profit_accrual'])->toBe($coreSummary['gross_profit_accrual'])
        ->and($finReport['summary']['net_cash_flow'])->toBe($coreSummary['net_cash_flow']);

    // Due date 15 days ago must fall into 1_30 bucket with balance 750000
    $aging = collect($finReport['receivables_aging'])->keyBy('bucket');
    expect($aging->get('1_30')['amount'])->toBe(750000)
        ->and($aging->get('1_30')['count'])->toBe(1);
});

it('ranks services by true profitability rather than gross revenue and tracks quotation conversion', function (): void {
    $operator = createVerifiedTwoFactorUser(['role' => 'operator']);
    $highRevenueLowProfit = Service::factory()->create(['name' => 'Ganti Board Laptop', 'code' => 'SRV-BOARD']);
    $moderateRevenueHighProfit = Service::factory()->create(['name' => 'Optimasi Jaringan', 'code' => 'SRV-OPT']);

    $today = CarbonImmutable::now('Asia/Jakarta');

    $orderA = ServiceOrder::factory()->create(['service_id' => $highRevenueLowProfit->id, 'status' => 'completed', 'created_at' => $today]);
    $orderB = ServiceOrder::factory()->create(['service_id' => $moderateRevenueHighProfit->id, 'status' => 'completed', 'created_at' => $today]);

    $expensivePart = InventoryItem::factory()->create(['unit_cost' => 4500000, 'quantity_on_hand' => 3]);
    ServiceOrderSparePart::query()->create([
        'service_order_id' => $orderA->id,
        'inventory_item_id' => $expensivePart->id,
        'requested_by' => $operator->id,
        'issued_by' => $operator->id,
        'requested_quantity' => 1,
        'issued_quantity' => 1,
        'returned_quantity' => 0,
        'unit_cost' => 4500000,
        'status' => 'issued',
        'requested_at' => $today,
        'issued_at' => $today,
    ]);

    Invoice::query()->create([
        'invoice_number' => 'INV-SRV-A',
        'service_order_id' => $orderA->id,
        'customer_name' => 'Klien A',
        'created_by' => $operator->id,
        'issued_by' => $operator->id,
        'status' => 'issued',
        'issue_date' => $today->toDateString(),
        'subtotal' => 5000000,
        'total_amount' => 5000000,
    ]);

    Invoice::query()->create([
        'invoice_number' => 'INV-SRV-B',
        'service_order_id' => $orderB->id,
        'customer_name' => 'Klien B',
        'created_by' => $operator->id,
        'issued_by' => $operator->id,
        'status' => 'paid',
        'issue_date' => $today->toDateString(),
        'subtotal' => 2000000,
        'total_amount' => 2000000,
        'paid_amount' => 2000000,
    ]);

    Estimate::query()->create([
        'quotation_number' => 'QT-PH3-1',
        'service_order_id' => $orderB->id,
        'created_by' => $operator->id,
        'version' => 1,
        'status' => 'converted',
        'labor_total' => 2000000,
        'subtotal' => 2000000,
        'total' => 2000000,
    ]);

    Estimate::query()->create([
        'quotation_number' => 'QT-PH3-2',
        'service_order_id' => $orderA->id,
        'created_by' => $operator->id,
        'version' => 1,
        'status' => 'rejected',
        'labor_total' => 5000000,
        'subtotal' => 5000000,
        'total' => 5000000,
    ]);

    $this->actingAs($operator)
        ->get(route('analytics.index', ['section' => 'services', 'period' => 'monthly']))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('services.most_profitable.0.code', 'SRV-OPT')
            ->where('services.most_profitable.0.gross_profit', 2000000)
            ->where('services.lowest_margin.0.code', 'SRV-BOARD')
            ->where('services.lowest_margin.0.gross_profit', 500000)
            ->where('services.overview.quotation_approval_rate', fn ($v): bool => abs((float) $v - 50.0) < 0.01)
            ->where('services.overview.quotation_conversion_rate', fn ($v): bool => abs((float) $v - 100.0) < 0.01)
        );
});

it('tracks workforce metrics with complexity context, inventory velocity/dead-stock, customer retention without leaking PII, and deterministic insights', function (): void {
    $operator = createVerifiedTwoFactorUser(['role' => 'operator']);
    $supervisor = createVerifiedTwoFactorUser(['role' => 'supervisor']);
    $tech = createVerifiedTwoFactorUser(['role' => 'technician', 'name' => 'Andi Spesialis', 'specialty' => 'MikroTik']);
    $service = Service::factory()->create(['name' => 'Pemulihan Server', 'code' => 'SRV-REC']);

    $now = CarbonImmutable::now('Asia/Jakarta');

    // Prior order to make customer returning
    $customer = User::factory()->create(['role' => 'customer', 'name' => 'PT Maju Terus', 'email' => 'secret@maju.test']);
    ServiceOrder::factory()->create([
        'customer_id' => $customer->id,
        'customer_name' => $customer->name,
        'email' => $customer->email,
        'service_id' => $service->id,
        'status' => 'completed',
        'created_at' => $now->subDays(45),
        'completed_at' => $now->subDays(44),
    ]);

    // Current high-risk breached order creating negative margin
    $currentOrder = ServiceOrder::factory()->create([
        'customer_id' => $customer->id,
        'customer_name' => $customer->name,
        'email' => $customer->email,
        'service_id' => $service->id,
        'assigned_technician_id' => $tech->id,
        'status' => 'in_progress',
        'risk_level' => 'high',
        'urgency' => 'critical',
        'created_at' => $now->subDays(2),
        'resolution_due_at' => $now->subHour(),
    ]);

    OrderAssignment::query()->create([
        'service_order_id' => $currentOrder->id,
        'user_id' => $tech->id,
        'assignment_role' => 'technician',
        'assigned_at' => $now->subDays(2),
    ]);

    OrderStatusEvent::query()->create([
        'service_order_id' => $currentOrder->id,
        'actor_id' => $operator->id,
        'status' => 'reopened',
        'title' => 'Order Dibuka Ulang',
        'occurred_at' => $now->subDay(),
        'created_at' => $now->subDay(),
    ]);

    TechnicianWorkSession::query()->create([
        'service_order_id' => $currentOrder->id,
        'technician_id' => $tech->id,
        'status' => 'completed',
        'started_at' => $now->subHours(3),
        'completed_at' => $now->subHour(),
        'duration_seconds' => 7200,
    ]);

    // Inventory: fast-moving item, dead-stock item (> 60 days passive), out-of-stock item
    $fastItem = InventoryItem::factory()->create(['sku' => 'FAST-01', 'name' => 'Kabel UTP Cat6', 'quantity_on_hand' => 2, 'reorder_level' => 5, 'unit_cost' => 50000]);
    InventoryMovement::query()->create([
        'inventory_item_id' => $fastItem->id,
        'type' => 'spare_part_issue',
        'quantity' => 8,
        'balance_before' => 10,
        'balance_after' => 2,
        'occurred_at' => $now->subDay(),
    ]);

    $deadItem = InventoryItem::factory()->create([
        'sku' => 'DEAD-01',
        'name' => 'Switch Lama',
        'quantity_on_hand' => 4,
        'reorder_level' => 1,
        'unit_cost' => 300000,
        'created_at' => $now->subDays(90),
    ]);
    InventoryMovement::query()->create([
        'inventory_item_id' => $deadItem->id,
        'type' => 'stock_out',
        'quantity' => 1,
        'balance_before' => 5,
        'balance_after' => 4,
        'occurred_at' => $now->subDays(75),
    ]);

    InventoryItem::factory()->create(['sku' => 'OOS-01', 'name' => 'Adaptor 12V', 'quantity_on_hand' => 0, 'reorder_level' => 3, 'unit_cost' => 75000]);

    // Asset, Warranty & Claim
    $asset = CustomerAsset::query()->create([
        'customer_id' => $customer->id,
        'name' => 'Server Utama',
        'category' => 'Server',
        'status' => 'active',
    ]);
    $warranty = Warranty::query()->create([
        'warranty_number' => 'WR-PH3-01',
        'customer_id' => $customer->id,
        'customer_asset_id' => $asset->id,
        'service_order_id' => $currentOrder->id,
        'scope_type' => 'service',
        'item_label' => 'Pemulihan Server',
        'duration_days' => 30,
        'status' => 'active',
        'starts_at' => $now->subDays(2),
        'expires_at' => $now->addDays(28),
        'created_at' => $now->subDays(2),
    ]);
    WarrantyClaim::query()->create([
        'claim_number' => 'CLM-PH3-01',
        'warranty_id' => $warranty->id,
        'customer_id' => $customer->id,
        'customer_asset_id' => $asset->id,
        'original_service_order_id' => $currentOrder->id,
        'status' => 'submitted',
        'issue_description' => 'Kendala kembali muncul.',
        'submitted_at' => $now->subHours(6),
    ]);

    // Workforce verification
    $this->actingAs($operator)
        ->get(route('analytics.index', ['section' => 'workforce']))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('workforce.technicians.0.assigned_jobs_period', 1)
            ->where('workforce.technicians.0.high_complexity_jobs_period', 1)
            ->where('workforce.technicians.0.total_work_hours_period', 2)
            ->where('workforce.technicians.0.reopened_jobs_period', 1)
        );

    // Inventory verification & unchanged ledger assertion
    $beforeStock = InventoryItem::query()->findOrFail($fastItem->id)->quantity_on_hand;
    $this->actingAs($operator)
        ->get(route('analytics.index', ['section' => 'inventory']))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('inventory.fast_moving.0.sku', 'FAST-01')
            ->where('inventory.dead_stock_candidates.0.sku', 'DEAD-01')
            ->where('inventory.reorder_indicators.0.sku', 'OOS-01')
        );
    expect(InventoryItem::query()->findOrFail($fastItem->id)->quantity_on_hand)->toBe($beforeStock);

    // Customer PII hidden from supervisor even with active invoice
    Invoice::query()->create([
        'invoice_number' => 'INV-CUST-PRIV',
        'service_order_id' => $currentOrder->id,
        'customer_id' => $customer->id,
        'customer_name' => $customer->name,
        'customer_email' => $customer->email,
        'created_by' => $operator->id,
        'issued_by' => $operator->id,
        'status' => 'issued',
        'issue_date' => $now->toDateString(),
        'subtotal' => 900000,
        'total_amount' => 900000,
    ]);

    $this->actingAs($supervisor)
        ->get(route('analytics.index', ['section' => 'customers']))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('customers.can_view_pii', false)
            ->where('customers.overview.returning_customers_period', 1)
            ->where('customers.top_lifetime_segments.0.customer_label', "Pelanggan #{$customer->id}")
        );

    // Deterministic insights generated
    $this->actingAs($operator)
        ->get(route('analytics.index', ['section' => 'insights']))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('insights', fn ($list): bool => collect($list)->pluck('id')->contains('inventory-reorder-attention')
                && collect($list)->pluck('id')->contains('inventory-dead-stock'))
        );
});

it('handles empty datasets safely without division by zero, sanitizes CSV formula injection, and invalidates cached aggregates on mutations', function (): void {
    $operator = createVerifiedTwoFactorUser(['role' => 'operator']);

    // Empty future custom range must return 200 without exceptions
    $this->actingAs($operator)
        ->get(route('analytics.index', [
            'section' => 'executive',
            'period' => 'custom',
            'start_date' => '2035-01-01',
            'end_date' => '2035-01-31',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('executive.kpis.total_orders.value', 0)
            ->where('executive.kpis.completion_rate.value', null)
            ->where('executive.kpis.sla_compliance_rate.value', null)
        );

    // CSV formula neutralization
    $exportService = app(AnalyticsExportService::class);
    expect($exportService->sanitizeCell('=SUM(A1:A2)'))->toBe("'=SUM(A1:A2)")
        ->and($exportService->sanitizeCell('+cmd|calc'))->toBe("'+cmd|calc")
        ->and($exportService->sanitizeCell('-1+1'))->toBe("'-1+1")
        ->and($exportService->sanitizeCell('@import'))->toBe("'@import");

    // Observer invalidation test + query count sanity
    $service = Service::factory()->create();
    $period = AnalyticsPeriod::resolve('monthly');
    $cache = app(AnalyticsCacheManager::class);
    $execService = app(ExecutiveAnalyticsService::class);

    $beforeOrders = $execService->summary($period, $operator)['kpis']['total_orders']['value'];
    $versionBefore = $cache->versionFor('orders');

    ServiceOrder::factory()->create([
        'service_id' => $service->id,
        'status' => 'new',
    ]);

    expect($cache->versionFor('orders'))->toBeGreaterThan($versionBefore);
    $afterOrders = $execService->summary($period, $operator)['kpis']['total_orders']['value'];
    expect($afterOrders)->toBe($beforeOrders + 1);

    // Query budget check: cold load across all BI domains + warm cache hit efficiency
    $cache->invalidateAll();
    DB::enableQueryLog();
    $this->actingAs($operator)->get(route('analytics.index', ['section' => 'executive']))->assertOk();
    $coldQueryCount = count(DB::getQueryLog());
    DB::flushQueryLog();
    $this->actingAs($operator)->get(route('analytics.index', ['section' => 'executive']))->assertOk();
    $warmQueryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($coldQueryCount)->toBeLessThan(130)
        ->and($warmQueryCount)->toBeLessThan(15);
});

it('respects Asia/Jakarta period boundaries and streams UTF-8 BOM sanitized CSV downloads', function (): void {
    $operator = createVerifiedTwoFactorUser(['role' => 'operator']);
    $service = Service::factory()->create(['name' => '=HYPERLINK("http://evil.test")', 'code' => 'SRV-INJ']);

    $lateWib = CarbonImmutable::parse('2026-11-15 23:30:00', 'Asia/Jakarta');
    $nextMorningWib = CarbonImmutable::parse('2026-11-16 09:00:00', 'Asia/Jakarta');

    ServiceOrder::factory()->create([
        'service_id' => $service->id,
        'status' => 'completed',
        'created_at' => $lateWib,
        'completed_at' => $lateWib,
    ]);
    ServiceOrder::factory()->create([
        'service_id' => $service->id,
        'status' => 'new',
        'created_at' => $nextMorningWib,
    ]);

    $periodNov15 = AnalyticsPeriod::resolve('custom', '2026-11-15', '2026-11-15', $nextMorningWib);
    $execNov15 = app(ExecutiveAnalyticsService::class)->summary($periodNov15, $operator);
    expect($execNov15['kpis']['total_orders']['value'])->toBe(1)
        ->and($execNov15['kpis']['completed_orders']['value'])->toBe(1);

    $csvResponse = $this->actingAs($operator)->get(route('analytics.export', [
        'type' => 'services',
        'period' => 'custom',
        'start_date' => '2026-11-15',
        'end_date' => '2026-11-16',
    ]));

    $csvResponse->assertOk();
    $content = $csvResponse->streamedContent();
    expect(str_starts_with($content, "\xEF\xBB\xBF"))->toBeTrue()
        ->and($content)->toContain("'=HYPERLINK");
});

it('reports outside-period activity context accurately without leaking global dates to warehouse or technician roles', function (): void {
    $operator = createVerifiedTwoFactorUser(['role' => 'operator']);
    $warehouse = createVerifiedTwoFactorUser(['role' => 'warehouse']);
    $technician = createVerifiedTwoFactorUser(['role' => 'technician']);
    $service = Service::factory()->create();

    $olderDate = CarbonImmutable::now('Asia/Jakarta')->subDays(70);
    ServiceOrder::factory()->create([
        'service_id' => $service->id,
        'status' => 'completed',
        'created_at' => $olderDate,
        'completed_at' => $olderDate,
    ]);
    FinancialTransaction::query()->create([
        'actor_id' => $operator->id,
        'verified_by' => $operator->id,
        'type' => 'income',
        'category' => 'service_payment',
        'amount' => 500000,
        'payment_method' => 'transfer',
        'status' => 'verified',
        'occurred_at' => $olderDate->addDay(),
        'verified_at' => $olderDate->addDay(),
    ]);

    // Monthly (30 days) genuinely contains 0 orders while data exists 70 days ago
    $this->actingAs($operator)
        ->get(route('analytics.index', ['section' => 'executive', 'period' => 'monthly']))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('executive.kpis.total_orders.value', 0)
            ->where('activityContext.has_outside_period_data', true)
            ->where('activityContext.latest_order_date', $olderDate->translatedFormat('d M Y'))
            ->where('activityContext.latest_transaction_date', $olderDate->addDay()->translatedFormat('d M Y'))
        );

    // Quarterly (90 days) includes the 70-day-old records
    $this->actingAs($operator)
        ->get(route('analytics.index', ['section' => 'executive', 'period' => 'quarterly']))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('executive.kpis.total_orders.value', 1)
            ->where('executive.kpis.cash_received.value', 500000)
            ->where('activityContext.has_outside_period_data', false)
        );

    // Warehouse & Technician must never receive global order or finance dates
    $this->actingAs($warehouse)
        ->get(route('analytics.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('activityContext.latest_order_date', null)
            ->where('activityContext.latest_transaction_date', null)
        );

    $this->actingAs($technician)
        ->get(route('analytics.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('activityContext.latest_order_date', null)
            ->where('activityContext.latest_transaction_date', null)
            ->where('activityContext.latest_movement_date', null)
        );
});
