<?php

use App\Models\User;

it('allows internal accounts to enter the workspace without authenticator OTP', function (string $role) {
    $user = User::factory()->create(['role' => $role]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk();
})->with([
    'operator' => 'operator',
    'admin' => 'admin',
    'supervisor' => 'supervisor',
    'technician' => 'technician',
    'warehouse' => 'warehouse',
]);

it('allows customers to use the app without OTP', function () {
    $customer = User::factory()->create();

    $this->actingAs($customer)
        ->get(route('dashboard'))
        ->assertOk();
});

it('allows an internal account with confirmed OTP to use the workspace', function () {
    $operator = User::factory()->withTwoFactor()->create(['role' => 'operator']);

    $this->actingAs($operator)
        ->get(route('dashboard'))
        ->assertOk();
});

it('prevents an internal account from disabling mandatory OTP', function () {
    $operator = User::factory()->withTwoFactor()->create(['role' => 'operator']);

    $response = $this->actingAs($operator)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->from(route('security.edit'))
        ->delete(route('two-factor.disable'));

    $response
        ->assertRedirect(route('security.edit'))
        ->assertSessionHasErrors('two_factor');
    expect($operator->refresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();
});
