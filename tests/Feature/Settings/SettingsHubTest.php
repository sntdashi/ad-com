<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('redirects guests to login', function () {
    $this->get(route('settings.index'))
        ->assertRedirect(route('login'));
});

it('renders the settings hub for every account role', function (string $role) {
    $user = User::factory()->create(['role' => $role]);

    $this->actingAs($user)
        ->get(route('settings.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/index')
            ->where('auth.user.role', $role));
})->with([
    'customer' => 'customer',
    'technician' => 'technician',
    'supervisor' => 'supervisor',
    'warehouse' => 'warehouse',
    'admin' => 'admin',
    'operator' => 'operator',
]);
