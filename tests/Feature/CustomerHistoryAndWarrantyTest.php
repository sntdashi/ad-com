<?php

use App\Models\AssetServiceRecord;
use App\Models\CustomerAsset;
use App\Models\InventoryItem;
use App\Models\OrderWorkEvidence;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\TechnicianWorkSession;
use App\Models\User;
use App\Models\Warranty;
use App\Models\WarrantyClaim;
use App\Services\CustomerHistory\CustomerDeviceService;
use App\Services\Inventory\InventoryService;
use App\Services\Warranty\WarrantyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    Queue::fake();
});

it('registers customer devices, prevents duplicate serials, preserves historical snapshots, and isolates customer records', function (): void {
    $customerA = User::factory()->create(['role' => 'customer']);
    $customerB = User::factory()->create(['role' => 'customer']);
    $admin = User::factory()->create(['role' => 'admin']);

    // Customer A registers their laptop
    $this->actingAs($customerA)
        ->post(route('devices.store'), [
            'name' => 'Laptop Lab TKJ',
            'category' => 'Laptop / PC',
            'brand' => 'Lenovo',
            'model' => 'ThinkPad T14',
            'serial_number' => 'SN-T14-998877',
            'location' => 'Lab 1',
            'notes' => 'Unit praktikum utama',
        ])
        ->assertRedirect();

    $deviceA = CustomerAsset::query()->where('customer_id', $customerA->id)->firstOrFail();
    expect($deviceA->device_code)->toStartWith('DEV-')
        ->and($deviceA->serial_masked)->not->toBe('SN-T14-998877')
        ->and($deviceA->serial_number_hash)->toBe(CustomerAsset::hashSerial('SN-T14-998877'));

    // Duplicate serial on same customer is rejected (case & whitespace normalized)
    $this->actingAs($customerA)
        ->post(route('devices.store'), [
            'name' => 'Duplicate Laptop',
            'category' => 'Laptop / PC',
            'serial_number' => ' sn-t14-998877 ',
        ])
        ->assertSessionHasErrors('serial_number');

    // Link an order to Device A with diagnosis snapshot
    $service = Service::factory()->create(['warranty_days' => 14]);
    $orderA = ServiceOrder::factory()->create([
        'customer_id' => $customerA->id,
        'service_id' => $service->id,
        'status' => 'completed',
        'completed_at' => now()->subDay(),
    ]);

    $this->actingAs($admin)
        ->post(route('orders.device.link', $orderA), [
            'mode' => 'existing',
            'customer_asset_id' => $deviceA->id,
            'diagnosis' => 'Thermal paste kering & fan berdebu',
            'action_taken' => 'Repaste + pembersihan heatsink',
        ])
        ->assertRedirect();

    $record = AssetServiceRecord::query()
        ->where('customer_asset_id', $deviceA->id)
        ->where('service_order_id', $orderA->id)
        ->firstOrFail();

    expect($record->device_snapshot['name'])->toBe('Laptop Lab TKJ');

    // Updating device name later must preserve the historical snapshot in asset_service_records
    $this->actingAs($customerA)
        ->patch(route('devices.update', $deviceA), [
            'name' => 'Laptop Gudang Cadangan',
            'category' => 'Laptop / PC',
            'status' => 'active',
        ])
        ->assertRedirect();

    $deviceA->refresh();
    $record->refresh();
    expect($deviceA->name)->toBe('Laptop Gudang Cadangan')
        ->and($record->device_snapshot['name'])->toBe('Laptop Lab TKJ');

    // Customer B isolation: cannot view Customer A device, cannot update it, and does not see it in index
    $this->actingAs($customerB)->get(route('devices.show', $deviceA))->assertNotFound();
    $this->actingAs($customerB)->patch(route('devices.update', $deviceA), [
        'name' => 'Hijack',
        'category' => 'Laptop / PC',
        'status' => 'active',
    ])->assertForbidden();

    $orderB = ServiceOrder::factory()->create([
        'customer_id' => $customerB->id,
        'service_id' => $service->id,
    ]);
    $this->actingAs($customerB)
        ->post(route('orders.device.link', $orderB), [
            'mode' => 'existing',
            'customer_asset_id' => $deviceA->id,
        ])
        ->assertForbidden();

    $this->actingAs($customerB)
        ->get(route('devices.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('isCustomer', true)
            ->has('devices.data', 0)
        );
});

it('builds accurate unified service history timeline from real records without exposing internal data to customers', function (): void {
    $customer = User::factory()->create(['role' => 'customer', 'name' => 'Budi Santoso']);
    $technician = User::factory()->create(['role' => 'technician', 'name' => 'Teknisi Andi']);
    $warehouse = User::factory()->create(['role' => 'warehouse', 'name' => 'Staf Gudang']);
    $supervisor = User::factory()->create(['role' => 'supervisor', 'name' => 'Supra Supervisor']);

    $service = Service::factory()->create(['name' => 'Perbaikan Laptop', 'warranty_days' => 30]);
    $order = ServiceOrder::factory()->create([
        'customer_id' => $customer->id,
        'customer_name' => $customer->name,
        'service_id' => $service->id,
        'assigned_technician_id' => $technician->id,
        'status' => 'in_progress',
    ]);

    $order->assignments()->create([
        'user_id' => $technician->id,
        'assignment_role' => 'technician',
        'assigned_at' => now()->subHours(5),
    ]);

    $order->statusEvents()->create([
        'actor_id' => $customer->id,
        'status' => 'new',
        'title' => 'Permintaan dibuat',
        'description' => 'Keluhan layar berkedip.',
        'visibility' => 'customer',
        'occurred_at' => now()->subHours(6),
    ]);

    $order->statusEvents()->create([
        'actor_id' => $supervisor->id,
        'status' => 'triaged',
        'title' => 'Catatan triase internal',
        'description' => 'PRIVATE INTERNAL COST NOTE: beli part grosir Rp 120rb',
        'visibility' => 'internal',
        'occurred_at' => now()->subHours(5)->addMinutes(10),
    ]);

    TechnicianWorkSession::query()->create([
        'service_order_id' => $order->id,
        'technician_id' => $technician->id,
        'status' => 'completed',
        'started_at' => now()->subHours(4),
        'paused_at' => now()->subHours(3),
        'resumed_at' => now()->subHours(2)->subMinutes(30),
        'paused_seconds' => 1800,
        'completed_at' => now()->subHours(2),
        'duration_seconds' => 5400,
        'notes' => 'PRIVATE TECH NOTE: kabel fleksibel agak getas',
    ]);

    $part = InventoryItem::factory()->create([
        'sku' => 'LCD-14-FHD',
        'name' => 'Panel LCD 14 FHD',
        'unit' => 'unit',
        'quantity_on_hand' => 3,
        'unit_cost' => 450000,
        'selling_price' => 700000,
        'warranty_days' => 90,
        'is_active' => true,
    ]);

    $inventoryService = app(InventoryService::class);
    $spareRequest = $inventoryService->requestSparePart($order, $part, $technician, 1, 'Butuh ganti panel');
    $inventoryService->issueSparePart($spareRequest, $warehouse, 1, 'Dikeluarkan dari rak A');

    OrderWorkEvidence::query()->create([
        'service_order_id' => $order->id,
        'uploaded_by' => $technician->id,
        'stage' => 'after',
        'caption' => 'Tampilan normal setelah penggantian',
        'customer_confirmed_at' => now()->subHour(),
        'confirmed_by' => $customer->id,
        'confirmation_note' => 'Sudah dites dan aman.',
    ]);

    $device = app(CustomerDeviceService::class)->registerDevice([
        'name' => 'Asus VivoBook',
        'category' => 'Laptop',
        'serial_number' => 'ASUS-VB-01',
    ], $customer, $customer);
    app(CustomerDeviceService::class)->linkOrderToDevice($order, $device, $supervisor);

    app(WarrantyService::class)->registerWarranty($order, [
        'scope_type' => 'spare_part',
        'service_order_spare_part_id' => $spareRequest->id,
        'customer_asset_id' => $device->id,
    ], $supervisor);

    // Customer view of order & device hides internal notes, purchase cost, and internal statusEvents
    $this->actingAs($customer)
        ->get(route('orders.show', $order))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('order.spare_parts_summary.internal_cost_total', null)
            ->where('order.work_sessions', [])
            ->where('order.unified_timeline', function ($timeline): bool {
                $titles = collect($timeline)->pluck('title')->all();
                $descriptions = collect($timeline)->pluck('description')->implode(' ');

                return in_array('Permintaan dibuat', $titles, true)
                    && in_array('Teknisi ditugaskan', $titles, true)
                    && in_array('Pengerjaan dimulai', $titles, true)
                    && in_array('Pengerjaan dijeda sementara', $titles, true)
                    && in_array('Pengerjaan dilanjutkan', $titles, true)
                    && in_array('Sesi perbaikan selesai', $titles, true)
                    && in_array('Spare-part dikeluarkan ke pekerjaan', $titles, true)
                    && in_array('Konfirmasi pelanggan diterima', $titles, true)
                    && ! in_array('Catatan triase internal', $titles, true)
                    && ! str_contains($descriptions, 'PRIVATE INTERNAL COST NOTE')
                    && ! str_contains($descriptions, 'PRIVATE TECH NOTE');
            })
        );

    $this->actingAs($customer)
        ->get(route('devices.show', $device))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('device.name', 'Asus VivoBook')
            ->has('warranties', 1)
            ->where('warranties.0.scope_type', 'spare_part')
            ->where('warranties.0.duration_days', 90)
        );
});

it('requires explicit warranty configuration, computes server-side expiration, and deduplicates notifications', function (): void {
    $customer = User::factory()->create(['role' => 'customer', 'phone' => '6281234567890']);
    $admin = User::factory()->create(['role' => 'admin']);
    $supervisor = User::factory()->create(['role' => 'supervisor']);

    // Service with 0 warranty_days cannot blindly auto-activate warranty without explicit duration
    $noWarrantyService = Service::factory()->create(['warranty_days' => 0]);
    $order = ServiceOrder::factory()->create([
        'customer_id' => $customer->id,
        'service_id' => $noWarrantyService->id,
        'status' => 'completed',
    ]);

    $this->actingAs($admin)
        ->post(route('orders.warranties.store', $order), [
            'scope_type' => 'service',
        ])
        ->assertSessionHasErrors('duration_days');

    // Explicit registration activates warranty and dispatches 1 warranty.activated notification
    $this->actingAs($admin)
        ->post(route('orders.warranties.store', $order), [
            'scope_type' => 'service',
            'duration_days' => 14,
            'starts_at' => now()->subDays(10)->toDateTimeString(),
            'coverage_description' => 'Garansi jasa instalasi ulang',
        ])
        ->assertRedirect();

    $warranty = Warranty::query()->where('service_order_id', $order->id)->firstOrFail();
    expect($warranty->resolvedStatus())->toBe('active')
        ->and($warranty->isApproachingExpiration())->toBeTrue();

    expect(
        DB::table('notifications')
            ->where('notifiable_id', $customer->id)
            ->where('data', 'like', '%warranty.activated%')
            ->count()
    )->toBe(1);

    // Monitor approaching expiration twice -> must send exactly 1 customer notification, no duplicates
    $warrantyService = app(WarrantyService::class);
    $firstRun = $warrantyService->monitorWarranties(now(), 7);
    $secondRun = $warrantyService->monitorWarranties(now(), 7);

    expect($firstRun['expiring_notified'])->toBe(1)
        ->and($secondRun['expiring_notified'])->toBe(0);

    expect(
        DB::table('notifications')
            ->where('notifiable_id', $customer->id)
            ->where('data', 'like', '%warranty.approaching_expiration%')
            ->count()
    )->toBe(1);

    // Fast-forward past expiration -> resolvedStatus is expired server-side immediately & monitor marks expired once
    $future = now()->addDays(10);
    expect($warranty->resolvedStatus($future))->toBe('expired');

    $expRun1 = $warrantyService->monitorWarranties($future, 7);
    $expRun2 = $warrantyService->monitorWarranties($future, 7);

    $warranty->refresh();
    expect($warranty->status)->toBe('expired')
        ->and($expRun1['expired_marked'])->toBe(1)
        ->and($expRun2['expired_marked'])->toBe(0);

    expect(
        DB::table('notifications')
            ->where('notifiable_id', $customer->id)
            ->where('data', 'like', '%warranty.expired%')
            ->count()
    )->toBe(1);

    // Submitting a claim against an expired warranty is blocked
    $this->actingAs($customer)
        ->post(route('warranties.claims.store', $warranty), [
            'issue_description' => 'Masalah muncul lagi setelah lewat masa garansi.',
        ])
        ->assertSessionHasErrors('warranty');
});

it('executes warranty claim eligibility, duplicate prevention, approval with follow-up order, voiding, and role enforcement', function (): void {
    $customer = User::factory()->create(['role' => 'customer']);
    $otherCustomer = User::factory()->create(['role' => 'customer']);
    $technician = User::factory()->create(['role' => 'technician']);
    $warehouse = User::factory()->create(['role' => 'warehouse']);
    $supervisor = User::factory()->create(['role' => 'supervisor']);

    $device = app(CustomerDeviceService::class)->registerDevice([
        'name' => 'Mikrotik CCR',
        'category' => 'Jaringan',
        'serial_number' => 'MK-CCR-777',
    ], $customer, $customer);

    $service = Service::factory()->create(['warranty_days' => 30]);
    $originOrder = ServiceOrder::factory()->create([
        'customer_id' => $customer->id,
        'customer_asset_id' => $device->id,
        'service_id' => $service->id,
        'status' => 'completed',
    ]);

    // Unauthorized roles cannot register warranty or access admin hub
    $this->actingAs($customer)->post(route('orders.warranties.store', $originOrder), [
        'scope_type' => 'service',
        'duration_days' => 30,
    ])->assertForbidden();
    $this->actingAs($technician)->post(route('orders.warranties.store', $originOrder), [
        'scope_type' => 'service',
        'duration_days' => 30,
    ])->assertForbidden();
    $this->actingAs($customer)->get(route('warranties.index'))->assertForbidden();
    $this->actingAs($warehouse)->get(route('devices.index'))->assertForbidden();

    // Supervisor registers service warranty
    $this->actingAs($supervisor)
        ->post(route('orders.warranties.store', $originOrder), [
            'scope_type' => 'service',
            'duration_days' => 30,
            'customer_asset_id' => $device->id,
        ])
        ->assertRedirect();

    $warranty = Warranty::query()->where('service_order_id', $originOrder->id)->firstOrFail();

    // Another customer cannot submit a claim on someone else's warranty
    $this->actingAs($otherCustomer)
        ->post(route('warranties.claims.store', $warranty), [
            'issue_description' => 'Mencoba klaim milik orang lain.',
        ])
        ->assertForbidden();

    // Owning customer submits valid claim
    $this->actingAs($customer)
        ->post(route('warranties.claims.store', $warranty), [
            'issue_description' => 'Konfigurasi routing putus lagi setiap 30 menit.',
        ])
        ->assertRedirect();

    $claim = WarrantyClaim::query()->where('warranty_id', $warranty->id)->firstOrFail();
    expect($claim->status)->toBe('submitted')
        ->and($claim->claim_number)->toStartWith('CLM-');

    // Duplicate active claim on the same warranty is rejected
    $this->actingAs($customer)
        ->post(route('warranties.claims.store', $warranty), [
            'issue_description' => 'Pengajuan ganda saat klaim pertama masih aktif.',
        ])
        ->assertSessionHasErrors('warranty');

    // Customer & technician cannot review or resolve claims
    $this->actingAs($customer)->patch(route('warranties.claims.review', $claim), [
        'status' => 'approved',
    ])->assertForbidden();
    $this->actingAs($technician)->patch(route('warranties.claims.review', $claim), [
        'status' => 'approved',
    ])->assertForbidden();

    // Supervisor views admin warranty dashboard and approves claim with follow-up order
    $this->actingAs($supervisor)
        ->get(route('warranties.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.active', 1)
            ->where('stats.active_claims', 1)
        );

    $this->actingAs($supervisor)
        ->patch(route('warranties.claims.review', $claim), [
            'status' => 'approved',
            'eligibility_checked' => true,
            'eligibility_notes' => 'Masih dalam cakupan garansi konfigurasi 30 hari.',
            'review_notes' => 'Disetujui untuk penjadwalan ulang teknisi.',
            'create_service_order' => true,
        ])
        ->assertRedirect();

    $claim->refresh();
    expect($claim->status)->toBe('approved')
        ->and($claim->eligibility_checked)->toBeTrue()
        ->and($claim->claim_service_order_id)->not->toBeNull();

    $followUpOrder = ServiceOrder::query()->findOrFail($claim->claim_service_order_id);
    expect($followUpOrder->customer_asset_id)->toBe($device->id)
        ->and($followUpOrder->customer_id)->toBe($customer->id)
        ->and($followUpOrder->complaint)->toContain($claim->claim_number);

    // Supervisor marks claim resolved
    $this->actingAs($supervisor)
        ->patch(route('warranties.claims.resolve', $claim), [
            'resolution_notes' => 'Firmware diperbarui dan stabilitas router normal 24 jam.',
        ])
        ->assertRedirect();

    $claim->refresh();
    expect($claim->status)->toBe('resolved')
        ->and($claim->resolved_at)->not->toBeNull();

    // Voiding warranty prevents further claims
    $this->actingAs($supervisor)
        ->patch(route('warranties.void', $warranty), [
            'void_reason' => 'Ditemukan kerusakan fisik akibat petir eksternal.',
        ])
        ->assertRedirect();

    $warranty->refresh();
    expect($warranty->resolvedStatus())->toBe('void');

    expect(fn () => app(WarrantyService::class)->submitClaim(
        $warranty,
        $customer,
        'Masih mencoba klaim setelah void.',
    ))->toThrow(ValidationException::class);

    // Verify audit trail captured all key events
    $auditEvents = DB::table('audit_logs')->pluck('event')->all();
    expect($auditEvents)->toContain('customer_asset.registered')
        ->toContain('warranty.registered')
        ->toContain('warranty_claim.submitted')
        ->toContain('warranty_claim.reviewed')
        ->toContain('warranty_claim.resolved')
        ->toContain('warranty.voided');
});
