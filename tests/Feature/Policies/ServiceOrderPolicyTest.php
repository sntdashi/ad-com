<?php

use App\Models\OrderAssignment;
use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('allows administrators and supervisors to view and update every order', function (string $role) {
    $user = User::factory()->create(['role' => $role]);
    $order = ServiceOrder::factory()->create();

    expect(Gate::forUser($user)->allows('view', $order))->toBeTrue()
        ->and(Gate::forUser($user)->allows('update', $order))->toBeTrue()
        ->and(Gate::forUser($user)->allows('updateTeam', $order))->toBeTrue();
})->with(['operator' => 'operator', 'administrator' => 'admin', 'supervisor' => 'supervisor']);

it('allows a customer to view only their own order', function () {
    $customer = User::factory()->create(['role' => 'customer']);
    $ownOrder = ServiceOrder::factory()->create(['customer_id' => $customer->id]);
    $otherOrder = ServiceOrder::factory()->create();

    expect(Gate::forUser($customer)->allows('view', $ownOrder))->toBeTrue()
        ->and(Gate::forUser($customer)->allows('view', $otherOrder))->toBeFalse()
        ->and(Gate::forUser($customer)->allows('update', $ownOrder))->toBeFalse()
        ->and(Gate::forUser($customer)->allows('updateTeam', $ownOrder))->toBeFalse();
});

it('allows only the owning customer to review a completed order', function () {
    $customer = User::factory()->create();
    $otherCustomer = User::factory()->create();
    $completedOrder = ServiceOrder::factory()->for($customer, 'customer')->create(['status' => 'completed']);
    $activeOrder = ServiceOrder::factory()->for($customer, 'customer')->create(['status' => 'in_progress']);

    expect(Gate::forUser($customer)->allows('review', $completedOrder))->toBeTrue()
        ->and(Gate::forUser($customer)->allows('review', $activeOrder))->toBeFalse()
        ->and(Gate::forUser($otherCustomer)->allows('review', $completedOrder))->toBeFalse();
});

it('allows a technician to update only their assigned order', function () {
    $technician = User::factory()->create(['role' => 'technician']);
    $assignedOrder = ServiceOrder::factory()->create(['assigned_technician_id' => $technician->id]);
    $otherOrder = ServiceOrder::factory()->create();

    expect(Gate::forUser($technician)->allows('view', $assignedOrder))->toBeTrue()
        ->and(Gate::forUser($technician)->allows('update', $assignedOrder))->toBeTrue()
        ->and(Gate::forUser($technician)->allows('updateTeam', $assignedOrder))->toBeFalse()
        ->and(Gate::forUser($technician)->allows('view', $otherOrder))->toBeFalse()
        ->and(Gate::forUser($technician)->allows('update', $otherOrder))->toBeFalse();
});

it('allows active technician team members and denies released members', function () {
    $technician = User::factory()->create(['role' => 'technician']);
    $activeOrder = ServiceOrder::factory()->create();
    $releasedOrder = ServiceOrder::factory()->create();
    OrderAssignment::factory()->for($activeOrder)->for($technician)->create();
    OrderAssignment::factory()->for($releasedOrder)->for($technician)->create([
        'released_at' => now(),
    ]);

    expect(Gate::forUser($technician)->allows('view', $activeOrder))->toBeTrue()
        ->and(Gate::forUser($technician)->allows('update', $activeOrder))->toBeTrue()
        ->and(Gate::forUser($technician)->allows('view', $releasedOrder))->toBeFalse()
        ->and(Gate::forUser($technician)->allows('update', $releasedOrder))->toBeFalse();
});

it('denies inactive accounts', function () {
    $user = User::factory()->create(['role' => 'admin', 'is_active' => false]);
    $order = ServiceOrder::factory()->create();

    expect(Gate::forUser($user)->allows('viewAny', ServiceOrder::class))->toBeFalse()
        ->and(Gate::forUser($user)->allows('view', $order))->toBeFalse()
        ->and(Gate::forUser($user)->allows('update', $order))->toBeFalse()
        ->and(Gate::forUser($user)->allows('updateTeam', $order))->toBeFalse();
});
