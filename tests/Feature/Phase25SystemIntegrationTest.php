<?php

use App\Http\Resources\Api\V1\OrderDetailResource;
use App\Models\ChecklistTemplate;
use App\Models\CustomerAsset;
use App\Models\Estimate;
use App\Models\FinancialTransaction;
use App\Models\InventoryItem;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderSparePart;
use App\Models\TechnicianWorkSession;
use App\Models\User;
use App\Models\Warranty;
use App\Models\WarrantyClaim;
use App\Services\Finance\FinancialReportService;
use App\Services\Notifications\NotificationCenterService;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    Queue::fake();
    Storage::fake('local');
});

it('executes full multi-role Phase 0 through Phase 2 lifecycle with snapshot immutability and persisted financial reconciliation', function (): void {
    $operator = User::factory()->create(['role' => 'operator', 'name' => 'Operator Utama']);
    $admin = User::factory()->create(['role' => 'admin', 'name' => 'Admin Operasional']);
    $supervisor = User::factory()->create(['role' => 'supervisor', 'name' => 'Supervisor Lapangan']);
    $technician = User::factory()->create([
        'role' => 'technician',
        'name' => 'Teknisi Lapangan',
        'availability_status' => 'available',
    ]);
    $warehouse = User::factory()->create(['role' => 'warehouse', 'name' => 'Petugas Gudang']);
    $customer = User::factory()->create(['role' => 'customer', 'name' => 'Siti Pelanggan', 'phone' => '6281234560099']);

    $service = Service::factory()->create([
        'name' => 'Instalasi & Perbaikan Jaringan',
        'code' => 'NET-PRO',
        'is_active' => true,
        'warranty_days' => 30,
    ]);

    $checklist = ChecklistTemplate::query()->create([
        'service_id' => $service->id,
        'item_key' => 'int-throughput-check',
        'title' => 'Verifikasi throughput & ping stabil',
        'standard_code' => 'SOP-INT-01',
        'requires_evidence' => false,
        'sort_order' => 1,
    ]);

    // 1. Warehouse manages inventory + weighted-average stock-in + verified purchase expense
    $ram = InventoryItem::factory()->create([
        'sku' => 'SSD-INT-512',
        'name' => 'NVMe SSD 512GB',
        'category' => 'Storage',
        'unit' => 'unit',
        'quantity_on_hand' => 4,
        'reorder_level' => 2,
        'unit_cost' => 100000,
        'selling_price' => 175000,
        'warranty_days' => 90,
        'is_active' => true,
    ]);

    // Warehouse cannot directly create financial expense records; Admin/Operator records purchase with linked finance expense
    $this->actingAs($warehouse)
        ->post(route('inventory.movements.store'), [
            'inventory_item_id' => $ram->id,
            'type' => 'stock_in',
            'quantity' => 6,
            'unit_cost' => 150000,
            'supplier_name' => 'Distributor Nusantara',
            'reference' => 'PO-25-001',
            'record_finance_expense' => true,
        ])
        ->assertForbidden();

    // Stock in 6 units @ 150.000 -> weighted avg = (4*100k + 6*150k)/10 = 130.000
    $this->actingAs($admin)
        ->post(route('inventory.movements.store'), [
            'inventory_item_id' => $ram->id,
            'type' => 'stock_in',
            'quantity' => 6,
            'unit_cost' => 150000,
            'supplier_name' => 'Distributor Nusantara',
            'reference' => 'PO-25-001',
            'record_finance_expense' => true,
        ])
        ->assertRedirect();

    expect($ram->fresh()->quantity_on_hand)->toBe(10)
        ->and($ram->fresh()->unit_cost)->toBe(130000);

    $purchaseExpense = FinancialTransaction::query()
        ->where('category', 'inventory_purchase')
        ->latest('id')
        ->firstOrFail();

    expect($purchaseExpense->status)->toBe('pending')
        ->and($purchaseExpense->amount)->toBe(900000);

    $this->actingAs($operator)
        ->patch(route('finance.transactions.verify', $purchaseExpense))
        ->assertRedirect();

    // 2. Customer registers device, browses catalog, creates booking, and verifies tracking
    $this->actingAs($customer)
        ->post(route('devices.store'), [
            'name' => 'Workstation Desain',
            'category' => 'Laptop / PC',
            'brand' => 'Lenovo',
            'model' => 'ThinkPad T14',
            'serial_number' => 'SN-INT-2026-01',
            'location' => 'Ruang Studio',
        ])
        ->assertRedirect();

    $asset = CustomerAsset::query()->where('customer_id', $customer->id)->firstOrFail();

    $this->actingAs($customer)->get(route('booking.create'))->assertOk();

    $this->actingAs($customer)
        ->post(route('booking.store'), [
            'service_id' => $service->id,
            'customer_name' => $customer->name,
            'phone' => '081234560099',
            'email' => $customer->email,
            'address' => 'Jl. Merdeka Selatan No. 88',
            'district' => 'Pusat',
            'service_mode' => 'onsite',
            'complaint' => 'SSD lambat dan sistem jaringan sering terputus saat render.',
            'preferred_date' => now()->addDay()->toDateString(),
            'preferred_window' => 'morning',
            'urgency' => 'normal',
            'privacy_accepted' => true,
        ])
        ->assertRedirect();

    $order = ServiceOrder::query()->latest('created_at')->firstOrFail();
    $accessCode = (string) session('access_code');
    expect($order->customer_id)->toBe($customer->id)
        ->and($order->status)->toBe('new')
        ->and(strlen($accessCode))->toBe(8);

    // Track via ticket + access code
    $lookupResponse = $this->post(route('track.lookup'), [
        'ticket_number' => $order->ticket_number,
        'access_code' => $accessCode,
    ])->assertRedirect();

    $this->get((string) $lookupResponse->headers->get('Location'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('track-show')
            ->where('order.ticket_number', $order->ticket_number)
        );

    // 3. Operator/Supervisor triages, links device, assigns technician, chats with customer
    $this->actingAs($operator)
        ->post(route('orders.device.link', $order), [
            'mode' => 'existing',
            'customer_asset_id' => $asset->id,
            'diagnosis' => 'SSD penuh & bottleneck I/O',
        ])
        ->assertRedirect();

    $this->actingAs($supervisor)
        ->patch(route('orders.status.update', $order), ['status' => 'triaged'])
        ->assertRedirect();

    $this->actingAs($supervisor)
        ->patch(route('orders.status.update', $order), ['status' => 'scheduled'])
        ->assertRedirect();

    $this->actingAs($operator)
        ->patch(route('orders.team.update', $order), [
            'technician_ids' => [$technician->id],
            'lead_technician_id' => $technician->id,
        ])
        ->assertRedirect();

    $this->actingAs($customer)
        ->post(route('orders.messages.store', $order), [
            'body' => 'Apakah unit bisa selesai hari ini?',
        ])
        ->assertRedirect();

    // 4. Technician runs work session, requests spare part, uploads evidence, completes checklist
    $this->actingAs($technician)
        ->patch(route('orders.status.update', $order), ['status' => 'on_the_way'])
        ->assertRedirect();

    $this->actingAs($technician)
        ->patch(route('orders.status.update', $order), ['status' => 'in_progress'])
        ->assertRedirect();

    $this->actingAs($technician)
        ->post(route('orders.work-sessions.store', $order), ['notes' => 'Mulai penggantian SSD'])
        ->assertRedirect();

    $workSession = TechnicianWorkSession::query()->where('service_order_id', $order->id)->firstOrFail();

    $this->actingAs($technician)
        ->post(route('orders.spare-parts.store', $order), [
            'inventory_item_id' => $ram->id,
            'requested_quantity' => 2,
            'request_notes' => 'Upgrade + cadangan SSD',
        ])
        ->assertRedirect();

    $sparePart = ServiceOrderSparePart::query()->where('service_order_id', $order->id)->firstOrFail();

    // Warehouse issues 2 units @ historical unit_cost = 130.000 -> COGS = 260.000
    $this->actingAs($warehouse)
        ->patch(route('orders.spare-parts.issue', [$order, $sparePart]), [
            'issued_quantity' => 2,
            'issue_notes' => 'Diserahkan ke teknisi',
        ])
        ->assertRedirect();

    expect($ram->fresh()->quantity_on_hand)->toBe(8)
        ->and($sparePart->fresh()->unit_cost)->toBe(130000);

    // Later inventory cost increase must NOT alter already-issued spare part historical COGS
    $this->actingAs($warehouse)
        ->post(route('inventory.movements.store'), [
            'inventory_item_id' => $ram->id,
            'type' => 'stock_in',
            'quantity' => 2,
            'unit_cost' => 230000,
            'supplier_name' => 'Supplier Baru',
            'reference' => 'PO-25-002',
            'record_finance_expense' => false,
        ])
        ->assertRedirect();

    expect($sparePart->fresh()->unit_cost)->toBe(130000);

    $this->actingAs($technician)
        ->post(route('orders.evidences.store', $order), [
            'stage' => 'before',
            'photo' => UploadedFile::fake()->create('before.jpg', 120, 'image/jpeg'),
            'caption' => 'Kondisi sebelum upgrade',
        ])
        ->assertRedirect();

    $this->actingAs($technician)
        ->post(route('orders.evidences.store', $order), [
            'stage' => 'after',
            'photo' => UploadedFile::fake()->create('after.jpg', 120, 'image/jpeg'),
            'caption' => 'SSD terpasang dan teruji',
        ])
        ->assertRedirect();

    $this->actingAs($technician)
        ->patch(route('orders.checklist.update', [$order, $checklist]), [
            'result' => 'passed',
            'notes' => 'Stabil',
        ])
        ->assertRedirect();

    $this->actingAs($technician)
        ->patch(route('orders.work-sessions.update', [$order, $workSession]), [
            'action' => 'complete',
            'notes' => 'Selesai dikerjakan',
        ])
        ->assertRedirect();

    $this->actingAs($technician)
        ->patch(route('orders.status.update', $order), ['status' => 'verification'])
        ->assertRedirect();

    $this->actingAs($supervisor)
        ->patch(route('orders.status.update', $order), ['status' => 'waiting_payment'])
        ->assertRedirect();

    // 5. Admin creates & sends multi-category quotation; Customer reviews via Notification deep link & approves
    // Labor (200.000) + Spare part (2 * 175.000 = 350.000) + Operational (50.000) = 600.000 subtotal
    // Discount 50.000 => taxable 550.000; 10% tax = 55.000 => Total = 605.000
    $this->actingAs($admin)
        ->post(route('finance.quotations.store'), [
            'service_order_id' => $order->id,
            'discount_amount' => 50000,
            'tax_rate' => 10,
            'valid_until' => now()->addDays(7)->toDateString(),
            'notes' => 'Estimasi resmi jasa dan SSD',
            'send_now' => true,
            'items' => [
                ['item_type' => 'labor', 'label' => 'Jasa Instalasi & Migrasi', 'quantity' => '1', 'unit_price' => 200000],
                ['item_type' => 'spare_part', 'inventory_item_id' => $ram->id, 'service_order_spare_part_id' => $sparePart->id, 'label' => 'NVMe SSD 512GB', 'quantity' => '2', 'unit_price' => 175000],
                ['item_type' => 'operational', 'label' => 'Transportasi Onsite', 'quantity' => '1', 'unit_price' => 50000],
            ],
        ])
        ->assertRedirect();

    $estimate = Estimate::query()->where('service_order_id', $order->id)->firstOrFail();
    expect($estimate->status)->toBe('sent')
        ->and($estimate->subtotal)->toBe(600000)
        ->and($estimate->discount_amount)->toBe(50000)
        ->and($estimate->tax_amount)->toBe(55000)
        ->and($estimate->total)->toBe(605000);

    // Customer notification center shows accessible quotation deep link
    $this->actingAs($customer)
        ->get(route('notifications.index', ['category' => 'finance']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('inbox.data.0.href', "/finance/quotations/{$estimate->id}")
            ->where('inbox.data.0.href_accessible', true)
        );

    $this->actingAs($customer)
        ->post(route('finance.quotations.approve', $estimate), ['notes' => 'Setuju lanjutkan'])
        ->assertRedirect();

    $estimate->refresh();
    expect($estimate->status)->toBe('approved')
        ->and($estimate->approved_snapshot)->toBeArray()
        ->and($estimate->approved_snapshot['total'])->toBe(605000)
        ->and($order->fresh()->estimated_total)->toBe(605000);

    // 6. Operator converts approved quotation to issued invoice
    $this->actingAs($operator)
        ->post(route('finance.quotations.convert', $estimate), [
            'issue_now' => true,
            'due_date' => now()->addDays(5)->toDateString(),
        ])
        ->assertRedirect();

    $invoice = Invoice::query()->where('estimate_id', $estimate->id)->firstOrFail();
    expect($estimate->fresh()->status)->toBe('converted')
        ->and($invoice->status)->toBe('issued')
        ->and($invoice->total_amount)->toBe(605000)
        ->and($invoice->issued_snapshot)->toBeArray()
        ->and($invoice->issued_snapshot['total_amount'])->toBe(605000);

    // Customer sees accessible invoice deep link and can view the issued invoice + payment form enabled
    $this->actingAs($customer)
        ->get(route('notifications.index', ['category' => 'finance', 'search' => $invoice->invoice_number]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('inbox.data.0.href', "/finance/invoices/{$invoice->id}")
            ->where('inbox.data.0.href_accessible', true)
        );

    $this->actingAs($customer)
        ->get(route('finance.invoices.show', $invoice))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('permissions.can_record_payment', true)
            ->where('permissions.can_verify_immediately', false)
        );

    // 7. Customer submits partial payment with proof; verify_immediately cannot be spoofed by customer
    $this->actingAs($customer)
        ->post(route('finance.invoices.payments.store', $invoice), [
            'amount' => 205000,
            'payment_method' => 'bank_transfer',
            'reference' => 'CUST-DP-001',
            'idempotency_key' => 'idem-cust-dp-001',
            'verify_immediately' => true,
            'proof' => UploadedFile::fake()->create('dp-proof.png', 120, 'image/png'),
        ])
        ->assertRedirect();

    $dpTx = FinancialTransaction::query()->where('idempotency_key', 'idem-cust-dp-001')->firstOrFail();
    expect($dpTx->status)->toBe('pending')
        ->and($invoice->fresh()->status)->toBe('issued')
        ->and($invoice->fresh()->paid_amount)->toBe(0);

    // Customer payment proof downloadable by owning customer
    $this->actingAs($customer)->get(route('finance.transactions.proof', $dpTx))->assertOk();

    // Operator verifies partial payment -> status partially_paid
    $this->actingAs($operator)
        ->patch(route('finance.transactions.verify', $dpTx))
        ->assertRedirect();

    $invoice->refresh();
    expect($invoice->status)->toBe('partially_paid')
        ->and($invoice->paid_amount)->toBe(205000)
        ->and($invoice->outstandingBalance())->toBe(400000);

    // Overpayment blocked; rejected payment leaves balance unchanged; valid remaining settlement pays invoice in full
    $this->actingAs($customer)
        ->post(route('finance.invoices.payments.store', $invoice), [
            'amount' => 401000,
            'payment_method' => 'qr',
            'reference' => 'CUST-OVER-001',
            'idempotency_key' => 'idem-over-001',
        ])
        ->assertSessionHasErrors('amount');

    $this->actingAs($customer)
        ->post(route('finance.invoices.payments.store', $invoice), [
            'amount' => 400000,
            'payment_method' => 'qr',
            'reference' => 'CUST-BAD-002',
            'idempotency_key' => 'idem-bad-002',
        ])
        ->assertRedirect();

    $badTx = FinancialTransaction::query()->where('idempotency_key', 'idem-bad-002')->firstOrFail();

    $this->actingAs($operator)
        ->patch(route('finance.transactions.reject', $badTx), ['reason' => 'Lampiran buram'])
        ->assertRedirect();

    expect($invoice->fresh()->paid_amount)->toBe(205000)
        ->and($invoice->fresh()->outstandingBalance())->toBe(400000);

    $this->actingAs($customer)
        ->post(route('finance.invoices.payments.store', $invoice), [
            'amount' => 400000,
            'payment_method' => 'qr',
            'reference' => 'CUST-FINAL-003',
            'idempotency_key' => 'idem-final-003',
            'proof' => UploadedFile::fake()->create('final-proof.jpg', 120, 'image/jpeg'),
        ])
        ->assertRedirect();

    $finalTx = FinancialTransaction::query()->where('idempotency_key', 'idem-final-003')->firstOrFail();
    $this->actingAs($operator)
        ->patch(route('finance.transactions.verify', $finalTx))
        ->assertRedirect();

    $invoice->refresh();
    expect($invoice->status)->toBe('paid')
        ->and($invoice->paid_amount)->toBe(605000)
        ->and($invoice->outstandingBalance())->toBe(0);

    // Complete order, activate warranty, submit & resolve warranty claim
    $this->actingAs($operator)
        ->patch(route('orders.status.update', $order), ['status' => 'completed'])
        ->assertRedirect();

    $this->actingAs($operator)
        ->post(route('orders.warranties.store', $order), [
            'scope_type' => 'service',
            'duration_days' => 30,
            'coverage_description' => 'Garansi jasa dan konfigurasi',
        ])
        ->assertRedirect();

    $warranty = Warranty::query()->where('service_order_id', $order->id)->firstOrFail();

    $this->actingAs($customer)
        ->post(route('warranties.claims.store', $warranty), [
            'issue_description' => 'Koneksi drop saat beban penuh pada hari kedua.',
        ])
        ->assertRedirect();

    $claim = WarrantyClaim::query()->where('warranty_id', $warranty->id)->firstOrFail();

    $this->actingAs($supervisor)
        ->patch(route('warranties.claims.review', $claim), [
            'status' => 'approved',
            'eligibility_checked' => true,
            'review_notes' => 'Dijadwalkan pengecekan ulang gratis.',
            'create_service_order' => true,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $this->actingAs($supervisor)
        ->patch(route('warranties.claims.resolve', $claim), [
            'resolution_notes' => 'Firmware driver diperbarui dan stabil.',
        ])
        ->assertRedirect();

    expect($claim->fresh()->status)->toBe('resolved');

    // 8. Financial Integrity & Profitability Database Reconciliation
    $report = app(FinancialReportService::class);
    $summary = $report->summary();
    $breakdown = $report->orderBreakdown($order->fresh());

    expect($summary['invoiced_revenue'])->toBe(605000)
        ->and($summary['cash_received'])->toBe(605000)
        ->and($summary['outstanding_receivables'])->toBe(0)
        ->and($summary['spare_part_cogs'])->toBe(260000)
        ->and($summary['inventory_purchase_expenses'])->toBe(900000)
        ->and($summary['gross_profit_accrual'])->toBe(345000)
        ->and($summary['net_cash_flow'])->toBe(-295000)
        ->and($breakdown['invoiced_total'])->toBe(605000)
        ->and($breakdown['cash_received'])->toBe(605000)
        ->and($breakdown['spare_part_cogs'])->toBe(260000)
        ->and($breakdown['gross_profit'])->toBe(345000);

    expect(DB::table('audit_logs')->where('event', 'finance.quotation_approved')->exists())->toBeTrue()
        ->and(DB::table('audit_logs')->where('event', 'finance.invoice_issued')->exists())->toBeTrue()
        ->and(DB::table('audit_logs')->where('event', 'finance.transaction_verified')->exists())->toBeTrue()
        ->and(DB::table('audit_logs')->where('event', 'finance.transaction_rejected')->exists())->toBeTrue();
});

it('enforces Phase 2.5 multi-role security isolation, private files, notification routing, and API finance boundaries', function (): void {
    $operator = User::factory()->create(['role' => 'operator']);
    $admin = User::factory()->create(['role' => 'admin']);
    $supervisor = User::factory()->create(['role' => 'supervisor']);
    $technician = User::factory()->withTwoFactor()->create(['role' => 'technician']);
    $warehouse = User::factory()->create(['role' => 'warehouse']);
    $customerA = User::factory()->create(['role' => 'customer']);
    $customerB = User::factory()->create(['role' => 'customer']);

    $service = Service::factory()->create();
    $orderA = ServiceOrder::factory()->create([
        'customer_id' => $customerA->id,
        'service_id' => $service->id,
        'assigned_technician_id' => $technician->id,
        'status' => 'in_progress',
    ]);
    $assetA = CustomerAsset::query()->create([
        'customer_id' => $customerA->id,
        'device_code' => 'DEV-SEC-001',
        'name' => 'Laptop Customer A',
        'category' => 'Laptop',
        'status' => 'active',
    ]);

    $this->actingAs($technician)
        ->post(route('orders.evidences.store', $orderA), [
            'stage' => 'before',
            'photo' => UploadedFile::fake()->create('priv.jpg', 120, 'image/jpeg'),
        ])
        ->assertRedirect();
    $evidence = $orderA->workEvidences()->firstOrFail();

    $this->actingAs($admin)
        ->post(route('finance.quotations.store'), [
            'service_order_id' => $orderA->id,
            'send_now' => true,
            'items' => [['item_type' => 'labor', 'label' => 'Diagnosis', 'quantity' => '1', 'unit_price' => 150000, 'estimated_unit_cost' => 45000]],
        ])
        ->assertRedirect();
    $estimate = Estimate::query()->where('service_order_id', $orderA->id)->firstOrFail();

    $this->actingAs($admin)
        ->post(route('finance.quotations.approve', $estimate))
        ->assertRedirect();
    $this->actingAs($operator)
        ->post(route('finance.quotations.convert', $estimate), ['issue_now' => true])
        ->assertRedirect();
    $invoice = Invoice::query()->where('estimate_id', $estimate->id)->firstOrFail();

    $this->actingAs($customerA)
        ->post(route('finance.invoices.payments.store', $invoice), [
            'amount' => 150000,
            'payment_method' => 'bank_transfer',
            'reference' => 'SEC-PAY-1',
            'proof' => UploadedFile::fake()->create('proof.jpg', 120, 'image/jpeg'),
        ])
        ->assertRedirect();
    $tx = FinancialTransaction::query()->where('invoice_id', $invoice->id)->firstOrFail();

    // Also attach a verified expense on the order to assert API OrderDetailResource only sums type=income
    FinancialTransaction::factory()->create([
        'service_order_id' => $orderA->id,
        'actor_id' => $operator->id,
        'type' => 'expense',
        'category' => 'other_expense',
        'amount' => 99000,
        'status' => 'verified',
    ]);

    // 1. Cross-customer isolation across orders, devices, private evidence, quotations, invoices, and proofs
    $this->actingAs($customerB)->get(route('orders.show', $orderA))->assertNotFound();
    $this->actingAs($customerB)->get(route('devices.show', $assetA))->assertNotFound();
    $this->actingAs($customerB)->get(route('orders.evidences.file', [$orderA, $evidence]))->assertNotFound();
    $this->actingAs($customerB)->get(route('finance.quotations.show', $estimate))->assertForbidden();
    $this->actingAs($customerB)->get(route('finance.invoices.show', $invoice))->assertForbidden();
    $this->actingAs($customerB)->post(route('finance.invoices.payments.store', $invoice), [
        'amount' => 50000,
        'payment_method' => 'cash',
    ])->assertForbidden();
    $this->actingAs($customerB)->get(route('finance.transactions.proof', $tx))->assertForbidden();

    // 2. Supervisor oversight boundary: can view quotations/invoices WITHOUT raw finance or proof download links
    $this->actingAs($supervisor)
        ->get(route('finance.invoices.show', $invoice))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('invoice.payments.0.proof_url', null)
            ->where('permissions.can_record_payment', false)
        );
    $this->actingAs($supervisor)->get(route('finance.transactions.proof', $tx))->assertForbidden();
    $this->actingAs($supervisor)->get(route('finance.index'))->assertForbidden();

    // 3. Warehouse & Technician boundaries
    $this->actingAs($warehouse)->get(route('inventory.index'))->assertOk();
    $this->actingAs($warehouse)->get(route('workforce.index'))->assertForbidden();
    $this->actingAs($warehouse)->get(route('finance.index'))->assertForbidden();
    $this->actingAs($warehouse)->get(route('finance.invoices.show', $invoice))->assertForbidden();

    $this->actingAs($technician)->get(route('finance.index'))->assertForbidden();
    $this->actingAs($technician)->get(route('finance.quotations.show', $estimate))->assertForbidden();
    $techToken = $technician->createToken('Phone', ['mobile', 'role:technician', 'auth:2fa'], now()->addDay())->plainTextToken;
    $this->withToken($techToken)->getJson(route('api.v1.inventory.index'))->assertOk();

    // 4. Notification deep-link policy sanitization
    $center = app(NotificationCenterService::class);
    app(NotificationDispatcher::class)->notifyUsers(
        [$customerA],
        'finance.invoice_issued',
        'Tagihan Anda',
        'Tagihan terbit',
        "/finance/invoices/{$invoice->id}",
        [],
        false,
    );

    expect($center->resolveSafeHref($customerA, "/finance/invoices/{$invoice->id}"))->toBe("/finance/invoices/{$invoice->id}")
        ->and($center->resolveSafeHref($customerA, "/finance/quotations/{$estimate->id}"))->toBe("/finance/quotations/{$estimate->id}")
        ->and($center->resolveSafeHref($customerA, '/finance'))->toBeNull()
        ->and($center->resolveSafeHref($customerA, '/warranties'))->toBeNull()
        ->and($center->resolveSafeHref($customerB, "/finance/invoices/{$invoice->id}"))->toBeNull()
        ->and($center->resolveSafeHref($technician, "/finance/invoices/{$invoice->id}"))->toBeNull()
        ->and($center->resolveSafeHref($supervisor, "/finance/invoices/{$invoice->id}"))->toBe("/finance/invoices/{$invoice->id}");

    // 5. Mobile API v1 OrderDetailResource only exposes income totals, never expenses
    $custToken = $customerA->createToken('Phone', ['mobile', 'role:customer'], now()->addDay())->plainTextToken;
    $this->withToken($custToken)
        ->getJson("/api/v1/orders/{$orderA->id}")
        ->assertOk()
        ->assertJsonPath('data.finance.verified_income', 0)
        ->assertJsonPath('data.finance.pending_income', 150000);
});
