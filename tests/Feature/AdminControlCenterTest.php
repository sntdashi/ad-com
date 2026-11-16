<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('shows management signals and activity to administrators', function () {
    $this->withoutVite();
    $admin = User::factory()->withTwoFactor()->create(['role' => 'admin']);

    $this->actingAs($admin)->get(route('admin.control-center'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/admin-control-center')
            ->has('signals.open_orders')
            ->has('signals.pending_transactions')
            ->has('roleCounts.admin')
            ->where('assistant.provider', 'OpenAI')
        );
});

it('does not allow customers to open the admin control center', function () {
    $customer = User::factory()->create();

    $this->actingAs($customer)->get(route('admin.control-center'))->assertForbidden();
});
