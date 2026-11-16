<?php

use App\Models\Estimate;
use App\Models\FinancialTransaction;
use App\Models\InventoryItem;
use App\Models\Invoice;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\Finance\FinanceMath;
use App\Services\Finance\FinancialReportService;
use App\Services\Inventory\InventoryService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

test('quotation lifecycle supports multi-category decimal-safe calculation, expiration, approval snapshot, and rejection', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $customer = User::factory()->create(['role' => 'customer']);
    $otherCustomer = User::factory()->create(['role' => 'customer']);
    $order = ServiceOrder::factory()->create([
        'customer_id' => $customer->id,
        'customer_name' => $customer->name,
        'email' => $customer->email,
    ]);

    // Fractional quantity 1.5 * 100001 = 150001.5 -> half-up 150002
    // Spare part 2 * 75000 = 150000
    // Operational 1 * 25000 = 25000
    // Subtotal = 325002, Discount = 25002 => Taxable = 300000, Tax 11% = 33000, Total = 333000
    $this->actingAs($admin)
        ->post(route('finance.quotations.store'), [
            'service_order_id' => $order->id,
            'discount_amount' => 25002,
            'tax_rate' => '11.00',
            'valid_until' => now()->addDays(10)->toDateString(),
            'notes' => 'Ganti thermal paste & fan cleaning',
            'send_now' => false,
            'items' => [
                [
                    'item_type' => 'labor',
                    'label' => 'Jasa Bongkar & Cleaning',
                    'quantity' => '1.5',
                    'unit_price' => 100001,
                    'estimated_unit_cost' => 0,
                ],
                [
                    'item_type' => 'spare_part',
                    'label' => 'Thermal Paste High Performance',
                    'quantity' => '2',
                    'unit_price' => 75000,
                    'estimated_unit_cost' => 45000,
                ],
                [
                    'item_type' => 'operational',
                    'label' => 'Konsumsi Pengujian',
                    'quantity' => '1',
                    'unit_price' => 25000,
                    'estimated_unit_cost' => 15000,
                ],
            ],
        ])
        ->assertRedirect();

    $estimate = Estimate::query()->with('items')->latest('created_at')->firstOrFail();
    expect($estimate->quotation_number)->toStartWith('QUO-')
        ->and($estimate->status)->toBe('draft')
        ->and($estimate->labor_total)->toBe(150002)
        ->and($estimate->spare_parts_total)->toBe(150000)
        ->and($estimate->operational_total)->toBe(25000)
        ->and($estimate->subtotal)->toBe(325002)
        ->and($estimate->discount_amount)->toBe(25002)
        ->and($estimate->tax_amount)->toBe(33000)
        ->and($estimate->total)->toBe(333000);

    // Send to customer
    $this->actingAs($admin)
        ->post(route('finance.quotations.send', $estimate))
        ->assertRedirect();

    expect($estimate->fresh()->status)->toBe('sent');

    // Draft update is blocked after sending
    $this->actingAs($admin)
        ->put(route('finance.quotations.update', $estimate), [
            'discount_amount' => 0,
            'tax_rate' => '0',
            'items' => [
                ['item_type' => 'labor', 'label' => 'Mutasi ilegal', 'quantity' => '1', 'unit_price' => 50000],
            ],
        ])
        ->assertSessionHasErrors('status');

    // Customer B cannot view or approve Customer A's quotation
    $this->actingAs($otherCustomer)->get(route('finance.quotations.show', $estimate))->assertForbidden();
    $this->actingAs($otherCustomer)->post(route('finance.quotations.approve', $estimate))->assertForbidden();

    // Customer A views quotation without seeing internal estimated_unit_cost
    $this->actingAs($customer)
        ->get(route('finance.quotations.show', $estimate))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('quotation.id', $estimate->id)
            ->missing('quotation.items.0.estimated_unit_cost')
            ->where('auditLogs', [])
        );

    // Customer A approves quotation -> freezes approved_snapshot & updates order.estimated_total
    $this->actingAs($customer)
        ->post(route('finance.quotations.approve', $estimate), ['notes' => 'Setuju lanjut kerjakan'])
        ->assertRedirect();

    $estimate->refresh();
    expect($estimate->status)->toBe('approved')
        ->and($estimate->approved_by)->toBe($customer->id)
        ->and($estimate->approved_at)->not->toBeNull()
        ->and($estimate->approved_snapshot)->toBeArray()
        ->and($estimate->approved_snapshot['total'])->toBe(333000)
        ->and($order->fresh()->estimated_total)->toBe(333000);

    expect(DB::table('audit_logs')->where('event', 'finance.quotation_approved')->where('auditable_id', $estimate->id)->exists())->toBeTrue();

    // Second quotation: test rejection and expiration
    $this->actingAs($admin)
        ->post(route('finance.quotations.store'), [
            'service_order_id' => $order->id,
            'valid_until' => now()->addDays(5)->toDateString(),
            'send_now' => true,
            'items' => [
                ['item_type' => 'labor', 'label' => 'Re-balling Chipset', 'quantity' => '1', 'unit_price' => 500000],
            ],
        ])
        ->assertRedirect();

    $second = Estimate::query()->where('version', 2)->firstOrFail();
    $this->actingAs($customer)
        ->post(route('finance.quotations.reject', $second), ['reason' => 'Biaya terlalu mahal'])
        ->assertRedirect();

    expect($second->fresh()->status)->toBe('rejected')
        ->and($second->fresh()->rejection_reason)->toBe('Biaya terlalu mahal')
        ->and($second->fresh()->rejected_by)->toBe($customer->id);

    // Third quotation: expired valid_until blocks approval
    $this->actingAs($admin)
        ->post(route('finance.quotations.store'), [
            'service_order_id' => $order->id,
            'valid_until' => now()->addDays(2)->toDateString(),
            'send_now' => true,
            'items' => [
                ['item_type' => 'labor', 'label' => 'Ganti Keyboard', 'quantity' => '1', 'unit_price' => 200000],
            ],
        ])
        ->assertRedirect();

    $expiredQuote = Estimate::query()->where('version', 3)->firstOrFail();
    $expiredQuote->update(['valid_until' => now()->subDay()->toDateString()]);

    expect($expiredQuote->fresh()->resolvedStatus())->toBe('expired');

    $this->actingAs($customer)
        ->post(route('finance.quotations.approve', $expiredQuote))
        ->assertSessionHasErrors('status');

    expect($expiredQuote->fresh()->status)->toBe('expired');
});

test('invoice conversion, immutable snapshot, partial & full payments, duplicate/overpayment prevention, and cancellation work cleanly', function () {
    Storage::fake('local');

    $operator = User::factory()->create(['role' => 'operator']);
    $admin = User::factory()->create(['role' => 'admin']);
    $customer = User::factory()->create(['role' => 'customer']);
    $otherCustomer = User::factory()->create(['role' => 'customer']);

    $item = InventoryItem::create([
        'sku' => 'SSD-NVME-1TB',
        'name' => 'SSD NVMe 1TB',
        'category' => 'Storage',
        'unit' => 'pcs',
        'quantity_on_hand' => 5,
        'reorder_level' => 1,
        'unit_cost' => 600000,
        'selling_price' => 950000,
        'is_active' => true,
    ]);

    $order = ServiceOrder::factory()->create([
        'customer_id' => $customer->id,
        'customer_name' => $customer->name,
        'email' => $customer->email,
    ]);

    // Create & approve quotation
    $this->actingAs($admin)
        ->post(route('finance.quotations.store'), [
            'service_order_id' => $order->id,
            'tax_rate' => '0',
            'send_now' => true,
            'items' => [
                [
                    'item_type' => 'spare_part',
                    'inventory_item_id' => $item->id,
                    'label' => $item->name,
                    'quantity' => '1',
                    'unit_price' => 950000,
                ],
                [
                    'item_type' => 'labor',
                    'label' => 'Install OS & Migrasi Data',
                    'quantity' => '1',
                    'unit_price' => 150000,
                ],
            ],
        ])
        ->assertRedirect();

    $estimate = Estimate::query()->latest('created_at')->firstOrFail();
    $this->actingAs($customer)->post(route('finance.quotations.approve', $estimate))->assertRedirect();

    // Convert approved quotation -> issued invoice
    $this->actingAs($admin)
        ->post(route('finance.quotations.convert', $estimate), ['issue_now' => true])
        ->assertRedirect();

    $invoice = Invoice::query()->with('items')->latest('created_at')->firstOrFail();
    expect($invoice->invoice_number)->toStartWith('INV-')
        ->and($invoice->estimate_id)->toBe($estimate->id)
        ->and($invoice->status)->toBe('issued')
        ->and($invoice->total_amount)->toBe(1100000)
        ->and($invoice->issued_snapshot)->toBeArray()
        ->and($estimate->fresh()->status)->toBe('converted');

    // Double conversion is blocked
    $this->actingAs($admin)
        ->post(route('finance.quotations.convert', $estimate))
        ->assertSessionHasErrors('estimate_id');

    // Mutating inventory selling price after invoice issuance does not alter frozen invoice snapshot/total
    $item->update(['selling_price' => 1400000, 'unit_cost' => 800000]);
    expect($invoice->fresh()->total_amount)->toBe(1100000)
        ->and($invoice->fresh()->issued_snapshot['total_amount'])->toBe(1100000);

    // Overpayment blocked before any payment
    $this->actingAs($admin)
        ->post(route('finance.invoices.payments.store', $invoice), [
            'amount' => 1200000,
            'payment_method' => 'bank_transfer',
            'reference' => 'OVERPAY-01',
        ])
        ->assertSessionHasErrors('amount');

    // Partial payment with proof upload & idempotency key
    $proofFile = UploadedFile::fake()->create('bukti-dp.pdf', 120, 'application/pdf');
    $this->actingAs($admin)
        ->post(route('finance.invoices.payments.store', $invoice), [
            'amount' => 500000,
            'payment_method' => 'bank_transfer',
            'reference' => 'TRF-DP-01',
            'idempotency_key' => 'idem-dp-001',
            'proof' => $proofFile,
        ])
        ->assertRedirect();

    $dpTx = FinancialTransaction::query()->latest('id')->firstOrFail();
    expect($dpTx->status)->toBe('pending')
        ->and($dpTx->invoice_id)->toBe($invoice->id)
        ->and($dpTx->proof_path)->not->toBeNull();
    Storage::disk('local')->assertExists($dpTx->proof_path);

    // Proof download authorization: owner & admin ok, unrelated customer 403
    $this->actingAs($customer)->get(route('finance.transactions.proof', $dpTx))->assertOk();
    $this->actingAs($otherCustomer)->get(route('finance.transactions.proof', $dpTx))->assertForbidden();

    // Duplicate idempotency_key is blocked
    $this->actingAs($admin)
        ->post(route('finance.invoices.payments.store', $invoice), [
            'amount' => 100000,
            'payment_method' => 'bank_transfer',
            'reference' => 'TRF-DP-02',
            'idempotency_key' => 'idem-dp-001',
        ])
        ->assertSessionHasErrors('idempotency_key');

    // Duplicate active reference + amount is blocked
    $this->actingAs($admin)
        ->post(route('finance.invoices.payments.store', $invoice), [
            'amount' => 500000,
            'payment_method' => 'bank_transfer',
            'reference' => 'TRF-DP-01',
            'idempotency_key' => 'idem-dp-002',
        ])
        ->assertSessionHasErrors('reference');

    // Cannot cancel invoice once active/verified payments exist
    $this->actingAs($operator)->patch(route('finance.transactions.verify', $dpTx))->assertRedirect();
    expect($invoice->fresh()->paid_amount)->toBe(500000)
        ->and($invoice->fresh()->status)->toBe('partially_paid')
        ->and($invoice->fresh()->outstandingBalance())->toBe(600000);

    $this->actingAs($admin)
        ->post(route('finance.invoices.cancel', $invoice), ['reason' => 'Batal'])
        ->assertSessionHasErrors('status');

    // Remaining balance paid & rejected payment test
    $this->actingAs($admin)
        ->post(route('finance.invoices.payments.store', $invoice), [
            'amount' => 600000,
            'payment_method' => 'qr',
            'reference' => 'QRIS-BAD',
            'idempotency_key' => 'idem-bad-001',
        ])
        ->assertRedirect();
    $badTx = FinancialTransaction::query()->latest('id')->firstOrFail();

    // Admin cannot reject transaction; operator can
    $this->actingAs($admin)->patch(route('finance.transactions.reject', $badTx), ['reason' => 'Buram'])->assertForbidden();
    $this->actingAs($operator)->patch(route('finance.transactions.reject', $badTx), ['reason' => 'Bukti tidak valid'])->assertRedirect();
    expect($badTx->fresh()->status)->toBe('rejected')
        ->and($badTx->fresh()->rejection_reason)->toBe('Bukti tidak valid');

    // Full settlement via operator verify_immediately
    $this->actingAs($operator)
        ->post(route('finance.invoices.payments.store', $invoice), [
            'amount' => 600000,
            'payment_method' => 'cash',
            'reference' => 'CASH-FINAL',
            'idempotency_key' => 'idem-final-001',
            'verify_immediately' => true,
        ])
        ->assertRedirect();

    expect($invoice->fresh()->status)->toBe('paid')
        ->and($invoice->fresh()->paid_amount)->toBe(1100000)
        ->and($invoice->fresh()->outstandingBalance())->toBe(0);

    // Manual unpaid invoice supports dynamic overdue status & cancellation
    $this->actingAs($admin)
        ->post(route('finance.invoices.store'), [
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'issue_date' => now()->subDays(15)->toDateString(),
            'due_date' => now()->subDays(3)->toDateString(),
            'issue_now' => true,
            'items' => [
                ['item_type' => 'labor', 'label' => 'Konsultasi Jaringan', 'quantity' => '1', 'unit_price' => 250000],
            ],
        ])
        ->assertRedirect();

    $overdueInvoice = Invoice::query()->where('total_amount', 250000)->firstOrFail();
    expect($overdueInvoice->resolvedStatus())->toBe('overdue');

    $this->actingAs($admin)
        ->get(route('finance.invoices.index', ['status' => 'overdue']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('invoices.data.0.id', $overdueInvoice->id));

    $this->actingAs($admin)
        ->post(route('finance.invoices.cancel', $overdueInvoice), ['reason' => 'Salah input pelanggan'])
        ->assertRedirect();
    expect($overdueInvoice->fresh()->status)->toBe('cancelled')
        ->and($overdueInvoice->fresh()->voided_by)->toBe($admin->id);
});

test('financial reporting separates revenue vs cash vs COGS vs expenses and preserves inventory expense deduplication', function () {
    $operator = User::factory()->create(['role' => 'operator']);
    $warehouse = User::factory()->create(['role' => 'warehouse']);
    $customer = User::factory()->create(['role' => 'customer']);

    $item = InventoryItem::create([
        'sku' => 'RAM-DDR4-16G',
        'name' => 'RAM 16GB DDR4',
        'category' => 'Memory',
        'unit' => 'pcs',
        'quantity_on_hand' => 0,
        'reorder_level' => 2,
        'unit_cost' => 0,
        'selling_price' => 550000,
        'is_active' => true,
    ]);

    // Stock in 4 units @ 300,000 => 1,200,000 inventory_purchase expense (INV-MOV-*)
    $inventoryService = app(InventoryService::class);
    $movement = $inventoryService->recordMovement([
        'inventory_item_id' => $item->id,
        'type' => 'stock_in',
        'quantity' => 4,
        'unit_cost' => 300000,
        'reference' => 'PO-001',
        'record_finance_expense' => true,
    ], $operator);
    $tx = FinancialTransaction::query()->where('reference', 'INV-MOV-'.$movement->id)->firstOrFail();
    expect(FinancialTransaction::query()->where('reference', 'INV-MOV-'.$movement->id)->count())->toBe(1);

    // Operator verifies the inventory purchase expense
    $this->actingAs($operator)->patch(route('finance.transactions.verify', $tx))->assertRedirect();

    $order = ServiceOrder::factory()->create([
        'customer_id' => $customer->id,
        'customer_name' => $customer->name,
        'estimated_total' => 700000,
    ]);

    // Issue 2 spare parts to order -> COGS = 2 * 300,000 = 600,000 (does NOT create a duplicate financial_transactions expense)
    $sparePart = $inventoryService->requestSparePart($order, $item->fresh(), $operator, 2);
    $inventoryService->issueSparePart($sparePart, $warehouse, 2, 'Pasang RAM');
    expect($sparePart->fresh()->unit_cost)->toBe(300000);

    // Record & verify operational expense linked to order (50,000)
    $this->actingAs($operator)
        ->post(route('finance.store'), [
            'service_order_id' => $order->id,
            'type' => 'expense',
            'category' => 'transport_kurir',
            'amount' => 50000,
            'payment_method' => 'cash',
            'reference' => 'EXP-OPS-1',
            'occurred_at' => now()->toDateString(),
        ])
        ->assertRedirect();
    $opsTx = FinancialTransaction::query()->where('reference', 'EXP-OPS-1')->firstOrFail();
    $this->actingAs($operator)->patch(route('finance.transactions.verify', $opsTx))->assertRedirect();

    // Create & issue manual invoice for order: 2 * 550,000 spare parts + 150,000 labor = 1,250,000
    $this->actingAs($operator)
        ->post(route('finance.invoices.store'), [
            'service_order_id' => $order->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'issue_now' => true,
            'items' => [
                [
                    'item_type' => 'spare_part',
                    'service_order_spare_part_id' => $sparePart->id,
                    'label' => 'RAM 16GB DDR4',
                    'quantity' => '2',
                    'unit_price' => 550000,
                ],
                [
                    'item_type' => 'labor',
                    'label' => 'Jasa Upgrade RAM',
                    'quantity' => '1',
                    'unit_price' => 150000,
                ],
            ],
        ])
        ->assertRedirect();

    $invoice = Invoice::query()->where('service_order_id', $order->id)->firstOrFail();

    // Verify partial cash received: 800,000
    $this->actingAs($operator)
        ->post(route('finance.invoices.payments.store', $invoice), [
            'amount' => 800000,
            'payment_method' => 'bank_transfer',
            'reference' => 'TRF-800',
            'verify_immediately' => true,
        ])
        ->assertRedirect();

    $reports = app(FinancialReportService::class);
    $summary = $reports->summary();

    expect($summary['invoiced_revenue'])->toBe(1250000)
        ->and($summary['cash_received'])->toBe(800000)
        ->and($summary['outstanding_receivables'])->toBe(450000)
        ->and($summary['inventory_purchase_expenses'])->toBe(1200000)
        ->and($summary['operational_expenses'])->toBe(50000)
        ->and($summary['spare_part_cogs'])->toBe(600000)
        ->and($summary['gross_profit_accrual'])->toBe(1250000 - 600000 - 50000)
        ->and($summary['net_cash_flow'])->toBe(800000 - 1250000);

    $breakdown = $reports->orderBreakdown($order->fresh());
    expect($breakdown['invoiced_total'])->toBe(1250000)
        ->and($breakdown['cash_received'])->toBe(800000)
        ->and($breakdown['spare_part_cogs'])->toBe(600000)
        ->and($breakdown['direct_expenses'])->toBe(50000)
        ->and($breakdown['gross_profit'])->toBe(600000);
});

test('role permissions enforce supervisor read oversight and block unauthorized financial access', function () {
    $supervisor = User::factory()->create(['role' => 'supervisor']);
    $technician = User::factory()->create(['role' => 'technician']);
    $warehouse = User::factory()->create(['role' => 'warehouse']);
    $customer = User::factory()->create(['role' => 'customer']);
    $order = ServiceOrder::factory()->create(['customer_id' => $customer->id]);

    // Supervisor has read oversight on quotations, invoices, and order profitability, but NOT raw finance ledger
    $this->actingAs($supervisor)->get(route('finance.index'))->assertForbidden();
    $this->actingAs($supervisor)->get(route('finance.quotations.index'))->assertOk();
    $this->actingAs($supervisor)->get(route('finance.invoices.index'))->assertOk();
    $this->actingAs($supervisor)
        ->get(route('orders.show', $order))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('order.finance.profitability.service_order_id', $order->id));

    // Supervisor CANNOT mutate financial states
    $this->actingAs($supervisor)->post(route('finance.store'), [
        'type' => 'income',
        'category' => 'service_payment',
        'amount' => 10000,
        'payment_method' => 'cash',
        'occurred_at' => now()->toDateString(),
    ])->assertForbidden();

    $this->actingAs($supervisor)->post(route('finance.quotations.store'), [
        'service_order_id' => $order->id,
        'items' => [['label' => 'Test', 'quantity' => '1', 'unit_price' => 10000]],
    ])->assertForbidden();

    // Technician and Warehouse blocked from Finance module routes
    foreach ([$technician, $warehouse] as $user) {
        $this->actingAs($user)->get(route('finance.index'))->assertForbidden();
        $this->actingAs($user)->get(route('finance.quotations.index'))->assertForbidden();
        $this->actingAs($user)->get(route('finance.invoices.index'))->assertForbidden();
    }

    // Customer blocked from global finance ledger & never sees profitability or draft invoices
    $this->actingAs($customer)->get(route('finance.index'))->assertForbidden();

    $draftInvoice = Invoice::create([
        'invoice_number' => 'INV-DRAFT-0001',
        'service_order_id' => $order->id,
        'customer_id' => $customer->id,
        'customer_name' => $customer->name,
        'status' => 'draft',
        'total_amount' => 100000,
    ]);

    $this->actingAs($customer)->get(route('finance.invoices.show', $draftInvoice))->assertForbidden();
    $this->actingAs($customer)
        ->get(route('orders.show', $order))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('order.finance.profitability', null)
            ->where('order.finance.invoices', [])
        );

    expect(FinanceMath::lineTotal('1.25', 100000))->toBe(125000)
        ->and(FinanceMath::taxAmount(100000, '11.50'))->toBe(11500);
});
