<?php

use App\Models\AssistantActionProposal;
use App\Models\InventoryItem;
use App\Models\InventoryRestockRequest;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\Assistant\AssistantActionCoordinator;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

function createHardeningService(): Service
{
    return Service::create([
        'code' => 'SRV-HDN',
        'slug' => 'servis-komputer',
        'name' => 'Servis Laptop & Komputer',
        'category' => 'Komputer',
        'service_mode' => 'dropoff',
        'risk_level' => 'medium',
        'pricing_label' => 'Mulai Rp 150.000',
        'base_price_min' => 150000,
        'base_price_max' => 500000,
        'warranty_days' => 30,
        'is_active' => true,
        'summary' => 'Diagnosa dan perbaikan laptop serta komputer.',
    ]);
}

function createHardeningOrder(User $customer, Service $service, string $ticket = 'ADC-20261116-0901'): ServiceOrder
{
    return ServiceOrder::create([
        'ticket_number' => $ticket,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'customer_name' => $customer->name,
        'phone' => '6281234567890',
        'email' => $customer->email,
        'address' => 'Jl. Merdeka No. 10 Bandung',
        'service_mode' => 'dropoff',
        'complaint' => 'Laptop mati mendadak setelah boot logo muncul.',
        'preferred_date' => now()->toDateString(),
        'preferred_window' => 'morning',
        'urgency' => 'normal',
        'risk_level' => 'medium',
        'status' => 'triaged',
        'tracking_token_hash' => hash('sha256', 'tok-'.$ticket),
        'access_code_hash' => hash('sha256', 'code-'.$ticket),
    ]);
}

it('clarifies incomplete order requests over multiple turns and creates a valid proposal on follow-up', function () {
    createHardeningService();
    $operator = User::factory()->create(['role' => 'operator', 'is_active' => true]);

    $turn1 = $this->actingAs($operator)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'ADI, buatkan order servis laptop.',
        ])
        ->assertOk();

    expect($turn1->json('action_proposal'))->toBeNull()
        ->and($turn1->json('reply'))->toContain('Siap. Saya perlu mengetahui')
        ->and(ServiceOrder::query()->count())->toBe(0)
        ->and(AssistantActionProposal::query()->where('status', AssistantActionProposal::STATUS_AWAITING_CONFIRMATION)->count())->toBe(0);

    $turn2 = $this->actingAs($operator)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'Nama: Budi Santoso, HP: 081234567890, Email: budi@example.com, Alamat: Jl. Dago No. 15 Bandung, Keluhan: Laptop Asus tidak bisa booting sejak pagi tadi',
        ])
        ->assertOk();

    $proposal = $turn2->json('action_proposal');
    expect($proposal)->not->toBeNull()
        ->and($proposal['action_name'])->toBe('create_service_order')
        ->and($proposal['status'])->toBe('awaiting_confirmation')
        ->and($proposal['confirm_url'])->toStartWith('/assistant/actions/');

    $this->actingAs($operator)
        ->postJson(route('assistant.actions.confirm', ['proposal' => $proposal['action_id']]), [
            'payload_hash' => $proposal['payload_hash'],
        ])
        ->assertOk()
        ->assertJsonPath('status', 'executed');

    expect(ServiceOrder::query()->where('customer_name', 'Budi Santoso')->exists())->toBeTrue();
});

it('exits active clarification cleanly when user cancels or shifts to a read-only question', function () {
    createHardeningService();
    $operator = User::factory()->create(['role' => 'operator', 'is_active' => true]);

    $this->actingAs($operator)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'ADI, buatkan order servis laptop.',
        ])
        ->assertOk()
        ->assertJsonPath('action_proposal', null);

    $cancelled = $this->actingAs($operator)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'tidak jadi',
        ])
        ->assertOk();

    expect($cancelled->json('action_proposal'))->toBeNull()
        ->and(AssistantActionProposal::query()->where('status', AssistantActionProposal::STATUS_AWAITING_CONFIRMATION)->count())->toBe(0);

    $this->actingAs($operator)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'ADI, buatkan order servis laptop.',
        ])
        ->assertOk();

    $shifted = $this->actingAs($operator)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'Berapa total pemasukan bulan ini?',
        ])
        ->assertOk();

    expect($shifted->json('action_proposal'))->toBeNull()
        ->and($shifted->json('reply'))->not->toContain('lengkapi detail');
});

it('prevents staff from borrowing their own identity when creating a customer order', function () {
    createHardeningService();
    $operator = User::factory()->create([
        'role' => 'operator',
        'name' => 'Operator Internal',
        'email' => 'op-internal@example.com',
        'phone' => '6281112223334',
        'is_active' => true,
    ]);

    $response = $this->actingAs($operator)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'Buatkan order servis laptop untuk keluhan: Laptop sering overheat dan mati mendadak ketika digunakan bekerja',
        ])
        ->assertOk();

    expect($response->json('action_proposal'))->toBeNull()
        ->and($response->json('reply'))->toContain('nama pelanggan')
        ->and(ServiceOrder::query()->count())->toBe(0);
});

it('requires explicit quantity for restock and rejects duplicate open restock requests', function () {
    $warehouse = User::factory()->create(['role' => 'warehouse', 'is_active' => true]);
    $item = InventoryItem::create([
        'sku' => 'SKU-HDN-01',
        'name' => 'Thermal Paste Pro',
        'category' => 'Consumables',
        'unit' => 'pcs',
        'quantity_on_hand' => 2,
        'reorder_level' => 5,
        'unit_cost' => 75000,
        'is_active' => true,
    ]);

    $missingQty = $this->actingAs($warehouse)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'Ajukan restock SKU-HDN-01',
        ])
        ->assertOk();

    expect($missingQty->json('action_proposal'))->toBeNull()
        ->and($missingQty->json('reply'))->toContain('jumlah unit restock');

    $withQty = $this->actingAs($warehouse)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'Sebanyak 12 unit',
        ])
        ->assertOk();

    $proposal = $withQty->json('action_proposal');
    expect($proposal)->not->toBeNull()
        ->and($proposal['action_name'])->toBe('create_inventory_restock_request')
        ->and($proposal['preview']['financial_impact']['amount'])->toBe(900000);

    // Confirm first request
    $this->actingAs($warehouse)
        ->postJson(route('assistant.actions.confirm', ['proposal' => $proposal['action_id']]), [
            'payload_hash' => $proposal['payload_hash'],
        ])
        ->assertOk()
        ->assertJsonPath('status', 'executed');

    expect(InventoryRestockRequest::query()->where('inventory_item_id', $item->id)->count())->toBe(1);

    // Duplicate open restock request must be blocked cleanly
    $duplicate = $this->actingAs($warehouse)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'Ajukan restock SKU-HDN-01 sebanyak 5 unit',
        ])
        ->assertOk();

    expect($duplicate->json('action_proposal'))->toBeNull()
        ->and($duplicate->json('reply'))->toContain('diblokir karena masih ada permintaan');
});

it('rejects ambiguous single-token technician matches until user clarifies full name', function () {
    $service = createHardeningService();
    $customer = User::factory()->create(['role' => 'customer', 'is_active' => true]);
    $order = createHardeningOrder($customer, $service, 'ADC-20261116-0902');
    $supervisor = User::factory()->create(['role' => 'supervisor', 'is_active' => true]);

    $andiWijaya = User::factory()->create([
        'role' => 'technician',
        'name' => 'Andi Wijaya',
        'is_active' => true,
        'availability_status' => 'available',
    ]);
    User::factory()->create([
        'role' => 'technician',
        'name' => 'Andi Pratama',
        'is_active' => true,
        'availability_status' => 'available',
    ]);

    $ambiguous = $this->actingAs($supervisor)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'Tugaskan teknisi Andi untuk tiket ADC-20261116-0902',
        ])
        ->assertOk();

    expect($ambiguous->json('action_proposal'))->toBeNull()
        ->and($ambiguous->json('reply'))->toContain('Andi Pratama')
        ->and($ambiguous->json('reply'))->toContain('Andi Wijaya');

    $clarified = $this->actingAs($supervisor)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'Andi Wijaya',
        ])
        ->assertOk();

    $proposal = $clarified->json('action_proposal');
    expect($proposal)->not->toBeNull()
        ->and($proposal['action_name'])->toBe('assign_technician')
        ->and($proposal['preview']['proposed_changes'][0]['after'])->toBe('Andi Wijaya');

    $this->actingAs($supervisor)
        ->postJson(route('assistant.actions.confirm', ['proposal' => $proposal['action_id']]), [
            'payload_hash' => $proposal['payload_hash'],
        ])
        ->assertOk()
        ->assertJsonPath('status', 'executed');

    expect($order->fresh()->assigned_technician_id)->toBe($andiWijaya->id);
});

it('emits mobile api v1 action urls and supports bearer confirm/cancel flows', function () {
    createHardeningService();
    $customer = User::factory()->create([
        'role' => 'customer',
        'name' => 'Pelanggan Mobile',
        'email' => 'mobile-cust@example.com',
        'phone' => '6281299988877',
        'address' => 'Jl. Asia Afrika No. 8 Bandung',
        'is_active' => true,
    ]);

    $token = $customer->createToken('mobile-test', ['mobile', 'role:customer'], now()->addDay())->plainTextToken;

    $propose = $this->withToken($token)->postJson('/api/v1/assistant/messages', [
        'message' => 'Buatkan order servis komputer keluhan: PC tidak tampil gambar saat dinyalakan sejak kemarin sore',
    ])->assertOk();

    $proposal = $propose->json('action_proposal');
    expect($proposal)->not->toBeNull()
        ->and($proposal['confirm_url'])->toBe("/api/v1/assistant/actions/{$proposal['action_id']}/confirm")
        ->and($proposal['cancel_url'])->toBe("/api/v1/assistant/actions/{$proposal['action_id']}/cancel");

    $this->withToken($token)->postJson($proposal['confirm_url'], [
        'payload_hash' => $proposal['payload_hash'],
    ])
        ->assertOk()
        ->assertJsonPath('status', 'executed')
        ->assertJsonPath('action_proposal.status', 'executed');

    // Replay on mobile API returns 409 conflict + replay audit log
    $this->withToken($token)->postJson($proposal['confirm_url'], [
        'payload_hash' => $proposal['payload_hash'],
    ])
        ->assertStatus(409)
        ->assertJsonPath('status', 'executed');

    expect(DB::table('audit_logs')->where('auditable_id', $proposal['action_id'])->where('event', 'assistant.action_replay_rejected')->exists())->toBeTrue();
});

it('returns 409 conflict under active cache lock and rolls back db changes when execute throws mid-transaction', function () {
    $warehouse = User::factory()->create(['role' => 'warehouse', 'is_active' => true]);
    $item = InventoryItem::create([
        'sku' => 'SKU-ROLLBACK-1',
        'name' => 'RAM DDR5 16GB',
        'category' => 'Memory',
        'unit' => 'pcs',
        'quantity_on_hand' => 1,
        'reorder_level' => 4,
        'unit_cost' => 650000,
        'is_active' => true,
    ]);

    $propose = $this->actingAs($warehouse)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'Ajukan restock SKU-ROLLBACK-1 sebanyak 4 unit',
        ])
        ->assertOk();

    $proposal = $propose->json('action_proposal');
    expect($proposal)->not->toBeNull();

    // 1. Hold lock to simulate concurrent confirmation
    $lock = Cache::lock('assistant_action:'.$proposal['action_id'], 10);
    expect($lock->get())->toBeTrue();

    try {
        $this->actingAs($warehouse)
            ->postJson(route('assistant.actions.confirm', ['proposal' => $proposal['action_id']]), [
                'payload_hash' => $proposal['payload_hash'],
            ])
            ->assertStatus(409)
            ->assertJsonPath('status', 'conflict');
    } finally {
        $lock->release();
    }

    // 2. Throw mid-execution inside NotificationDispatcher (with active recipient) to verify full DB transaction rollback
    User::factory()->create(['role' => 'operator', 'is_active' => true]);
    app()->bind(NotificationDispatcher::class, static function (): NotificationDispatcher {
        return new class extends NotificationDispatcher
        {
            /** @param array<string, mixed> $meta */
            public function notifyUsers(
                iterable $users,
                string $eventKey,
                string $title,
                string $body,
                ?string $href = null,
                array $meta = [],
                bool $sendWhatsapp = true,
                ?string $dedupeKey = null,
                ?string $priority = null,
            ): void {
                throw new RuntimeException('Simulasi kegagalan downstream');
            }
        };
    });

    $this->actingAs($warehouse)
        ->postJson(route('assistant.actions.confirm', ['proposal' => $proposal['action_id']]), [
            'payload_hash' => $proposal['payload_hash'],
        ])
        ->assertStatus(422)
        ->assertJsonPath('status', 'failed');

    expect(InventoryRestockRequest::query()->where('inventory_item_id', $item->id)->count())->toBe(0)
        ->and(AssistantActionProposal::query()->findOrFail($proposal['action_id'])->status)->toBe(AssistantActionProposal::STATUS_FAILED)
        ->and(DB::table('audit_logs')->where('auditable_id', $proposal['action_id'])->where('event', 'assistant.action_failed')->exists())->toBeTrue();
});

it('rejects cross-user confirmation, tampered payload hashes, and unauthorized mobile roles cleanly', function () {
    $service = createHardeningService();
    $customer = User::factory()->create(['role' => 'customer', 'is_active' => true]);
    createHardeningOrder($customer, $service, 'ADC-20261116-0903');

    $operatorA = User::factory()->create(['role' => 'operator', 'is_active' => true]);
    $operatorB = User::factory()->create(['role' => 'operator', 'is_active' => true]);

    $propose = $this->actingAs($operatorA)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'Buatkan penawaran tiket ADC-20261116-0903 dengan jasa instalasi Rp 200000',
        ])
        ->assertOk();

    $proposal = $propose->json('action_proposal');
    expect($proposal)->not->toBeNull();

    // Tampered hash by owner -> 422
    $this->actingAs($operatorA)
        ->postJson(route('assistant.actions.confirm', ['proposal' => $proposal['action_id']]), [
            'payload_hash' => str_repeat('0', 64),
        ])
        ->assertStatus(422)
        ->assertJsonPath('status', 'tampered');

    // Cross-user attempt by another operator -> 403
    $this->actingAs($operatorB)
        ->postJson(route('assistant.actions.confirm', ['proposal' => $proposal['action_id']]), [
            'payload_hash' => $proposal['payload_hash'],
        ])
        ->assertStatus(403)
        ->assertJsonPath('status', 'forbidden');

    // Mobile customer attempting to confirm an operator's quotation proposal -> 403
    $customerToken = $customer->createToken('mobile-test', ['mobile', 'role:customer'], now()->addDay())->plainTextToken;
    $this->withToken($customerToken)->postJson('/api/v1/assistant/actions/'.$proposal['action_id'].'/confirm', [
        'payload_hash' => $proposal['payload_hash'],
    ])->assertStatus(403);

    $coordinator = app(AssistantActionCoordinator::class);
    $pending = AssistantActionProposal::query()->findOrFail($proposal['action_id']);
    expect($pending->status)->toBe(AssistantActionProposal::STATUS_AWAITING_CONFIRMATION)
        ->and($coordinator)->toBeInstanceOf(AssistantActionCoordinator::class);
});
