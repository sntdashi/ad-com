<?php

use App\Models\OrderMessage;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function createOrderForFollowupTest(?User $assignedTech = null): ServiceOrder
{
    $service = Service::create([
        'code' => 'SRV-FU',
        'slug' => 'servis-followup',
        'name' => 'Servis & Konsultasi',
        'category' => 'Perangkat',
        'service_mode' => 'dropoff',
        'risk_level' => 'low',
        'pricing_label' => 'Mulai Rp 100.000',
        'base_price_min' => 100000,
        'base_price_max' => 300000,
        'warranty_days' => 30,
        'is_active' => true,
        'summary' => 'Servis laptop.',
    ]);

    $customer = User::factory()->create(['role' => 'customer', 'name' => 'Pelanggan Dewi']);

    return ServiceOrder::create([
        'ticket_number' => 'ADC-20261116-0404',
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'customer_name' => $customer->name,
        'phone' => '6281234567890',
        'email' => $customer->email,
        'address' => 'Jl. Cihampelas No. 99 Bandung',
        'service_mode' => 'dropoff',
        'complaint' => 'Laptop perlu penggantian pasta thermal dan pembersihan kipas.',
        'preferred_date' => now()->toDateString(),
        'preferred_window' => 'morning',
        'urgency' => 'normal',
        'risk_level' => 'low',
        'status' => 'in_progress',
        'assigned_technician_id' => $assignedTech?->id,
        'tracking_token_hash' => hash('sha256', 'tok-fu'),
        'access_code_hash' => hash('sha256', 'code-fu'),
    ]);
}

it('previews and confirms create_customer_followup and dispatches customer notification', function () {
    $order = createOrderForFollowupTest();
    $operator = User::factory()->create(['role' => 'operator', 'name' => 'Op Layanan']);

    $response = $this->actingAs($operator)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'Kirim follow-up untuk ADC-20261116-0404 pesan: Perangkat sudah selesai dibersihkan dan sedang uji kestabilan suhu',
        ])
        ->assertOk();

    $proposal = $response->json('action_proposal');
    expect($proposal)->not->toBeNull()
        ->and($proposal['action_name'])->toBe('create_customer_followup')
        ->and($proposal['preview']['target'])->toContain('ADC-20261116-0404')
        ->and(OrderMessage::query()->count())->toBe(0);

    $this->actingAs($operator)
        ->postJson(route('assistant.actions.confirm', ['proposal' => $proposal['action_id']]), [
            'payload_hash' => $proposal['payload_hash'],
        ])
        ->assertOk()
        ->assertJsonPath('status', 'executed');

    expect(OrderMessage::query()->count())->toBe(1)
        ->and(OrderMessage::query()->firstOrFail()->body)->toContain('uji kestabilan suhu')
        ->and($order->fresh()->first_responded_at)->not->toBeNull()
        ->and(DB::table('notifications')->where('notifiable_id', $order->customer_id)->exists())->toBeTrue()
        ->and(DB::table('audit_logs')->where('auditable_id', $proposal['action_id'])->where('event', 'assistant.action_executed')->exists())->toBeTrue();
});

it('allows assigned technician followup while denying unassigned technician, warehouse, and customer', function () {
    $assignedTech = User::factory()->create(['role' => 'technician']);
    $unassignedTech = User::factory()->create(['role' => 'technician']);
    $warehouse = User::factory()->create(['role' => 'warehouse']);

    $order = createOrderForFollowupTest($assignedTech);
    $customer = User::query()->findOrFail($order->customer_id);

    foreach ([$unassignedTech, $warehouse, $customer] as $unauthorized) {
        $resp = $this->actingAs($unauthorized)
            ->postJson(route('assistant.messages.store'), [
                'message' => 'Kirim follow-up untuk ADC-20261116-0404 pesan: Halo pelanggan',
            ])
            ->assertOk();

        expect($resp->json('action_proposal'))->toBeNull();
    }

    $proposal = $this->actingAs($assignedTech)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'Kirim follow-up untuk ADC-20261116-0404 pesan: Penggantian thermal paste selesai dilakukan',
        ])
        ->assertOk()
        ->json('action_proposal');

    expect($proposal)->not->toBeNull();

    $this->actingAs($assignedTech)
        ->postJson(route('assistant.actions.confirm', ['proposal' => $proposal['action_id']]), [
            'payload_hash' => $proposal['payload_hash'],
        ])
        ->assertOk();

    expect(OrderMessage::query()->count())->toBe(1);
});

it('supports mobile api v1 assistant message preview and action confirmation endpoints', function () {
    $technician = User::factory()->create([
        'role' => 'technician',
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_confirmed_at' => now(),
    ]);
    createOrderForFollowupTest($technician);
    $token = $technician->createToken('mobile-test', ['mobile', 'role:technician', 'auth:2fa'])->plainTextToken;

    $previewResp = $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/v1/assistant/messages', [
            'message' => 'Kirim follow-up untuk ADC-20261116-0404 pesan: Unit siap diambil hari ini',
        ])
        ->assertOk();

    $proposal = $previewResp->json('action_proposal');
    expect($proposal)->not->toBeNull()
        ->and($previewResp->json('reply'))->toBeString()
        ->and($previewResp->json('mode'))->toBe('guide');

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/v1/assistant/actions/'.$proposal['action_id'].'/confirm', [
            'payload_hash' => $proposal['payload_hash'],
        ])
        ->assertOk()
        ->assertJsonPath('status', 'executed');

    expect(OrderMessage::query()->count())->toBe(1);
});
