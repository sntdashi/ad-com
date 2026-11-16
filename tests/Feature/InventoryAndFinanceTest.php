<?php

use App\Models\FinancialTransaction;
use App\Models\InventoryItem;
use App\Models\User;

test('warehouse can record stock movements and prevents insufficient stock out', function () {
    $warehouse = User::factory()->create(['role' => 'warehouse']);
    $technician = User::factory()->create(['role' => 'technician']);

    $item = InventoryItem::create([
        'sku' => 'SKU-TEST-01',
        'name' => 'Kabel UTP Cat6',
        'category' => 'Jaringan',
        'unit' => 'meter',
        'quantity_on_hand' => 10,
        'reorder_level' => 5,
        'unit_cost' => 15000,
        'storage_location' => 'Rak A1',
        'is_active' => true,
    ]);

    $this->actingAs($technician)
        ->post(route('inventory.movements.store'), [
            'inventory_item_id' => $item->id,
            'type' => 'stock_out',
            'quantity' => 2,
        ])
        ->assertForbidden();

    $this->actingAs($warehouse)
        ->post(route('inventory.movements.store'), [
            'inventory_item_id' => $item->id,
            'type' => 'stock_out',
            'quantity' => 20,
        ])
        ->assertSessionHasErrors('quantity');

    expect($item->fresh()->quantity_on_hand)->toBe(10);

    $this->actingAs($warehouse)
        ->post(route('inventory.movements.store'), [
            'inventory_item_id' => $item->id,
            'type' => 'stock_out',
            'quantity' => 6,
            'notes' => 'Dipakai instalasi',
        ])
        ->assertRedirect();

    expect($item->fresh()->quantity_on_hand)->toBe(4);

    $this->actingAs($warehouse)
        ->get(route('inventory.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.low_stock', 1)
            ->where('items.0.is_low_stock', true)
        );
});

test('admin records financial transaction and operator verifies it', function () {
    $operator = User::factory()->create(['role' => 'operator']);
    $admin = User::factory()->create(['role' => 'admin']);
    $customer = User::factory()->create(['role' => 'customer']);

    $this->actingAs($customer)
        ->post(route('finance.store'), [
            'type' => 'income',
            'category' => 'service_payment',
            'amount' => 150000,
            'payment_method' => 'bank_transfer',
            'occurred_at' => now()->toDateTimeString(),
        ])
        ->assertForbidden();

    $this->actingAs($admin)
        ->post(route('finance.store'), [
            'type' => 'income',
            'category' => 'service_payment',
            'amount' => 250000,
            'payment_method' => 'qr',
            'description' => 'Pembayaran servis',
            'occurred_at' => now()->toDateTimeString(),
        ])
        ->assertRedirect();

    $transaction = FinancialTransaction::query()->latest('id')->firstOrFail();
    expect($transaction->status)->toBe('pending');

    $this->actingAs($operator)
        ->patch(route('finance.transactions.verify', $transaction), [
            'status' => 'verified',
        ])
        ->assertRedirect();

    expect($transaction->fresh()->status)->toBe('verified');
});
