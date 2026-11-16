<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('updates only the authenticated customer profile and ignores privileged fields', function () {
    $user = User::factory()->create();
    $other = User::factory()->create(['name' => 'Pelanggan lain']);
    $token = $user->createToken('Phone', ['mobile', 'role:customer'], now()->addDay())->plainTextToken;

    $this->withToken($token)->patchJson(route('api.v1.me.update'), [
        'name' => 'Dina Putri', 'email' => $user->email, 'username' => '@dina.putri',
        'phone' => '081234567890', 'address' => 'Jalan Pendidikan Nomor 12', 'bio' => 'Pengguna AD Com',
        'role' => 'operator', 'is_active' => false, 'id' => $other->id,
        'avatar_url' => 'https://example.com/injected.png', 'show_phone_publicly' => true,
    ])->assertOk()->assertJsonPath('data.name', 'Dina Putri')->assertJsonPath('data.role', 'customer');

    expect($user->refresh())->username->toBe('dina.putri')->phone->toBe('6281234567890')
        ->is_active->toBeTrue()->show_phone_publicly->toBeFalse()->avatar_url->toBeNull();
    expect($other->refresh()->name)->toBe('Pelanggan lain');
});

it('allows a technician to update specialty and phone consent', function () {
    $user = User::factory()->withTwoFactor()->create(['role' => 'technician']);
    $token = $user->createToken('Phone', ['mobile', 'role:technician', 'auth:2fa'], now()->addDay())->plainTextToken;

    $this->withToken($token)->patchJson(route('api.v1.me.update'), [
        'name' => $user->name, 'email' => $user->email, 'specialty' => 'Instalasi jaringan',
        'phone' => '081234567890', 'show_phone_publicly' => true,
    ])->assertOk()->assertJsonPath('data.specialty', 'Instalasi jaringan')->assertJsonPath('data.show_phone_publicly', true);
});

it('returns 422 for duplicate usernames and unsafe uploads without changing the profile', function () {
    Storage::fake('public');
    User::factory()->create(['username' => 'dipakai']);
    $user = User::factory()->create();
    $token = $user->createToken('Phone', ['mobile', 'role:customer'], now()->addDay())->plainTextToken;

    $this->withToken($token)->patchJson(route('api.v1.me.update'), [
        'name' => 'Tidak tersimpan', 'email' => $user->email, 'username' => 'dipakai',
        'avatar' => UploadedFile::fake()->create('payload.svg', 10, 'image/svg+xml'),
    ])->assertUnprocessable()->assertJsonValidationErrors(['username', 'avatar']);

    expect($user->refresh())->username->toBeNull()->avatar_path->toBeNull();
    expect(Storage::disk('public')->allFiles())->toBeEmpty();
});

it('supports multipart avatar replacement and removal using method override', function () {
    Storage::fake('public');
    $oldPath = fakePng('old.png', 256, 256)->store('profile-photos', 'public');
    $user = User::factory()->create(['avatar_path' => $oldPath, 'avatar_url' => Storage::disk('public')->url($oldPath)]);
    $token = $user->createToken('Phone', ['mobile', 'role:customer'], now()->addDay())->plainTextToken;

    $this->withToken($token)->withHeader('Accept', 'application/json')->post(route('api.v1.me.update'), [
        '_method' => 'PATCH', 'name' => $user->name, 'email' => $user->email,
        'avatar' => fakePng('new.png', 512, 512),
    ])->assertOk();

    $newPath = $user->refresh()->avatar_path;
    Storage::disk('public')->assertExists($newPath);
    Storage::disk('public')->assertMissing($oldPath);

    $this->withToken($token)->patchJson(route('api.v1.me.update'), [
        'name' => $user->name, 'email' => $user->email, 'remove_avatar' => true,
    ])->assertOk()->assertJsonPath('data.avatar_url', null);
    Storage::disk('public')->assertMissing($newPath);
});

it('requires verification again after changing the profile email', function () {
    $user = User::factory()->create();
    $token = $user->createToken('Phone', ['mobile', 'role:customer'], now()->addDay())->plainTextToken;

    $this->withToken($token)->patchJson(route('api.v1.me.update'), [
        'name' => $user->name, 'email' => 'new-address@example.test',
    ])->assertOk();

    expect($user->refresh()->email_verified_at)->toBeNull();
    expect($user->tokens()->count())->toBe(0);

    $this->withToken($token)->patchJson(route('api.v1.me.update'), [
        'name' => 'Sesi lama', 'email' => 'new-address@example.test',
    ])->assertUnauthorized();
});

it('returns 422 when account deletion password is incorrect and preserves all tokens', function () {
    $user = User::factory()->create();
    $token = $user->createToken('Phone', ['mobile', 'role:customer'], now()->addDay())->plainTextToken;

    $this->withToken($token)->deleteJson(route('api.v1.me.destroy'), ['password' => 'wrong'])
        ->assertUnprocessable()->assertJsonValidationErrors(['password'])
        ->assertJsonPath('errors.password.0', 'Kata sandi tidak sesuai.');

    $this->assertModelExists($user);
    expect($user->tokens()->count())->toBe(1);
});

it('deletes the customer account and removes every mobile token and uploaded avatar', function () {
    Storage::fake('public');
    $avatarPath = fakePng('old.png', 256, 256)->store('profile-photos', 'public');
    $user = User::factory()->create(['avatar_path' => $avatarPath]);
    $token = $user->createToken('Phone', ['mobile', 'role:customer'], now()->addDay())->plainTextToken;
    $user->createToken('Tablet', ['mobile', 'role:customer'], now()->addDay());

    $this->withToken($token)->deleteJson(route('api.v1.me.destroy'), ['password' => 'password'])->assertNoContent();

    $this->assertModelMissing($user);
    $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id, 'tokenable_type' => User::class]);
    Storage::disk('public')->assertMissing($avatarPath);
});

it('returns 403 when an operational account requests self deletion', function () {
    $user = User::factory()->withTwoFactor()->create(['role' => 'technician']);
    $token = $user->createToken('Phone', ['mobile', 'role:technician', 'auth:2fa'], now()->addDay())->plainTextToken;

    $this->withToken($token)->deleteJson(route('api.v1.me.destroy'), ['password' => 'password'])->assertForbidden();

    $this->assertModelExists($user);
});
