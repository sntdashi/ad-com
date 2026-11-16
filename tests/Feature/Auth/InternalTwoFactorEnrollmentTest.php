<?php

use App\Models\User;
use PragmaRX\Google2FA\Google2FA;

it('lets an operator enroll OTP and then reach every operator control', function () {
    $this->withoutVite();
    $operator = User::factory()->create(['role' => 'operator']);

    $this->actingAs($operator)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('two-factor.enable'))
        ->assertRedirect();

    $this->actingAs($operator->refresh())
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk();

    $operator->refresh();
    expect($operator->two_factor_secret)->not->toBeNull()
        ->and($operator->two_factor_confirmed_at)->toBeNull();

    $code = app(Google2FA::class)->getCurrentOtp(decrypt($operator->two_factor_secret));

    $this->actingAs($operator)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('two-factor.confirm'), ['code' => $code])
        ->assertSessionHasNoErrors();

    expect($operator->refresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();

    foreach (['dashboard', 'orders.index', 'inventory.index', 'finance.index', 'team.index', 'technicians.performance', 'admin.control-center', 'assistant-settings.edit'] as $routeName) {
        $this->actingAs($operator)->get(route($routeName))->assertOk();
    }
});
