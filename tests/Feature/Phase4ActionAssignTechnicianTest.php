<?php

use App\Models\OrderAssignment;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function createOrderForTeamActionTest(): ServiceOrder
{
    $service = Service::create([
        'code' => 'SRV-TM',
        'slug' => 'servis-tim',
        'name' => 'Servis Infrastruktur',
        'category' => 'Jaringan',
        'service_mode' => 'onsite',
        'risk_level' => 'medium',
        'pricing_label' => 'Mulai Rp 200.000',
        'base_price_min' => 200000,
        'base_price_max' => 600000,
        'warranty_days' => 30,
        'is_active' => true,
        'summary' => 'Servis infrastruktur.',
    ]);

    $customer = User::factory()->create(['role' => 'customer']);

    return ServiceOrder::create([
        'ticket_number' => 'ADC-20261116-0303',
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'customer_name' => $customer->name,
        'phone' => '6281234567890',
        'email' => $customer->email,
        'address' => 'Jl. Braga No. 22 Bandung',
        'service_mode' => 'onsite',
        'complaint' => 'Butuh konfigurasi ulang switch dan access point kantor.',
        'preferred_date' => now()->toDateString(),
        'preferred_window' => 'morning',
        'urgency' => 'normal',
        'risk_level' => 'low',
        'status' => 'triaged',
        'tracking_token_hash' => hash('sha256', 'tok-tm'),
        'access_code_hash' => hash('sha256', 'code-tm'),
    ]);
}

it('previews and confirms assign_technician for supervisor and records OrderAssignment plus audit log', function () {
    $order = createOrderForTeamActionTest();
    $supervisor = User::factory()->create(['role' => 'supervisor']);
    $tech = User::factory()->create([
        'role' => 'technician',
        'name' => 'Teknisi Hendra',
        'is_active' => true,
        'availability_status' => 'available',
    ]);

    $response = $this->actingAs($supervisor)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'Tugaskan teknisi Hendra untuk tiket ADC-20261116-0303',
        ])
        ->assertOk();

    $proposal = $response->json('action_proposal');
    expect($proposal)->not->toBeNull()
        ->and($proposal['action_name'])->toBe('assign_technician')
        ->and($proposal['preview']['proposed_changes'][0]['before'])->toBe('Belum ada PIC')
        ->and($proposal['preview']['proposed_changes'][0]['after'])->toBe('Teknisi Hendra')
        ->and($order->fresh()->assigned_technician_id)->toBeNull();

    $this->actingAs($supervisor)
        ->postJson(route('assistant.actions.confirm', ['proposal' => $proposal['action_id']]), [
            'payload_hash' => $proposal['payload_hash'],
        ])
        ->assertOk()
        ->assertJsonPath('status', 'executed');

    expect($order->fresh()->assigned_technician_id)->toBe($tech->id)
        ->and(OrderAssignment::query()->where('service_order_id', $order->id)->where('user_id', $tech->id)->whereNull('released_at')->exists())->toBeTrue()
        ->and(DB::table('audit_logs')->where('auditable_id', $proposal['action_id'])->where('event', 'assistant.action_executed')->exists())->toBeTrue();
});

it('denies technician, warehouse, and customer from assigning technicians', function () {
    $order = createOrderForTeamActionTest();
    $targetTech = User::factory()->create(['role' => 'technician', 'name' => 'Teknisi Rudi', 'is_active' => true]);

    foreach (['technician', 'warehouse', 'customer'] as $role) {
        $actor = User::factory()->create(['role' => $role]);

        $response = $this->actingAs($actor)
            ->postJson(route('assistant.messages.store'), [
                'message' => 'Tugaskan teknisi Rudi untuk tiket ADC-20261116-0303',
            ])
            ->assertOk();

        expect($response->json('action_proposal'))->toBeNull()
            ->and($response->json('reply'))->toContain('hanya diizinkan');
    }

    expect($order->fresh()->assigned_technician_id)->toBeNull()
        ->and(OrderAssignment::query()->where('user_id', $targetTech->id)->count())->toBe(0);
});

it('rejects assignment conflict if technician becomes unavailable before confirm', function () {
    $order = createOrderForTeamActionTest();
    $operator = User::factory()->create(['role' => 'operator']);
    $tech = User::factory()->create([
        'role' => 'technician',
        'name' => 'Teknisi Bagus',
        'is_active' => true,
        'availability_status' => 'available',
    ]);

    $proposal = $this->actingAs($operator)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'Tugaskan teknisi Bagus untuk tiket ADC-20261116-0303',
        ])
        ->assertOk()
        ->json('action_proposal');

    // Technician goes on_leave before operator confirms
    $tech->update(['availability_status' => 'on_leave']);

    $this->actingAs($operator)
        ->postJson(route('assistant.actions.confirm', ['proposal' => $proposal['action_id']]), [
            'payload_hash' => $proposal['payload_hash'],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('status', 'failed');

    expect($order->fresh()->assigned_technician_id)->toBeNull();
});
