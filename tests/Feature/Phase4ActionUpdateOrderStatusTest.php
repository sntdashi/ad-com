<?php

use App\Models\OrderAssignment;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function createOrderForStatusTest(string $status = 'new', ?User $assignedTech = null): ServiceOrder
{
    $service = Service::create([
        'code' => 'SRV-ST',
        'slug' => 'jaringan-status',
        'name' => 'Perbaikan Jaringan',
        'category' => 'Jaringan',
        'service_mode' => 'onsite',
        'risk_level' => 'low',
        'pricing_label' => 'Mulai Rp 150.000',
        'base_price_min' => 150000,
        'base_price_max' => 450000,
        'warranty_days' => 30,
        'is_active' => true,
        'summary' => 'Perbaikan jaringan.',
    ]);

    $customer = User::factory()->create(['role' => 'customer', 'name' => 'Pelanggan Rina']);

    $order = ServiceOrder::create([
        'ticket_number' => 'ADC-20261116-0202',
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'customer_name' => $customer->name,
        'phone' => '6281234567890',
        'email' => $customer->email,
        'address' => 'Jl. Riau No. 18 Bandung',
        'service_mode' => 'onsite',
        'complaint' => 'Router kantor sering putus koneksi setiap siang.',
        'preferred_date' => now()->toDateString(),
        'preferred_window' => 'morning',
        'urgency' => 'normal',
        'risk_level' => 'low',
        'status' => $status,
        'assigned_technician_id' => $assignedTech?->id,
        'tracking_token_hash' => hash('sha256', 'tok-st'),
        'access_code_hash' => hash('sha256', 'code-st'),
    ]);

    if ($assignedTech !== null) {
        OrderAssignment::create([
            'service_order_id' => $order->id,
            'user_id' => $assignedTech->id,
            'role_in_team' => 'lead',
            'status' => 'active',
            'assigned_by' => $assignedTech->id,
            'assigned_at' => now(),
        ]);
    }

    return $order;
}

it('previews and confirms update_order_status for operator and logs events', function () {
    $order = createOrderForStatusTest('new');
    $operator = User::factory()->create(['role' => 'operator']);

    $response = $this->actingAs($operator)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'Ubah status ADC-20261116-0202 ke triaged catatan: Sudah diperiksa oleh operator',
        ])
        ->assertOk();

    $proposal = $response->json('action_proposal');
    expect($proposal)->not->toBeNull()
        ->and($proposal['action_name'])->toBe('update_order_status')
        ->and($proposal['preview']['proposed_changes'][0]['before'])->toContain('new')
        ->and($proposal['preview']['proposed_changes'][0]['after'])->toContain('triaged')
        ->and($order->fresh()->status)->toBe('new');

    $this->actingAs($operator)
        ->postJson(route('assistant.actions.confirm', ['proposal' => $proposal['action_id']]), [
            'payload_hash' => $proposal['payload_hash'],
        ])
        ->assertOk()
        ->assertJsonPath('status', 'executed');

    expect($order->fresh()->status)->toBe('triaged')
        ->and($order->fresh()->statusEvents()->where('status', 'triaged')->exists())->toBeTrue()
        ->and(DB::table('audit_logs')->where('auditable_id', $proposal['action_id'])->where('event', 'assistant.action_executed')->exists())->toBeTrue();
});

it('allows assigned technician valid status transition and denies unassigned or unauthorized roles', function () {
    $assignedTech = User::factory()->create(['role' => 'technician']);
    $unassignedTech = User::factory()->create(['role' => 'technician']);
    $warehouse = User::factory()->create(['role' => 'warehouse']);
    $customer = User::factory()->create(['role' => 'customer']);

    $order = createOrderForStatusTest('scheduled', $assignedTech);

    // Unassigned tech, warehouse, and customer are denied
    foreach ([$unassignedTech, $warehouse, $customer] as $unauthorized) {
        $resp = $this->actingAs($unauthorized)
            ->postJson(route('assistant.messages.store'), [
                'message' => 'Ubah status ADC-20261116-0202 ke on_the_way',
            ])
            ->assertOk();

        expect($resp->json('action_proposal'))->toBeNull();
    }

    // Assigned tech cannot jump to cancelled (not in technician transitions)
    $forbiddenTransition = $this->actingAs($assignedTech)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'Ubah status ADC-20261116-0202 ke cancelled',
        ])
        ->assertOk();
    expect($forbiddenTransition->json('action_proposal'))->toBeNull()
        ->and($forbiddenTransition->json('reply'))->toContain('tidak diizinkan');

    // Assigned tech can transition scheduled -> on_the_way
    $proposal = $this->actingAs($assignedTech)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'Ubah status ADC-20261116-0202 ke on_the_way',
        ])
        ->assertOk()
        ->json('action_proposal');

    expect($proposal)->not->toBeNull();

    $this->actingAs($assignedTech)
        ->postJson(route('assistant.actions.confirm', ['proposal' => $proposal['action_id']]), [
            'payload_hash' => $proposal['payload_hash'],
        ])
        ->assertOk();

    expect($order->fresh()->status)->toBe('on_the_way');
});

it('rejects confirmation when order status drifted before confirmation', function () {
    $order = createOrderForStatusTest('new');
    $operator = User::factory()->create(['role' => 'operator']);

    $proposal = $this->actingAs($operator)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'Ubah status ADC-20261116-0202 ke triaged',
        ])
        ->assertOk()
        ->json('action_proposal');

    // Another staff moves order directly to completed before confirm
    $order->update(['status' => 'completed']);

    $this->actingAs($operator)
        ->postJson(route('assistant.actions.confirm', ['proposal' => $proposal['action_id']]), [
            'payload_hash' => $proposal['payload_hash'],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('status', 'failed');

    expect($order->fresh()->status)->toBe('completed');
});
