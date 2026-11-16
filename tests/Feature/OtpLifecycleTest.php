<?php

use App\Models\LoginOtpChallenge;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\PasswordOtpService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

function mockFonnteSuccess(): void
{
    config()->set('services.fonnte.token', 'test-fonnte-token');
    Http::fake([
        'https://api.fonnte.com/send' => Http::response(['status' => true], 200),
    ]);
}

test('superadmin can configure global otp policy and non-superadmin is forbidden', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $operator = User::factory()->create(['role' => 'operator']);

    $this->actingAs($admin)
        ->put(route('system-settings.update'), [
            'business_name' => 'AD Com',
            'business_tagline' => 'Tagline',
            'warranty_default_days' => 14,
            'otp_length' => 8,
        ])
        ->assertForbidden();

    $this->actingAs($operator)
        ->put(route('system-settings.update'), [
            'business_name' => 'AD Com',
            'business_tagline' => 'Tagline',
            'warranty_default_days' => 14,
            'otp_length' => 6,
            'otp_expiration_minutes' => 10,
            'otp_resend_cooldown_seconds' => 45,
            'otp_max_attempts' => 4,
            'otp_max_resends' => 2,
            'otp_lockout_minutes' => 20,
            'otp_rate_limit_max' => 5,
            'otp_purpose_registration_phone_enabled' => true,
            'otp_purpose_password_reset_enabled' => true,
            'otp_purpose_password_change_enabled' => true,
            'otp_purpose_sensitive_action_enabled' => true,
            'otp_purpose_phone_change_enabled' => true,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('system_settings', ['key' => 'otp_max_attempts', 'value' => '4']);
    $this->assertDatabaseHas('system_settings', ['key' => 'otp_lockout_minutes', 'value' => '20']);
});

test('otp lifecycle supports pre-registration without user_id, valid verification, and prevents reuse and purpose mismatch', function () {
    mockFonnteSuccess();
    SystemSetting::putMany(['otp_purpose_registration_phone_enabled' => '1']);

    $issueResponse = $this->postJson(route('otp.issue'), [
        'purpose' => 'registration_phone',
        'phone' => '081234567890',
    ])->assertOk();

    $challengeId = $issueResponse->json('challenge.challenge_id');
    $challenge = LoginOtpChallenge::query()->where('challenge_id', $challengeId)->firstOrFail();
    expect($challenge->user_id)->toBeNull();
    expect($challenge->status)->toBe('pending');

    // Force known hash to test exact code verification
    $challenge->update(['code_hash' => Hash::make('654321')]);

    // Purpose mismatch fails
    $this->postJson(route('otp.verify', $challengeId), [
        'purpose' => 'password_change',
        'code' => '654321',
    ])->assertStatus(422);

    // Valid verify succeeds
    $this->postJson(route('otp.verify', $challengeId), [
        'purpose' => 'registration_phone',
        'code' => '654321',
    ])->assertOk()->assertJsonPath('verified', true);

    expect($challenge->fresh()->status)->toBe('verified');

    // Reuse fails (also protects concurrent verification)
    $this->postJson(route('otp.verify', $challengeId), [
        'purpose' => 'registration_phone',
        'code' => '654321',
    ])->assertStatus(422);

    // Registration succeeds with verified challenge_id
    $this->post(route('register.store'), [
        'name' => 'Pelanggan Baru',
        'email' => 'baru@adcom.test',
        'phone' => '081234567890',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'otp_challenge_id' => $challengeId,
    ])->assertRedirect();

    $this->assertDatabaseHas('users', ['email' => 'baru@adcom.test']);
});

test('wrong code increments attempts, triggers lockout, and expired otp fails', function () {
    mockFonnteSuccess();
    SystemSetting::putMany([
        'otp_max_attempts' => '3',
        'otp_lockout_minutes' => '15',
    ]);

    $user = User::factory()->create(['phone' => '6281234567801']);
    $service = app(PasswordOtpService::class);
    $payload = $service->issueChallenge($user, 'sensitive_action', null, '127.0.0.1');
    $challenge = LoginOtpChallenge::query()->where('challenge_id', $payload['challenge_id'])->firstOrFail();
    $challenge->update(['code_hash' => Hash::make('111222')]);

    for ($i = 1; $i <= 3; $i++) {
        expect(fn () => $service->verifyChallenge($payload['challenge_id'], 'sensitive_action', '999999', $user))
            ->toThrow(ValidationException::class);
    }

    $locked = $challenge->fresh();
    expect($locked->attempts)->toBe(3);
    expect($locked->status)->toBe('locked');
    expect($locked->locked_until)->not->toBeNull();

    // Even correct code fails while locked
    expect(fn () => $service->verifyChallenge($payload['challenge_id'], 'sensitive_action', '111222', $user))
        ->toThrow(ValidationException::class);

    // Expired challenge fails
    $expiredPayload = $service->issueChallenge(
        User::factory()->create(['phone' => '6281234567802']),
        'phone_change',
        null,
        '127.0.0.2',
    );
    LoginOtpChallenge::query()
        ->where('challenge_id', $expiredPayload['challenge_id'])
        ->update(['expires_at' => now()->subMinute()]);

    expect(fn () => $service->verifyChallenge($expiredPayload['challenge_id'], 'phone_change', '123456'))
        ->toThrow(ValidationException::class);
});

test('resend enforces cooldown, max resends, invalidates old code, and handles provider failure', function () {
    mockFonnteSuccess();
    SystemSetting::putMany([
        'otp_resend_cooldown_seconds' => '60',
        'otp_max_resends' => '2',
    ]);

    $user = User::factory()->create(['phone' => '6281234567803']);
    $service = app(PasswordOtpService::class);
    $payload = $service->issueChallenge($user, 'password_change', null, '127.0.0.3');

    // Immediate resend blocked by cooldown
    expect(fn () => $service->resendChallenge($payload['challenge_id'], $user, '127.0.0.3'))
        ->toThrow(ValidationException::class);

    // Allow resend after cooldown passes
    $challenge = LoginOtpChallenge::query()->where('challenge_id', $payload['challenge_id'])->firstOrFail();
    $challenge->update([
        'code_hash' => Hash::make('000111'),
        'resend_available_at' => now()->subSecond(),
    ]);

    $resent = $service->resendChallenge($payload['challenge_id'], $user, '127.0.0.3');
    expect($resent['resends_remaining'])->toBe(1);

    // Old code 000111 is now invalid
    expect(fn () => $service->verifyChallenge($payload['challenge_id'], 'password_change', '000111', $user))
        ->toThrow(ValidationException::class);

    // Provider failure does not pretend resend succeeded
    config()->set('services.fonnte.token', '');
    LoginOtpChallenge::query()->where('challenge_id', $payload['challenge_id'])->update([
        'resend_available_at' => now()->subSecond(),
    ]);

    expect(fn () => $service->resendChallenge($payload['challenge_id'], $user, '127.0.0.3'))
        ->toThrow(ValidationException::class);
});

test('rate limiting blocks excessive otp requests per target', function () {
    mockFonnteSuccess();
    SystemSetting::putMany([
        'otp_rate_limit_max' => '2',
        'otp_resend_cooldown_seconds' => '10',
    ]);

    $service = app(PasswordOtpService::class);
    $user = User::factory()->create(['phone' => '6281234567809']);

    $service->issueChallenge($user, 'password_change', null, '10.0.0.1');
    LoginOtpChallenge::query()->where('user_id', $user->id)->update(['resend_available_at' => now()->subSecond()]);

    $service->issueChallenge($user, 'password_change', null, '10.0.0.1');
    LoginOtpChallenge::query()->where('user_id', $user->id)->update(['resend_available_at' => now()->subSecond()]);

    expect(fn () => $service->issueChallenge($user, 'password_change', null, '10.0.0.1'))
        ->toThrow(ValidationException::class);
});

test('phone change requires verified phone_change otp challenge when fonnte is configured and allows update after verification', function () {
    mockFonnteSuccess();
    SystemSetting::putMany(['otp_purpose_phone_change_enabled' => '1']);

    $user = User::factory()->create(['phone' => '6281234567000']);

    // Unverified phone change is rejected
    $this->actingAs($user)
        ->from(route('profile.edit'))
        ->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'phone' => '081234567999',
        ])
        ->assertSessionHasErrors('phone');

    expect($user->fresh()->phone)->toBe('6281234567000');

    // Issue and verify phone_change OTP challenge for the new number
    $issueResponse = $this->actingAs($user)->postJson(route('otp.issue'), [
        'purpose' => 'phone_change',
        'phone' => '081234567999',
    ])->assertOk();

    $challengeId = $issueResponse->json('challenge.challenge_id');
    LoginOtpChallenge::query()
        ->where('challenge_id', $challengeId)
        ->update(['code_hash' => Hash::make('778899')]);

    $this->actingAs($user)->postJson(route('otp.verify', $challengeId), [
        'purpose' => 'phone_change',
        'code' => '778899',
    ])->assertOk()->assertJsonPath('verified', true);

    // Profile update now succeeds with verified otp_challenge_id
    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'phone' => '081234567999',
            'otp_challenge_id' => $challengeId,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->fresh()->phone)->toBe('6281234567999');
});
