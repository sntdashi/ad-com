<?php

use App\Models\User;
use App\Notifications\MobileVerifyEmail;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\PersonalAccessToken;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    Http::preventStrayRequests();
});

function mobileAuthenticatorCode(User $user): string
{
    return (new Google2FA)->getCurrentOtp(decrypt($user->two_factor_secret));
}

it('issues a scoped customer bearer token directly without WhatsApp OTP', function () {
    $this->freezeTime();
    $user = User::factory()->create();

    $response = $this->postJson(route('api.v1.auth.login'), ['email' => strtoupper($user->email), 'password' => 'password', 'device_name' => 'Android'])
        ->assertOk()->assertJsonPath('user.id', $user->id)->assertJsonMissingPath('user.password');
    $token = PersonalAccessToken::findToken($response->json('token'));

    expect($token->abilities)->toBe(['mobile', 'role:customer']);
    expect($token->expires_at->getTimestamp())->toBe(now()->addDays(30)->getTimestamp());
    $this->withToken($response->json('token'))->getJson(route('api.v1.me'))->assertOk()->assertJsonPath('data.id', $user->id);
    Http::assertNothingSent();
});

it('returns 422 for incorrect passwords and inactive accounts without issuing tokens', function (bool $active) {
    $user = User::factory()->create(['is_active' => $active]);

    $this->postJson(route('api.v1.auth.login'), ['email' => $user->email, 'password' => $active ? 'wrong' : 'password', 'device_name' => 'Android'])
        ->assertUnprocessable()->assertJsonValidationErrors('email');
    expect($user->tokens()->count())->toBe(0);
})->with([true, false]);

it('returns 403 for roles restricted to the web dashboard', function (string $role) {
    $user = User::factory()->withTwoFactor()->create(['role' => $role]);

    $this->postJson(route('api.v1.auth.login'), ['email' => $user->email, 'password' => 'password', 'device_name' => 'Android'])
        ->assertForbidden()->assertJsonPath('code', 'mobile_role_not_supported');
    expect($user->tokens()->count())->toBe(0);
})->with(['operator', 'admin', 'supervisor', 'warehouse']);

it('returns 403 for technicians without confirmed authenticator setup', function () {
    $user = User::factory()->create(['role' => 'technician']);

    $this->postJson(route('api.v1.auth.login'), ['email' => $user->email, 'password' => 'password', 'device_name' => 'Android'])
        ->assertForbidden()->assertJsonPath('code', 'two_factor_setup_required');
    expect($user->tokens()->count())->toBe(0);
});

it('requires the technician authenticator code before issuing a mobile token', function () {
    $user = User::factory()->withTwoFactor()->create([
        'role' => 'technician',
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
    ]);
    $login = $this->postJson(route('api.v1.auth.login'), ['email' => $user->email, 'password' => 'password', 'device_name' => 'Android'])
        ->assertAccepted()->assertJsonPath('method', 'authenticator')->assertJsonMissingPath('token');
    expect($user->tokens()->count())->toBe(0);
    $input = ['challenge_token' => $login->json('challenge_token'), 'code' => mobileAuthenticatorCode($user)];

    $response = $this->postJson(route('api.v1.auth.two-factor'), $input)->assertOk();

    expect(PersonalAccessToken::findToken($response->json('token'))->abilities)->toContain('auth:2fa');
    $this->postJson(route('api.v1.auth.two-factor'), $input)->assertUnprocessable()->assertJsonValidationErrors('challenge_token');
    Http::assertNothingSent();
});

it('uses the authenticator second factor for technician login', function () {
    $user = User::factory()->withTwoFactor()->create([
        'role' => 'technician',
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
    ]);
    $old = $user->createToken('Old', ['mobile', 'role:technician', 'auth:2fa'], now()->addDay())->plainTextToken;
    $login = $this->postJson(route('api.v1.auth.login'), ['email' => $user->email, 'password' => 'password', 'device_name' => 'Android']);

    $response = $this->postJson(route('api.v1.auth.two-factor'), [
        'challenge_token' => $login->json('challenge_token'), 'code' => mobileAuthenticatorCode($user),
    ])->assertOk();

    expect(PersonalAccessToken::findToken($response->json('token'))->abilities)->toContain('auth:2fa');
    expect(PersonalAccessToken::findToken($old))->not->toBeNull();
});

it('expires challenges and rejects challenges after security changes', function (string $change) {
    $this->freezeTime();
    $user = User::factory()->withTwoFactor()->create([
        'role' => 'technician',
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
    ]);
    $login = $this->postJson(route('api.v1.auth.login'), ['email' => $user->email, 'password' => 'password', 'device_name' => 'Android']);
    if ($change === 'time') {
        $this->travel(6)->minutes();
    } else {
        $user->update(['password' => 'a-new-password']);
    }

    $this->postJson(route('api.v1.auth.two-factor'), ['challenge_token' => $login->json('challenge_token'), 'code' => '123456'])
        ->assertUnprocessable()->assertJsonValidationErrors('challenge_token');
    expect($user->tokens()->count())->toBe(0);
})->with(['time', 'password']);

it('limits failed challenge attempts without accepting a valid code afterwards', function () {
    $user = User::factory()->withTwoFactor()->create([
        'role' => 'technician',
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
    ]);
    $login = $this->postJson(route('api.v1.auth.login'), ['email' => $user->email, 'password' => 'password', 'device_name' => 'Android']);
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson(route('api.v1.auth.two-factor'), ['challenge_token' => $login->json('challenge_token'), 'code' => '000000'])->assertUnprocessable();
    }
    $this->travel(61)->seconds();

    $this->postJson(route('api.v1.auth.two-factor'), ['challenge_token' => $login->json('challenge_token'), 'code' => mobileAuthenticatorCode($user)])
        ->assertUnprocessable()->assertJsonValidationErrors('challenge_token');
    expect($user->tokens()->count())->toBe(0);
});

it('does not accept web sessions or wildcard tokens as mobile authentication', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->getJson(route('api.v1.me'))->assertUnauthorized();
    $token = $user->createToken('Other client', ['*'], now()->addDay())->plainTextToken;

    $this->withToken($token)->getJson(route('api.v1.me'))->assertForbidden();
});

it('returns 401 for expired tokens and revokes a token on logout', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    $expired = $user->createToken('Expired', ['mobile', 'role:customer'], now()->subMinute())->plainTextToken;
    $valid = $user->createToken('Valid', ['mobile', 'role:customer'], now()->addDay())->plainTextToken;
    $this->withToken($expired)->getJson(route('api.v1.me'))->assertUnauthorized();

    $this->withToken($valid)->postJson(route('api.v1.auth.logout'))->assertNoContent();

    $this->withToken($valid)->getJson(route('api.v1.me'))->assertUnauthorized();
});

it('registers only customers and sends a signed verification email', function () {
    Notification::fake();
    $response = $this->postJson(route('api.v1.auth.register'), [
        'name' => 'Dina Putri', 'email' => 'dina@example.test', 'phone' => '081234567890', 'password' => 'password123',
        'password_confirmation' => 'password123', 'device_name' => 'Android',
    ])->assertCreated()->assertJsonPath('user.role', 'customer')->assertJsonPath('user.email_verified_at', null)->assertJsonPath('token_type', 'Bearer');
    $user = User::findOrFail($response->json('user.id'));
    Notification::assertSentTo($user, MobileVerifyEmail::class);

    $this->withToken($response->json('token'))->getJson(route('api.v1.orders.index'))->assertForbidden();
    $url = (new MobileVerifyEmail)->toMail($user)->actionUrl;
    $this->get($url)->assertOk();
    expect($user->refresh()->hasVerifiedEmail())->toBeTrue();
});

it('rejects registration privilege injection without creating an account', function () {
    Notification::fake();
    $this->postJson(route('api.v1.auth.register'), [
        'name' => 'Dina Putri', 'email' => 'dina@example.test', 'phone' => '081234567890', 'password' => 'password123',
        'password_confirmation' => 'password123', 'device_name' => 'Android', 'role' => 'operator',
    ])->assertUnprocessable()->assertJsonValidationErrors('role');
    $this->assertDatabaseMissing('users', ['email' => 'dina@example.test']);
    Notification::assertNothingSent();
});

it('rejects expired and tampered verification links', function () {
    $user = User::factory()->unverified()->create();
    $expired = URL::temporarySignedRoute('api.v1.auth.verification.verify', now()->subMinute(), ['id' => $user->id, 'hash' => sha1($user->email)]);
    $wrongHash = URL::temporarySignedRoute('api.v1.auth.verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1('wrong@example.test')]);

    $this->get($expired)->assertForbidden();
    $this->get($wrongHash)->assertForbidden();
    expect($user->refresh()->hasVerifiedEmail())->toBeFalse();
});

it('returns the same recovery message for registered and unknown addresses', function () {
    Notification::fake();
    config(['services.fonnte.token' => 'test-token']);
    Http::fake(['https://api.fonnte.com/send' => Http::response(['status' => true], 200)]);
    $user = User::factory()->create();
    $known = $this->postJson(route('api.v1.auth.forgot-password'), ['email' => $user->email])->assertOk();
    $unknown = $this->postJson(route('api.v1.auth.forgot-password'), ['email' => 'unknown@example.test'])->assertOk();

    expect($known->json())->toBe($unknown->json());
    Notification::assertSentTo($user, ResetPassword::class);
});

it('returns 429 after repeated failed password attempts', function () {
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson(route('api.v1.auth.login'), ['email' => 'unknown@example.test', 'password' => 'wrong', 'device_name' => 'Android'])->assertUnprocessable();
    }

    $this->postJson(route('api.v1.auth.login'), ['email' => 'unknown@example.test', 'password' => 'wrong', 'device_name' => 'Android'])->assertTooManyRequests();
});
