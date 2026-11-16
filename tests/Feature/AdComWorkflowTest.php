<?php

use App\Models\OrderAssignment;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function makeAdComService(array $overrides = []): Service
{
    return Service::factory()->create([
        'code' => 'NET.01',
        'slug' => 'jaringan-wifi',
        'name' => 'Jaringan & Wi-Fi',
        'summary' => 'Audit dan instalasi jaringan.',
        'description' => 'Layanan jaringan untuk masyarakat.',
        'starting_price' => 75000,
        'pricing_label' => 'Mulai Rp75 ribu',
        'service_mode' => 'onsite',
        'risk_level' => 'medium',
        'icon' => 'router',
        'warranty_days' => 14,
        'outcomes' => ['Hasil uji koneksi'],
        'is_active' => true,
        'sort_order' => 1,
        ...$overrides,
    ]);
}

function makeAdComOrder(Service $service, array $overrides = []): ServiceOrder
{
    return ServiceOrder::factory()->for($service)->create([
        'ticket_number' => 'ADC-2609-9999',
        'tracking_token_hash' => hash('sha256', 'test-tracking-token'),
        'access_code_hash' => hash('sha256', 'TEST1234'),
        'customer_name' => 'Pelanggan Test',
        'phone' => '6281234567890',
        'address' => 'Jalan Pendidikan Nomor 10',
        'service_mode' => 'onsite',
        'complaint' => 'Koneksi internet sering terputus sejak dua hari lalu.',
        'urgency' => 'normal',
        'risk_level' => 'unassessed',
        'status' => 'new',
        ...$overrides,
    ]);
}

test('public booking page exposes active services', function () {
    makeAdComService();
    makeAdComService(['code' => 'OLD.01', 'slug' => 'nonaktif', 'name' => 'Nonaktif', 'is_active' => false]);

    $this->get(route('booking.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('booking')
            ->has('services', 1)
            ->where('services.0.slug', 'jaringan-wifi'));
});

test('guest can create a privacy-safe service ticket', function () {
    $service = makeAdComService();

    $response = $this->post(route('booking.store'), [
        'service_id' => $service->id,
        'customer_name' => 'Siti Rahma',
        'phone' => '0812-3456-7890',
        'email' => 'SITI@EXAMPLE.COM',
        'address' => 'Jalan Pendidikan Nomor 12, dekat sekolah',
        'district' => 'Kecamatan Contoh',
        'service_mode' => 'onsite',
        'complaint' => 'Sinyal Wi-Fi hilang di lantai dua dan sering terputus.',
        'preferred_date' => now()->addDay()->format('Y-m-d'),
        'preferred_window' => 'morning',
        'urgency' => 'normal',
        'privacy_accepted' => true,
        'status' => 'completed',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('access_code');

    $order = ServiceOrder::query()->sole();
    expect($order->phone)->toBe('6281234567890')
        ->and($order->email)->toBe('siti@example.com')
        ->and($order->tracking_token_hash)->toHaveLength(64)
        ->and($order->access_code_hash)->toHaveLength(64)
        ->and($order->access_code_hash)->not->toBe(session('access_code'))
        ->and($order->status)->toBe('new');

    $this->assertDatabaseCount('order_status_events', 1);
});

test('booking rejects incomplete complaint and invalid Indonesian phone', function () {
    $service = makeAdComService();

    $this->from(route('booking.create'))->post(route('booking.store'), [
        'service_id' => $service->id,
        'customer_name' => 'Siti',
        'phone' => '123',
        'address' => 'Alamat yang cukup panjang',
        'service_mode' => 'onsite',
        'complaint' => 'Rusak',
        'urgency' => 'normal',
        'privacy_accepted' => true,
    ])->assertRedirect(route('booking.create'))->assertSessionHasErrors(['phone', 'complaint']);
});

test('tracking requires the matching access code and creates a signed link', function () {
    $order = makeAdComOrder(makeAdComService());
    $order->statusEvents()->create(['status' => 'new', 'title' => 'Permintaan dicatat', 'visibility' => 'customer', 'occurred_at' => now()]);

    $this->from(route('track.index'))->post(route('track.lookup'), [
        'ticket_number' => $order->ticket_number,
        'access_code' => 'WRONG999',
    ])->assertRedirect(route('track.index'))->assertSessionHasErrors('ticket_number');

    $response = $this->post(route('track.lookup'), [
        'ticket_number' => strtolower($order->ticket_number),
        'access_code' => 'test1234',
    ]);

    $response->assertRedirect();
    $location = $response->headers->get('Location');
    expect($location)->toContain('/lacak/p/')->toContain('signature=');
    $this->get($location)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('track-show')
        ->where('order.ticket_number', $order->ticket_number));
});

test('private tracking token never works when the raw token is wrong', function () {
    makeAdComOrder(makeAdComService());

    $this->get(route('track.token', ['token' => 'wrong-token']))->assertNotFound();
    $this->get(route('track.token', ['token' => 'test-tracking-token']))->assertOk();
});

test('supervisor can transition an order and the change is audited', function () {
    $order = makeAdComOrder(makeAdComService());
    $supervisor = User::factory()->withTwoFactor()->create(['role' => 'supervisor']);

    $this->actingAs($supervisor)->patch(route('orders.status.update', $order), [
        'status' => 'triaged',
        'note' => 'Kebutuhan sudah diperiksa dan aman untuk dilanjutkan.',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($order->fresh()->status)->toBe('triaged');
    $this->assertDatabaseHas('order_status_events', ['service_order_id' => $order->id, 'status' => 'triaged']);
    $this->assertDatabaseHas('audit_logs', ['auditable_id' => $order->id, 'event' => 'service_order.status_updated']);
});

test('technician receives 404 for an order assigned to someone else', function () {
    $order = makeAdComOrder(makeAdComService());
    $technician = User::factory()->withTwoFactor()->create(['role' => 'technician']);

    $this->actingAs($technician)->get(route('orders.show', $order))->assertNotFound();
});

test('assigned technician team member sees the team and service standards', function () {
    $service = makeAdComService();
    $service->checklistTemplates()->create([
        'item_key' => 'final-test',
        'title' => 'Jalankan tes koneksi akhir',
        'standard_code' => 'NET-QA-01',
        'requires_evidence' => true,
        'is_required' => true,
        'sort_order' => 1,
    ]);
    $pic = User::factory()->create(['name' => 'Raka Wijaya', 'role' => 'technician']);
    $member = User::factory()->withTwoFactor()->create(['name' => 'Nabila Putri', 'role' => 'technician']);
    $order = makeAdComOrder($service, [
        'assigned_technician_id' => $pic->id,
        'status' => 'scheduled',
    ]);
    OrderAssignment::factory()->for($order)->for($pic)->create();
    OrderAssignment::factory()->for($order)->for($member)->create([
        'assigned_at' => now()->addMinute(),
    ]);

    $this->actingAs($member)->get(route('orders.show', $order))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('orders/show')
            ->where('order.technician.name', 'Raka Wijaya')
            ->where('order.technician_team.0.name', 'Raka Wijaya')
            ->where('order.technician_team.1.name', 'Nabila Putri')
            ->where('order.checklist.0.standard_code', 'NET-QA-01')
            ->missing('order.checklist.0.competency_code'));
});

test('public tracking exposes the assigned technician team without unconsented contacts', function () {
    $service = makeAdComService();
    $pic = User::factory()->create(['name' => 'Raka Wijaya', 'role' => 'technician']);
    $member = User::factory()->create(['name' => 'Nabila Putri', 'role' => 'technician']);
    $order = makeAdComOrder($service, ['assigned_technician_id' => $pic->id]);
    OrderAssignment::factory()->for($order)->for($pic)->create();
    OrderAssignment::factory()->for($order)->for($member)->create([
        'assigned_at' => now()->addMinute(),
    ]);

    $this->get(route('track.token', ['token' => 'test-tracking-token']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('track-show')
            ->where('order.technician.name', 'Raka Wijaya')
            ->where('order.technician.phone', null)
            ->where('order.technician_team.0.name', 'Raka Wijaya')
            ->where('order.technician_team.1.name', 'Nabila Putri'));
});

test('supervisor can replace the technician team and choose its PIC', function () {
    $order = makeAdComOrder(makeAdComService());
    $supervisor = User::factory()->withTwoFactor()->create(['role' => 'supervisor']);
    $oldMember = User::factory()->create(['role' => 'technician']);
    $newPic = User::factory()->create(['role' => 'technician']);
    $newMember = User::factory()->create(['role' => 'technician']);
    $oldAssignment = OrderAssignment::factory()->for($order)->for($oldMember)->create();

    $this->actingAs($supervisor)->patch(route('orders.team.update', $order), [
        'technician_ids' => [$newPic->id, $newMember->id],
        'lead_technician_id' => $newPic->id,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($order->fresh()->assigned_technician_id)->toBe($newPic->id)
        ->and($oldAssignment->fresh()->released_at)->not->toBeNull();
    $this->assertDatabaseHas('order_assignments', [
        'service_order_id' => $order->id,
        'user_id' => $newPic->id,
        'assignment_role' => 'technician',
        'released_at' => null,
    ]);
    $this->assertDatabaseHas('order_assignments', [
        'service_order_id' => $order->id,
        'user_id' => $newMember->id,
        'assignment_role' => 'technician',
        'released_at' => null,
    ]);
    $this->assertDatabaseHas('audit_logs', [
        'auditable_id' => $order->id,
        'event' => 'service_order.team_updated',
    ]);
});

test('technician cannot change an order team', function () {
    $order = makeAdComOrder(makeAdComService());
    $technician = User::factory()->withTwoFactor()->create(['role' => 'technician']);

    $this->actingAs($technician)->patch(route('orders.team.update', $order), [
        'technician_ids' => [$technician->id],
        'lead_technician_id' => $technician->id,
    ])->assertForbidden();

    expect($order->fresh()->assigned_technician_id)->toBeNull();
    $this->assertDatabaseCount('order_assignments', 0);
});

test('team assignment rejects accounts that are not active technicians', function () {
    $order = makeAdComOrder(makeAdComService());
    $supervisor = User::factory()->withTwoFactor()->create(['role' => 'supervisor']);
    $customer = User::factory()->create(['role' => 'customer']);

    $this->actingAs($supervisor)->patch(route('orders.team.update', $order), [
        'technician_ids' => [$customer->id],
        'lead_technician_id' => $customer->id,
    ])->assertSessionHasErrors([
        'technician_ids.0' => 'Anggota yang dipilih harus merupakan teknisi aktif.',
    ]);

    expect($order->fresh()->assigned_technician_id)->toBeNull();
    $this->assertDatabaseCount('order_assignments', 0);
});
