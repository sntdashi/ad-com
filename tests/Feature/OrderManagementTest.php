<?php

use App\Models\OrderAssignment;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('authorized operations staff can search tickets with service technician date and estimate', function () {
    $this->withoutVite();
    $service = Service::factory()->create(['name' => 'Instalasi CCTV']);
    $technician = User::factory()->create(['role' => 'technician', 'name' => 'Raka Mahendra']);
    $order = ServiceOrder::factory()->create([
        'service_id' => $service->id,
        'customer_name' => 'Dina Pratiwi',
        'status' => 'in_progress',
        'assigned_technician_id' => $technician->id,
        'scheduled_at' => now()->setDate(2026, 11, 15)->setTime(9, 30),
        'estimated_total' => 425000,
    ]);
    OrderAssignment::factory()->for($order)->for($technician)->create();
    $admin = User::factory()->withTwoFactor()->create(['role' => 'admin']);

    $this->actingAs($admin)->get(route('orders.index', ['search' => 'Raka Mahendra', 'status' => 'in_progress']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('operations/orders')
            ->where('filters.search', 'Raka Mahendra')
            ->where('filters.status', 'in_progress')
            ->has('orders.data', 1)
            ->where('orders.data.0.id', $order->id)
            ->where('orders.data.0.customer_name', 'Dina Pratiwi')
            ->where('orders.data.0.service', 'Instalasi CCTV')
            ->where('orders.data.0.technicians', ['Raka Mahendra'])
            ->where('orders.data.0.estimated_total', 425000)
            ->where('orders.data.0.status', 'in_progress')
            ->where('orders.data.0.scheduled_at', fn ($date): bool => str_contains((string) $date, '09.30')));
});

test('customers cannot open the full operations ticket list', function () {
    $this->withoutVite();
    $customer = User::factory()->create();

    $this->actingAs($customer)->get(route('orders.index'))->assertForbidden();
});

test('operations queue can be filtered by dashboard group', function () {
    $this->withoutVite();
    $service = Service::factory()->create();
    $waiting = ServiceOrder::factory()->create(['service_id' => $service->id, 'status' => 'new']);
    ServiceOrder::factory()->create(['service_id' => $service->id, 'status' => 'completed']);
    $operator = User::factory()->withTwoFactor()->create(['role' => 'operator']);

    $this->actingAs($operator)->get(route('orders.index', ['group' => 'waiting']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.group', 'waiting')
            ->where('filters.group_label', 'Butuh aksi')
            ->has('orders.data', 1)
            ->where('orders.data.0.id', $waiting->id));

    $this->actingAs($operator)->get(route('orders.index', ['group' => 'bogus']))
        ->assertSessionHasErrors('group');
});
