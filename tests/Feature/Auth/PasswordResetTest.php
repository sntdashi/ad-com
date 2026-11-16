<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::resetPasswords());
    config(['services.fonnte.token' => 'test-token']);
    Http::preventStrayRequests();
});

test('reset password link screen can be rendered', function () {
    $response = $this->get(route('password.request'));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/forgot-password'));
});

test('reset password link can be requested', function () {
    Notification::fake();
    Http::fake(['https://api.fonnte.com/send' => Http::response(['status' => true], 200)]);

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class);
});

test('every account role can request a password reset link', function (string $role) {
    Notification::fake();
    Http::fake(['https://api.fonnte.com/send' => Http::response(['status' => true], 200)]);
    $user = User::factory()->create(['role' => $role]);

    $this->post(route('password.email'), ['email' => $user->email])
        ->assertSessionHasNoErrors();

    Notification::assertSentTo($user, ResetPassword::class);
})->with([
    'customer' => 'customer',
    'technician' => 'technician',
    'supervisor' => 'supervisor',
    'warehouse' => 'warehouse',
    'admin' => 'admin',
    'operator' => 'operator',
]);

test('reset password screen can be rendered', function () {
    Notification::fake();
    Http::fake(['https://api.fonnte.com/send' => Http::response(['status' => true], 200)]);

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
        $response = $this->get(route('password.reset', [
            'token' => $notification->token,
            'email' => $user->email,
        ]));

        $response
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/reset-password')
                ->where('token', $notification->token)
                ->where('email', $user->email));

        return true;
    });
});

test('password can be reset with valid token', function () {
    Notification::fake();

    $user = User::factory()->create();
    $newPassword = 'Password-baru-123!';
    $otp = null;
    Http::fake(['https://api.fonnte.com/send' => function ($request) use (&$otp) {
        preg_match('/([0-9]{6})\./', $request['message'], $matches);
        $otp = $matches[1] ?? null;

        return Http::response(['status' => true], 200);
    }]);

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($newPassword, $otp, $user) {
        $response = $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'otp' => $otp,
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login'));
        expect(Hash::check($newPassword, $user->refresh()->password))->toBeTrue();

        return true;
    });
});

test('a valid reset link cannot change the password without the WhatsApp code', function () {
    Notification::fake();
    Http::fake(['https://api.fonnte.com/send' => Http::response(['status' => true], 200)]);
    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
        $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'Password-baru-123!',
            'password_confirmation' => 'Password-baru-123!',
        ])->assertSessionHasErrors('otp');

        expect(Hash::check('password', $user->refresh()->password))->toBeTrue();

        return true;
    });
});

test('password cannot be reset with invalid token', function () {
    $user = User::factory()->create();

    $response = $this->post(route('password.update'), [
        'token' => 'invalid-token',
        'email' => $user->email,
        'password' => 'newpassword123',
        'password_confirmation' => 'newpassword123',
    ]);

    $response->assertSessionHasErrors('email');
});
