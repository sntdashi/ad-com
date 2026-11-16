<?php

use App\Models\FinancialTransaction;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderSparePart;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    Queue::fake();
});

it('creates and updates inventory master data, prevents duplicate SKUs, and records initial stock movements', function (): void {
    $warehouse = User::factory()->create(['role' => 'warehouse']);
    $technician = User::factory()->create(['role' => 'technician']);

    // Technician cannot create inventory master data
    $this->actingAs($technician)
        ->post(route('inventory.store'), [
            'sku' => 'NET-001',
            'name' => 'Mikrotik RB750Gr3',
            'category' => 'Jaringan',
            'unit' => 'unit',
            'quantity_on_hand' => 5,
            'reorder_level' => 2,
            'unit_cost' => 850000,
        ])
        ->assertForbidden();

    // Warehouse creates item with lowercase sku -> normalized uppercase + opening movement
    $this->actingAs($warehouse)
        ->post(route('inventory.store'), [
            'sku' => 'net-rb-750',
            'name' => 'Mikrotik hEX RB750Gr3',
            'category' => 'Jaringan',
            'description' => 'Routerboard 5 port gigabit',
            'unit' => 'unit',
            'quantity_on_hand' => 4,
            'reorder_level' => 2,
            'unit_cost' => 850000,
            'selling_price' => 980000,
            'storage_location' => 'Rak A2',
            'supplier_name' => 'PT Citraweb',
            'supplier_contact' => '0274-555111',
        ])
        ->assertRedirect();

    $item = InventoryItem::query()->where('sku', 'NET-RB-750')->firstOrFail();
    expect($item->quantity_on_hand)->toBe(4)
        ->and($item->selling_price)->toBe(980000)
        ->and($item->supplier_name)->toBe('PT Citraweb');

    $openingMove = InventoryMovement::query()->where('inventory_item_id', $item->id)->firstOrFail();
    expect($openingMove->type)->toBe('stock_in')
        ->and($openingMove->balance_before)->toBe(0)
        ->and($openingMove->balance_after)->toBe(4);

    // Duplicate SKU prevented (case-insensitive)
    $this->actingAs($warehouse)
        ->post(route('inventory.store'), [
            'sku' => 'NET-RB-750',
            'name' => 'Duplicate Router',
            'category' => 'Jaringan',
            'unit' => 'unit',
            'quantity_on_hand' => 1,
            'reorder_level' => 1,
            'unit_cost' => 100000,
        ])
        ->assertSessionHasErrors('sku');

    // Master update does not mutate quantity_on_hand directly
    $this->actingAs($warehouse)
        ->patch(route('inventory.update', $item), [
            'sku' => 'NET-RB-750',
            'name' => 'Mikrotik hEX Gigabit',
            'category' => 'Jaringan',
            'unit' => 'unit',
            'reorder_level' => 3,
            'unit_cost' => 850000,
            'selling_price' => 995000,
            'storage_location' => 'Rak A3',
            'is_active' => true,
            'quantity_on_hand' => 999,
        ])
        ->assertRedirect();

    expect($item->fresh()->name)->toBe('Mikrotik hEX Gigabit')
        ->and($item->fresh()->quantity_on_hand)->toBe(4);
});

it('handles stock-in weighted average cost, stock-out, stock adjustments with required reason, and negative stock prevention', function (): void {
    $warehouse = User::factory()->create(['role' => 'warehouse']);

    $item = InventoryItem::factory()->create([
        'sku' => 'SSD-512-WAC',
        'quantity_on_hand' => 4,
        'reorder_level' => 2,
        'unit_cost' => 500000,
    ]);

    // Stock in 4 units at 700,000 -> WAC = ((4*500k) + (4*700k)) / 8 = 600,000
    $this->actingAs($warehouse)
        ->post(route('inventory.movements.store'), [
            'inventory_item_id' => $item->id,
            'type' => 'stock_in',
            'quantity' => 4,
            'unit_cost' => 700000,
            'supplier_name' => 'Distribusi Nusantara',
            'reference' => 'PO-WAC-01',
        ])
        ->assertRedirect();

    expect($item->fresh()->quantity_on_hand)->toBe(8)
        ->and($item->fresh()->unit_cost)->toBe(600000);

    // Stock out exceeding balance is blocked
    $this->actingAs($warehouse)
        ->post(route('inventory.movements.store'), [
            'inventory_item_id' => $item->id,
            'type' => 'stock_out',
            'quantity' => 9,
        ])
        ->assertSessionHasErrors('quantity');

    expect($item->fresh()->quantity_on_hand)->toBe(8);

    // Stock adjustment requires adjustment_reason
    $this->actingAs($warehouse)
        ->post(route('inventory.movements.store'), [
            'inventory_item_id' => $item->id,
            'type' => 'adjustment',
            'new_quantity' => 6,
            'adjustment_reason' => '',
        ])
        ->assertSessionHasErrors('adjustment_reason');

    // Valid stock adjustment records adjustment_out with balance_before and balance_after
    $this->actingAs($warehouse)
        ->post(route('inventory.movements.store'), [
            'inventory_item_id' => $item->id,
            'type' => 'adjustment',
            'new_quantity' => 6,
            'adjustment_reason' => 'Stock opname: 2 unit fisik rusak',
        ])
        ->assertRedirect();

    expect($item->fresh()->quantity_on_hand)->toBe(6);

    $adjMove = InventoryMovement::query()->latest('id')->firstOrFail();
    expect($adjMove->type)->toBe('adjustment_out')
        ->and($adjMove->quantity)->toBe(2)
        ->and($adjMove->balance_before)->toBe(8)
        ->and($adjMove->balance_after)->toBe(6)
        ->and($adjMove->adjustment_reason)->toBe('Stock opname: 2 unit fisik rusak');
});

it('deduplicates low-stock alerts across consecutive deductions and resolves alerts upon replenishment', function (): void {
    $warehouse = User::factory()->create(['role' => 'warehouse']);
    $operator = User::factory()->create(['role' => 'operator']);
    $admin = User::factory()->create(['role' => 'admin']);

    $item = InventoryItem::factory()->create([
        'sku' => 'UTP-DEDUP-01',
        'quantity_on_hand' => 6,
        'reorder_level' => 3,
    ]);

    // First drop (6 -> 3) triggers low-stock alert once per recipient
    $this->actingAs($warehouse)
        ->post(route('inventory.movements.store'), [
            'inventory_item_id' => $item->id,
            'type' => 'stock_out',
            'quantity' => 3,
        ])
        ->assertRedirect();

    expect($item->fresh()->low_stock_alerted_at)->not->toBeNull()
        ->and(DB::table('notifications')->where('data', 'like', '%"inventory.low_stock"%')->count())->toBe(3);

    // Second drop (3 -> 0) stays in low-stock state -> NO duplicate alert sent
    $this->actingAs($warehouse)
        ->post(route('inventory.movements.store'), [
            'inventory_item_id' => $item->id,
            'type' => 'stock_out',
            'quantity' => 3,
        ])
        ->assertRedirect();

    expect($item->fresh()->quantity_on_hand)->toBe(0)
        ->and(DB::table('notifications')->where('data', 'like', '%"inventory.low_stock"%')->count())->toBe(3);

    // Replenish above threshold (0 -> 8) clears low_stock_alerted_at
    $this->actingAs($warehouse)
        ->post(route('inventory.movements.store'), [
            'inventory_item_id' => $item->id,
            'type' => 'stock_in',
            'quantity' => 8,
        ])
        ->assertRedirect();

    expect($item->fresh()->low_stock_alerted_at)->toBeNull();

    // Dropping below threshold again (8 -> 2) triggers alert again cleanly
    $this->actingAs($warehouse)
        ->post(route('inventory.movements.store'), [
            'inventory_item_id' => $item->id,
            'type' => 'stock_out',
            'quantity' => 6,
        ])
        ->assertRedirect();

    expect($item->fresh()->low_stock_alerted_at)->not->toBeNull()
        ->and(DB::table('notifications')->where('data', 'like', '%"inventory.low_stock"%')->count())->toBe(6);
});

it('executes full service-order spare-part request, warehouse issue, duplicate/insufficient block, cancellation, and return lifecycle', function (): void {
    $warehouse = User::factory()->create(['role' => 'warehouse']);
    $assignedTech = User::factory()->create(['role' => 'technician']);
    $otherTech = User::factory()->create(['role' => 'technician']);
    $customer = User::factory()->create(['role' => 'customer']);

    $service = Service::factory()->create();
    $order = ServiceOrder::factory()->create([
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'assigned_technician_id' => $assignedTech->id,
        'status' => 'in_progress',
    ]);

    $ram = InventoryItem::factory()->create([
        'sku' => 'RAM-DDR4-8G',
        'quantity_on_hand' => 5,
        'reorder_level' => 1,
        'unit_cost' => 250000,
        'selling_price' => 350000,
    ]);

    // Unassigned technician & customer cannot request spare parts
    $this->actingAs($otherTech)
        ->post(route('orders.spare-parts.store', $order), [
            'inventory_item_id' => $ram->id,
            'requested_quantity' => 1,
        ])
        ->assertForbidden();

    $this->actingAs($customer)
        ->post(route('orders.spare-parts.store', $order), [
            'inventory_item_id' => $ram->id,
            'requested_quantity' => 1,
        ])
        ->assertForbidden();

    // Assigned technician requests 3 units -> DOES NOT deduct stock yet!
    $this->actingAs($assignedTech)
        ->post(route('orders.spare-parts.store', $order), [
            'inventory_item_id' => $ram->id,
            'requested_quantity' => 3,
            'request_notes' => 'Upgrade RAM workstation lab',
        ])
        ->assertRedirect();

    expect($ram->fresh()->quantity_on_hand)->toBe(5);
    $requestRow = ServiceOrderSparePart::query()->where('service_order_id', $order->id)->firstOrFail();
    expect($requestRow->status)->toBe('requested')
        ->and($requestRow->issued_quantity)->toBe(0);

    // Technician cannot approve/issue their own request
    $this->actingAs($assignedTech)
        ->patch(route('orders.spare-parts.issue', [$order, $requestRow]), [
            'issued_quantity' => 3,
        ])
        ->assertForbidden();

    // Warehouse approves and issues 3 units -> atomic stock deduction + movement
    $this->actingAs($warehouse)
        ->patch(route('orders.spare-parts.issue', [$order, $requestRow]), [
            'issued_quantity' => 3,
            'issue_notes' => 'Diambil di loket gudang',
        ])
        ->assertRedirect();

    expect($ram->fresh()->quantity_on_hand)->toBe(2)
        ->and($requestRow->fresh()->status)->toBe('issued')
        ->and($requestRow->fresh()->issued_quantity)->toBe(3);

    $issueMove = InventoryMovement::query()
        ->where('spare_part_request_id', $requestRow->id)
        ->where('type', 'spare_part_issue')
        ->firstOrFail();
    expect($issueMove->balance_before)->toBe(5)
        ->and($issueMove->balance_after)->toBe(2)
        ->and($issueMove->service_order_id)->toBe($order->id);

    // Duplicate issuance is blocked (authorized policy rejects already-issued row with 403/validation)
    $this->actingAs($warehouse)
        ->patch(route('orders.spare-parts.issue', [$order, $requestRow]), [
            'issued_quantity' => 1,
        ])
        ->assertForbidden();
    expect($ram->fresh()->quantity_on_hand)->toBe(2);

    // Partial return of 1 unit restores stock and updates status to partially_returned
    $this->actingAs($warehouse)
        ->patch(route('orders.spare-parts.return', [$order, $requestRow]), [
            'return_quantity' => 1,
            'return_notes' => '1 slot RAM bermasalah, hanya terpakai 2',
        ])
        ->assertRedirect();

    expect($ram->fresh()->quantity_on_hand)->toBe(3)
        ->and($requestRow->fresh()->returned_quantity)->toBe(1)
        ->and($requestRow->fresh()->netIssuedQuantity())->toBe(2)
        ->and($requestRow->fresh()->status)->toBe('partially_returned');

    // Over-return (> net issued 2) fails validation
    $this->actingAs($warehouse)
        ->patch(route('orders.spare-parts.return', [$order, $requestRow]), [
            'return_quantity' => 5,
        ])
        ->assertSessionHasErrors('return_quantity');

    // Customer sees chargeable spare-part value on ticket, NOT internal unit_cost or inventoryCatalog
    $this->actingAs($customer)
        ->get(route('orders.show', $order))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('order.spare_parts.0.net_issued_quantity', 2)
            ->where('order.spare_parts.0.unit_cost', null)
            ->where('order.spare_parts.0.line_total', 700000)
            ->where('order.spare_parts_summary.chargeable_total', 700000)
            ->where('order.spare_parts_summary.internal_cost_total', null)
        );

    // Request + cancellation never deducts stock
    $this->actingAs($assignedTech)
        ->post(route('orders.spare-parts.store', $order), [
            'inventory_item_id' => $ram->id,
            'requested_quantity' => 2,
        ])
        ->assertRedirect();

    $secondReq = ServiceOrderSparePart::query()->latest('id')->firstOrFail();
    $this->actingAs($assignedTech)
        ->patch(route('orders.spare-parts.cancel', [$order, $secondReq]), [
            'cancellation_reason' => 'Tidak jadi dibutuhkan',
        ])
        ->assertRedirect();

    expect($secondReq->fresh()->status)->toBe('cancelled')
        ->and($ram->fresh()->quantity_on_hand)->toBe(3);

    // Request exceeding available stock fails cleanly at warehouse issue time without negative stock
    $this->actingAs($assignedTech)
        ->post(route('orders.spare-parts.store', $order), [
            'inventory_item_id' => $ram->id,
            'requested_quantity' => 10,
        ])
        ->assertRedirect();

    $overReq = ServiceOrderSparePart::query()->latest('id')->firstOrFail();
    $this->actingAs($warehouse)
        ->patch(route('orders.spare-parts.issue', [$order, $overReq]), [
            'issued_quantity' => 10,
        ])
        ->assertSessionHasErrors('issued_quantity');

    expect($ram->fresh()->quantity_on_hand)->toBe(3);
});

it('integrates stock purchases with maker-checker finance and protects warehouse boundaries', function (): void {
    $warehouse = User::factory()->create(['role' => 'warehouse']);
    $admin = User::factory()->create(['role' => 'admin']);
    $operator = User::factory()->create(['role' => 'operator']);

    $item = InventoryItem::factory()->create([
        'sku' => 'FIN-CABLE-01',
        'quantity_on_hand' => 2,
        'unit_cost' => 100000,
    ]);

    // Warehouse cannot trigger finance transaction creation or access finance route
    $this->actingAs($warehouse)
        ->post(route('inventory.movements.store'), [
            'inventory_item_id' => $item->id,
            'type' => 'stock_in',
            'quantity' => 3,
            'unit_cost' => 120000,
            'record_finance_expense' => true,
        ])
        ->assertForbidden();

    expect(FinancialTransaction::count())->toBe(0);

    // Admin records stock-in WITH finance expense -> creates pending inventory_purchase transaction
    $this->actingAs($admin)
        ->post(route('inventory.movements.store'), [
            'inventory_item_id' => $item->id,
            'type' => 'stock_in',
            'quantity' => 5,
            'unit_cost' => 120000,
            'payment_method' => 'bank_transfer',
            'record_finance_expense' => true,
        ])
        ->assertRedirect();

    $tx = FinancialTransaction::query()->latest('id')->firstOrFail();
    expect($tx->type)->toBe('expense')
        ->and($tx->category)->toBe('inventory_purchase')
        ->and($tx->amount)->toBe(600000)
        ->and($tx->status)->toBe('pending');

    // Admin cannot self-verify pending finance transaction; Operator verifies it
    $this->actingAs($admin)
        ->patch(route('finance.transactions.verify', $tx))
        ->assertForbidden();

    $this->actingAs($operator)
        ->patch(route('finance.transactions.verify', $tx))
        ->assertRedirect();

    expect($tx->fresh()->status)->toBe('verified');

    // Valuation reflects real asset value, out of stock count, and verified purchase expense
    $summary = app(InventoryService::class)->valuationSummary();
    expect($summary['valuation_method'])->toBe(InventoryService::VALUATION_METHOD)
        ->and($summary['units'])->toBe(7)
        ->and($summary['asset_value'])->toBe(7 * (int) round(((2 * 100000) + (5 * 120000)) / 7))
        ->and($summary['finance_summary']['verified_purchase_expense'])->toBe(600000);
});

it('filters, searches, sorts, and paginates the inventory dashboard accurately', function (): void {
    $warehouse = User::factory()->create(['role' => 'warehouse']);

    InventoryItem::factory()->create([
        'sku' => 'NET-ZERO',
        'name' => 'Konektor RJ45',
        'category' => 'Jaringan',
        'quantity_on_hand' => 0,
        'reorder_level' => 10,
        'unit_cost' => 50000,
    ]);
    InventoryItem::factory()->create([
        'sku' => 'CCTV-HIGH',
        'name' => 'NVR 16 Channel',
        'category' => 'CCTV',
        'quantity_on_hand' => 10,
        'reorder_level' => 2,
        'unit_cost' => 1200000,
    ]);

    $this->actingAs($warehouse)
        ->get(route('inventory.index', [
            'stock_status' => 'out_of_stock',
            'search' => 'RJ45',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.out_of_stock', 1)
            ->where('stats.item_types', 2)
            ->has('items', 1)
            ->where('items.0.sku', 'NET-ZERO')
            ->where('items.0.is_out_of_stock', true)
        );
});
