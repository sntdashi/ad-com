<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('profile.edit'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('settings/profile')
        ->where('profileCompletion', 43));
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->name)->toBe('Test User');
    expect($user->email)->toBe('test@example.com');
    expect($user->email_verified_at)->toBeNull();
});

test('user can update their social profile fields and upload an avatar', function () {
    Storage::fake('public');
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'username' => '@test.user',
            'email' => $user->email,
            'phone' => '081234567890',
            'address' => 'Jalan Pendidikan Nomor 10, Kota Bandung',
            'bio' => 'Pelanggan layanan AD Com.',
            'avatar' => fakePng('avatar.png', 512, 512),
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();
    expect($user)
        ->username->toBe('test.user')
        ->phone->toBe('6281234567890')
        ->address->toBe('Jalan Pendidikan Nomor 10, Kota Bandung')
        ->bio->toBe('Pelanggan layanan AD Com.')
        ->avatar_path->not->toBeNull();
    Storage::disk('public')->assertExists($user->avatar_path);
});

test('user can remove their uploaded avatar', function () {
    Storage::fake('public');
    $avatarPath = fakePng('old.png', 256, 256)->store('profile-photos', 'public');
    $user = User::factory()->create([
        'avatar_path' => $avatarPath,
        'avatar_url' => Storage::disk('public')->url($avatarPath),
    ]);

    $response = $this->actingAs($user)->patch(route('profile.update'), [
        'name' => $user->name,
        'email' => $user->email,
        'remove_avatar' => true,
    ]);

    $response->assertSessionHasNoErrors();
    expect($user->refresh())
        ->avatar_path->toBeNull()
        ->avatar_url->toBeNull();
    Storage::disk('public')->assertMissing($avatarPath);
});

test('profile update rejects duplicate usernames and unsafe avatar files', function () {
    Storage::fake('public');
    User::factory()->create(['username' => 'dipakai']);
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('profile.edit'))
        ->patch(route('profile.update'), [
            'name' => $user->name,
            'username' => 'dipakai',
            'email' => $user->email,
            'avatar' => UploadedFile::fake()->create('payload.svg', 10, 'image/svg+xml'),
        ]);

    $response
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHasErrors(['username', 'avatar']);
    expect($user->refresh()->username)->toBeNull();
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete(route('profile.destroy'), [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    $this->assertGuest();
    expect($user->fresh())->toBeNull();
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), [
            'password' => 'wrong-password',
        ]);

    $response
        ->assertSessionHasErrors('password')
        ->assertRedirect(route('profile.edit'));

    expect($user->fresh())->not->toBeNull();
});

test('operational accounts cannot delete themselves', function () {
    $operator = User::factory()->withTwoFactor()->create(['role' => 'operator']);

    $this->actingAs($operator)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertForbidden();

    expect($operator->fresh())->not->toBeNull();
});
