<?php

use App\Models\Service;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

test('superadmin can manage services and system configuration with audit logging', function () {
    $this->withoutVite();
    $superadmin = User::factory()->create(['role' => 'operator']);

    $this->actingAs($superadmin)
        ->get(route('services-settings.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/services'));

    $this->actingAs($superadmin)
        ->post(route('services-settings.store'), [
            'code' => 'SRV.99',
            'name' => 'Audit Jaringan Sekolah',
            'summary' => 'Audit menyeluruh jaringan sekolah.',
            'starting_price' => 150000,
            'pricing_label' => 'Mulai Rp150 ribu',
            'service_mode' => 'onsite',
            'risk_level' => 'medium',
            'warranty_days' => 30,
            'sort_order' => 5,
            'is_active' => true,
        ])
        ->assertRedirect();

    $service = Service::query()->where('code', 'SRV.99')->firstOrFail();

    $this->actingAs($superadmin)
        ->patch(route('services-settings.update', $service), [
            'code' => 'SRV.99',
            'name' => 'Audit Jaringan Sekolah Pro',
            'summary' => 'Audit menyeluruh jaringan sekolah dan Lab TKJ.',
            'starting_price' => 200000,
            'pricing_label' => 'Mulai Rp200 ribu',
            'service_mode' => 'onsite',
            'risk_level' => 'high',
            'warranty_days' => 30,
            'sort_order' => 5,
            'is_active' => false,
        ])
        ->assertRedirect();

    expect($service->refresh()->is_active)->toBeFalse()
        ->and(DB::table('audit_logs')->where('event', 'service.created')->exists())->toBeTrue()
        ->and(DB::table('audit_logs')->where('event', 'service.updated')->exists())->toBeTrue();

    $this->actingAs($superadmin)
        ->get(route('system-settings.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/system'));

    $this->actingAs($superadmin)
        ->put(route('system-settings.update'), [
            'business_name' => 'AD Com TEFA Center',
            'business_tagline' => 'Layanan IT Terpadu.',
            'business_phone' => '628123450000',
            'business_address' => 'Lab TKJ 1',
            'operating_hours' => '08.00-16.00',
            'warranty_default_days' => 21,
            'registration_otp_enabled' => true,
        ])
        ->assertRedirect();

    expect(SystemSetting::query()->where('key', 'business_name')->value('value'))->toBe('AD Com TEFA Center')
        ->and(DB::table('audit_logs')->where('event', 'system.settings_updated')->exists())->toBeTrue();

    $this->actingAs($superadmin)
        ->get(route('admin.control-center', ['audit_event' => 'service.']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/admin-control-center')
            ->where('isSuperadmin', true)
            ->where('auditEvent', 'service.')
            ->has('recentActivity', 2));
});

test('non-superadmin roles cannot access service management or system configuration', function (string $role) {
    $this->withoutVite();
    $user = User::factory()->create(['role' => $role]);

    $this->actingAs($user)->get(route('services-settings.index'))->assertForbidden();
    $this->actingAs($user)->post(route('services-settings.store'), [])->assertForbidden();
    $this->actingAs($user)->get(route('system-settings.edit'))->assertForbidden();
    $this->actingAs($user)->put(route('system-settings.update'), [])->assertForbidden();
})->with([
    'admin' => 'admin',
    'supervisor' => 'supervisor',
    'technician' => 'technician',
    'warehouse' => 'warehouse',
    'customer' => 'customer',
]);
