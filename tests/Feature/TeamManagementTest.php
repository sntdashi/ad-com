<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

test('operator can filter team members by role status and search and view role permissions', function () {
    $this->withoutVite();

    $operator = User::factory()->withTwoFactor()->create([
        'role' => 'operator',
        'phone' => '081111111111',
    ]);
    $inactiveTech = User::factory()->create([
        'role' => 'technician',
        'name' => 'Bagus Teknisi',
        'is_active' => false,
        'phone' => '',
    ]);
    User::factory()->create([
        'role' => 'technician',
        'name' => 'Siti Aktif',
        'is_active' => true,
        'phone' => '082222222222',
    ]);

    $this->actingAs($operator)
        ->get(route('team.index', [
            'role' => 'technician',
            'filter' => 'inactive',
            'search' => 'Bagus',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('operations/team')
            ->where('filters.role', 'technician')
            ->where('filters.filter', 'inactive')
            ->where('filters.search', 'Bagus')
            ->has('rolePermissions', 6)
            ->has('members', 1)
            ->where('members.0.id', $inactiveTech->id)
            ->where('members.0.is_active', false));

    $this->actingAs($operator)
        ->get(route('team.index', ['filter' => 'missing_phone']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('operations/team')
            ->has('members', 1)
            ->where('members.0.id', $inactiveTech->id));
});

test('non-manager roles cannot open team management', function () {
    $this->withoutVite();

    $technician = User::factory()->withTwoFactor()->create(['role' => 'technician']);

    $this->actingAs($technician)
        ->get(route('team.index'))
        ->assertForbidden();

    $this->actingAs($technician)
        ->post(route('team.store'), [
            'name' => 'Unauthorized',
            'email' => 'unauth@adcom.test',
            'role' => 'technician',
            'password' => 'password123',
        ])
        ->assertForbidden();
});

test('superadmin can create any role while admin cannot create or edit superadmin', function () {
    $superadmin = User::factory()->create(['role' => 'operator']);
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($superadmin)
        ->post(route('team.store'), [
            'name' => 'Superadmin Baru',
            'email' => 'superadmin2@adcom.test',
            'role' => 'operator',
            'phone' => '628199999999',
            'password' => 'password123',
        ])
        ->assertRedirect();

    expect(User::query()->where('email', 'superadmin2@adcom.test')->value('role'))->toBe('operator')
        ->and(DB::table('audit_logs')->where('event', 'team.member_created')->exists())->toBeTrue();

    $this->actingAs($admin)
        ->post(route('team.store'), [
            'name' => 'Admin Coba Superadmin',
            'email' => 'illegal@adcom.test',
            'role' => 'operator',
            'password' => 'password123',
        ])
        ->assertSessionHasErrors('role');

    $this->actingAs($admin)
        ->patch(route('team.update', $superadmin), [
            'role' => 'admin',
            'show_phone_publicly' => false,
            'is_active' => false,
        ])
        ->assertForbidden();
});
