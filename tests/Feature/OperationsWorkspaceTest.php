<?php

use App\Models\FinancialTransaction;
use App\Models\InventoryItem;
use App\Models\ServiceOrder;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('each operational role reaches only its intended workspace', function () {
    $operator = User::factory()->withTwoFactor()->create(['role' => 'operator']);
    $warehouse = User::factory()->withTwoFactor()->create(['role' => 'warehouse']);
    $customer = User::factory()->create(['role' => 'customer']);

    $this->actingAs($operator)->get(route('finance.index'))->assertOk();
    $this->actingAs($operator)->get(route('team.index'))->assertOk();
    $this->actingAs($warehouse)->get(route('inventory.index'))->assertOk();
    $this->actingAs($warehouse)->get(route('finance.index'))->assertForbidden();
    $this->actingAs($warehouse)->get(route('team.index'))->assertForbidden();
    $this->actingAs($customer)->get(route('inventory.index'))->assertForbidden();
});

test('a customer message is attached to the ticket and routed to its PIC', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $technician = User::factory()->withTwoFactor()->create(['role' => 'technician']);
    $order = ServiceOrder::factory()->create([
        'customer_id' => $customer->id,
        'assigned_technician_id' => $technician->id,
    ]);

    $this->actingAs($customer)->post(route('orders.messages.store', $order), [
        'body' => 'Apakah teknisi sudah menuju lokasi?',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $this->assertDatabaseHas('order_messages', [
        'service_order_id' => $order->id,
        'sender_id' => $customer->id,
        'recipient_id' => $technician->id,
        'body' => 'Apakah teknisi sudah menuju lokasi?',
    ]);
});

test('ticket chat rejects an unassigned customer and an unrelated technician', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $technician = User::factory()->withTwoFactor()->create(['role' => 'technician']);
    $order = ServiceOrder::factory()->create(['customer_id' => $customer->id]);

    $this->actingAs($customer)->post(route('orders.messages.store', $order), [
        'body' => 'Halo teknisi',
    ])->assertSessionHasErrors(['body' => 'Chat aktif setelah PIC teknisi ditugaskan ke tiket ini.']);

    $this->actingAs($technician)->post(route('orders.messages.store', $order), [
        'body' => 'Pesan tanpa assignment',
    ])->assertForbidden();

    $this->assertDatabaseCount('order_messages', 0);
});

test('warehouse records atomic stock movements and cannot create a negative balance', function () {
    $warehouse = User::factory()->withTwoFactor()->create(['role' => 'warehouse']);
    $item = InventoryItem::factory()->create([
        'quantity_on_hand' => 8,
        'reorder_level' => 2,
    ]);

    $this->actingAs($warehouse)->post(route('inventory.movements.store'), [
        'inventory_item_id' => $item->id,
        'type' => 'stock_out',
        'quantity' => 3,
        'notes' => 'Dipakai untuk tiket lapangan.',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($item->fresh()->quantity_on_hand)->toBe(5);
    $this->assertDatabaseHas('inventory_movements', [
        'inventory_item_id' => $item->id,
        'type' => 'stock_out',
        'quantity' => 3,
        'balance_after' => 5,
    ]);

    $this->actingAs($warehouse)->post(route('inventory.movements.store'), [
        'inventory_item_id' => $item->id,
        'type' => 'stock_out',
        'quantity' => 6,
    ])->assertSessionHasErrors(['quantity' => 'Stok tidak cukup untuk mutasi keluar ini.']);

    expect($item->fresh()->quantity_on_hand)->toBe(5);
});

test('admin records finance entries while only an operator verifies them', function () {
    $admin = User::factory()->withTwoFactor()->create(['role' => 'admin']);
    $operator = User::factory()->withTwoFactor()->create(['role' => 'operator']);
    $order = ServiceOrder::factory()->create();

    $this->actingAs($admin)->post(route('finance.store'), [
        'service_order_id' => $order->id,
        'type' => 'income',
        'category' => 'service_payment',
        'amount' => 250000,
        'payment_method' => 'bank_transfer',
        'reference' => 'TRF-TEST-001',
        'occurred_at' => '2026-09-04',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $transaction = FinancialTransaction::query()->sole();
    expect($transaction->status)->toBe('pending');

    $this->actingAs($admin)->patch(route('finance.transactions.verify', $transaction))->assertForbidden();
    $this->actingAs($operator)->patch(route('finance.transactions.verify', $transaction))->assertRedirect();

    expect($transaction->fresh())
        ->status->toBe('verified')
        ->verified_by->toBe($operator->id)
        ->verified_at->not->toBeNull();
    $this->assertDatabaseHas('audit_logs', [
        'event' => 'finance.transaction_verified',
        'auditable_id' => (string) $transaction->id,
    ]);
});

test('operator manages all roles while admin cannot modify an operator', function () {
    $operator = User::factory()->withTwoFactor()->create(['role' => 'operator']);
    $admin = User::factory()->withTwoFactor()->create(['role' => 'admin']);
    $member = User::factory()->create(['role' => 'customer']);

    $this->actingAs($operator)->patch(route('team.update', $member), [
        'role' => 'warehouse',
        'phone' => '628111222333',
        'avatar_url' => 'https://example.com/maya.jpg',
        'specialty' => 'Inventaris',
        'bio' => 'Mengelola kebutuhan stok.',
        'show_phone_publicly' => false,
        'is_active' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($member->fresh()->role)->toBe('warehouse');

    $this->actingAs($admin)->patch(route('team.update', $operator), [
        'role' => 'customer',
        'phone' => null,
        'avatar_url' => null,
        'specialty' => null,
        'bio' => null,
        'show_phone_publicly' => false,
        'is_active' => true,
    ])->assertForbidden();

    expect($operator->fresh()->role)->toBe('operator');

    $customer = User::factory()->create(['role' => 'customer']);
    $this->actingAs($admin)->patch(route('team.update', $customer), [
        'role' => 'operator',
        'phone' => null,
        'avatar_url' => null,
        'specialty' => null,
        'bio' => null,
        'show_phone_publicly' => false,
        'is_active' => true,
    ])->assertSessionHasErrors('role');

    expect($customer->fresh()->role)->toBe('customer');
});

test('ticket page exposes consented technician profile chat and payment status', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $technician = User::factory()->create([
        'name' => 'Raka Wijaya',
        'role' => 'technician',
        'phone' => '628123450003',
        'specialty' => 'CCTV & jaringan',
        'show_phone_publicly' => true,
    ]);
    $order = ServiceOrder::factory()->create([
        'customer_id' => $customer->id,
        'assigned_technician_id' => $technician->id,
        'estimated_total' => 500000,
    ]);
    $order->messages()->create([
        'sender_id' => $technician->id,
        'recipient_id' => $customer->id,
        'body' => 'Saya PIC untuk tiket ini.',
    ]);
    FinancialTransaction::factory()->for($order, 'order')->create([
        'type' => 'income',
        'amount' => 250000,
        'status' => 'verified',
    ]);

    $this->actingAs($customer)->get(route('orders.show', $order))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('orders/show')
            ->where('order.technician.name', 'Raka Wijaya')
            ->where('order.technician.phone', '628123450003')
            ->where('order.messages.0.body', 'Saya PIC untuk tiket ini.')
            ->where('order.finance.verified_income', 250000)
            ->where('canChat', true));
});
