<?php

use App\Models\User;

it('lets an internal account without OTP open the security setup page', function (string $role) {
    $user = User::factory()->create(['role' => $role]);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk();
})->with(['operator', 'admin', 'supervisor', 'technician', 'warehouse']);
