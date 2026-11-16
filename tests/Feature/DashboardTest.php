<?php

use App\Models\OrderAssignment;
use App\Models\ServiceOrder;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('technician dashboard includes orders assigned through the active team', function () {
    $pic = User::factory()->create(['name' => 'Raka Wijaya', 'role' => 'technician']);
    $member = User::factory()->withTwoFactor()->create(['name' => 'Nabila Putri', 'role' => 'technician']);
    $order = ServiceOrder::factory()->create([
        'assigned_technician_id' => $pic->id,
        'status' => 'scheduled',
    ]);
    OrderAssignment::factory()->for($order)->for($pic)->create();
    OrderAssignment::factory()->for($order)->for($member)->create([
        'assigned_at' => now()->addMinute(),
    ]);

    $this->actingAs($member)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->has('orders', 1)
            ->where('orders.0.id', $order->id)
            ->where('orders.0.technician_team', ['Raka Wijaya', 'Nabila Putri']));
});

test('admin dashboard shows total active and available technician counts with ticket details', function () {
    $freeTechnician = User::factory()->create(['role' => 'technician', 'name' => 'Teknisi Bebas']);
    $teamTechnician = User::factory()->create(['role' => 'technician', 'name' => 'Teknisi Tim']);
    $leadTechnician = User::factory()->create(['role' => 'technician', 'name' => 'Teknisi PIC']);
    User::factory()->create(['role' => 'technician', 'is_active' => false]);

    $teamOrder = ServiceOrder::factory()->create([
        'status' => 'in_progress',
        'created_at' => now()->subMinute(),
    ]);
    OrderAssignment::factory()->for($teamOrder)->for($teamTechnician)->create();
    $leadOrder = ServiceOrder::factory()->create([
        'status' => 'scheduled',
        'assigned_technician_id' => $leadTechnician->id,
        'scheduled_at' => now()->addDay(),
        'estimated_total' => 275000,
    ]);

    $admin = User::factory()->withTwoFactor()->create(['role' => 'admin']);

    $this->actingAs($admin)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('signals.4.label', 'Total teknisi')
            ->where('signals.4.value', 4)
            ->where('signals.5.label', 'Teknisi aktif')
            ->where('signals.5.value', 3)
            ->where('signals.6.label', 'Teknisi tersedia')
            ->where('signals.6.value', 1)
            ->where('orders.0.id', $leadOrder->id)
            ->where('orders.0.estimated_total', 275000)
            ->where('orders.0.technician_team', ['Teknisi PIC'])
            ->where('orders.1.id', $teamOrder->id)
            ->where('orders.1.technician_team', ['Teknisi Tim']));
});
