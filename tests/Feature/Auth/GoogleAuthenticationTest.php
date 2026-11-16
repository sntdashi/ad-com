<?php

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function () {
    config(['services.fonnte.token' => 'test-token']);
    Http::preventStrayRequests();
    config([
        'services.google' => [
            'client_id' => 'google-client-id',
            'client_secret' => 'google-client-secret',
            'redirect' => 'http://localhost/auth/google/callback',
        ],
    ]);
});

test('users can be redirected to Google', function () {
    Socialite::fake('google');

    $response = $this->get(route('auth.google.redirect'));

    $response->assertRedirect('https://socialite.fake/google/authorize');
});

test('Google authorization uses the registered absolute callback URI', function () {
    $response = $this->get(route('auth.google.redirect'));

    $response->assertRedirect();

    $location = $response->headers->get('Location');
    parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);

    expect($query)
        ->toHaveKey('redirect_uri')
        ->and($query['redirect_uri'])->toBe('http://localhost/auth/google/callback');
});

test('Google login reports missing application credentials', function () {
    config(['services.google.client_secret' => null]);

    $response = $this->get(route('auth.google.redirect'));

    $response->assertRedirect(route('login'));
    $response->assertSessionHas(
        'google_error',
        'Masuk dengan Google belum dikonfigurasi. Hubungi pengelola AD Com.',
    );
    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page
        ->component('auth/login')
        ->where('googleError', 'Masuk dengan Google belum dikonfigurasi. Hubungi pengelola AD Com.')
    );
});

test('a new Google customer must add a WhatsApp number before login', function () {
    Socialite::fake('google', SocialiteUser::fake([
        'id' => 'google-user-123',
        'name' => 'Siswa TKJ',
        'email' => 'Siswa.TKJ@example.com',
        'email_verified' => true,
        'avatar' => 'https://example.com/siswa.jpg',
    ]));

    $response = $this->get(route('auth.google.callback'));

    $user = User::query()->where('email', 'siswa.tkj@example.com')->firstOrFail();

    $this->assertAuthenticatedAs($user);
    $response->assertRedirect(route('profile.edit'));
    $response->assertSessionHas('auth.google_phone_setup', true);
    expect($user)
        ->email_verified_at->not->toBeNull()
        ->role->toBe('customer')
        ->is_active->toBeTrue();
    $this->assertDatabaseHas('social_accounts', [
        'user_id' => $user->id,
        'provider' => SocialAccount::PROVIDER_GOOGLE,
        'provider_user_id' => 'google-user-123',
        'provider_email' => 'siswa.tkj@example.com',
    ]);
});

test('Google login links an existing user by verified email without duplicating it', function () {
    Http::fake(['https://api.fonnte.com/send' => Http::response(['status' => true], 200)]);
    $user = User::factory()->unverified()->create([
        'email' => 'Guru.TKJ@example.com',
    ]);

    Socialite::fake('google', SocialiteUser::fake([
        'id' => 'google-user-456',
        'name' => 'Nama dari Google',
        'email' => 'guru.tkj@example.com',
        'email_verified' => true,
    ]));

    $response = $this->get(route('auth.google.callback'));

    $this->assertAuthenticatedAs($user);
    $response->assertRedirect(route('dashboard', absolute: false));
    Http::assertNothingSent();
    expect(User::query()->count())->toBe(1)
        ->and($user->refresh()->hasVerifiedEmail())->toBeTrue();
    $this->assertDatabaseHas('social_accounts', [
        'user_id' => $user->id,
        'provider_user_id' => 'google-user-456',
    ]);
});

test('an existing Google identity signs in its linked user', function () {
    Http::fake(['https://api.fonnte.com/send' => Http::response(['status' => true], 200)]);
    $user = User::factory()->create();
    SocialAccount::factory()->for($user)->create([
        'provider_user_id' => 'google-user-linked',
        'provider_email' => $user->email,
    ]);

    Socialite::fake('google', SocialiteUser::fake([
        'id' => 'google-user-linked',
        'name' => 'Linked User',
        'email' => 'new-google-email@example.com',
        'email_verified' => true,
    ]));

    $response = $this->get(route('auth.google.callback'));

    $this->assertAuthenticatedAs($user);
    $response->assertRedirect(route('dashboard', absolute: false));
    Http::assertNothingSent();
    expect($user->refresh()->email)->not->toBe('new-google-email@example.com');
    $this->assertDatabaseHas('social_accounts', [
        'user_id' => $user->id,
        'provider_email' => 'new-google-email@example.com',
    ]);
});

test('Google login requires Fortify TOTP for a protected account without sending WhatsApp OTP', function () {
    Http::fake(['https://api.fonnte.com/send' => Http::response(['status' => true], 200)]);
    $user = User::factory()->withTwoFactor()->create(['role' => 'technician']);
    SocialAccount::factory()->for($user)->create([
        'provider_user_id' => 'google-user-with-otp',
    ]);
    Socialite::fake('google', SocialiteUser::fake([
        'id' => 'google-user-with-otp',
        'email' => $user->email,
        'email_verified' => true,
    ]));

    $response = $this->get(route('auth.google.callback'));

    $this->assertGuest();
    $response->assertRedirect(route('two-factor.login'));
    $response->assertSessionHas('login.id', $user->id);
    Http::assertNothingSent();
});

test('a disabled account cannot sign in with its linked Google identity', function () {
    $user = User::factory()->create(['is_active' => false]);
    SocialAccount::factory()->for($user)->create([
        'provider_user_id' => 'google-user-disabled',
    ]);

    Socialite::fake('google', SocialiteUser::fake([
        'id' => 'google-user-disabled',
        'email' => $user->email,
        'email_verified' => true,
    ]));

    $response = $this->get(route('auth.google.callback'));

    $this->assertGuest();
    $response->assertRedirect(route('login'));
    $response->assertSessionHas('google_error');
});

test('an existing Google link cannot be replaced by another Google identity', function () {
    $user = User::factory()->create(['email' => 'supervisor@example.com']);
    SocialAccount::factory()->for($user)->create([
        'provider_user_id' => 'original-google-user',
    ]);

    Socialite::fake('google', SocialiteUser::fake([
        'id' => 'different-google-user',
        'email' => 'supervisor@example.com',
        'email_verified' => true,
    ]));

    $response = $this->get(route('auth.google.callback'));

    $this->assertGuest();
    $response->assertRedirect(route('login'));
    $response->assertSessionHas('google_error');
    $this->assertDatabaseMissing('social_accounts', [
        'provider_user_id' => 'different-google-user',
    ]);
});

test('Google identities without a valid verified email are rejected', function (?string $email, bool $isVerified) {
    Socialite::fake('google', SocialiteUser::fake([
        'id' => 'invalid-google-user',
        'email' => $email,
        'email_verified' => $isVerified,
    ]));

    $response = $this->get(route('auth.google.callback'));

    $this->assertGuest();
    $response->assertRedirect(route('login'));
    $response->assertSessionHas('google_error');
    expect(User::query()->count())->toBe(0);
})->with([
    'unverified email' => ['unverified@example.com', false],
    'missing email' => [null, true],
]);

test('a denied Google grant returns a useful login error', function () {
    Socialite::fake('google', function () {
        throw new RuntimeException('access_denied');
    });

    $response = $this->get(route('auth.google.callback'));

    $this->assertGuest();
    $response->assertRedirect(route('login'));
    $response->assertSessionHas('google_error');
});
