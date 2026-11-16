<?php

use App\Models\Estimate;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function createOrderForQuotationTest(): ServiceOrder
{
    $service = Service::create([
        'code' => 'SRV-Q',
        'slug' => 'servis-komputer-q',
        'name' => 'Servis Komputer',
        'category' => 'Perangkat',
        'service_mode' => 'dropoff',
        'risk_level' => 'medium',
        'pricing_label' => 'Mulai Rp 150.000',
        'base_price_min' => 150000,
        'base_price_max' => 450000,
        'warranty_days' => 30,
        'is_active' => true,
        'summary' => 'Servis komputer.',
    ]);

    $customer = User::factory()->create(['role' => 'customer', 'name' => 'Pelanggan Sari']);

    return ServiceOrder::create([
        'ticket_number' => 'ADC-20261116-0101',
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'customer_name' => $customer->name,
        'phone' => '6281234567890',
        'email' => $customer->email,
        'address' => 'Jl. Merdeka No. 12 Bandung',
        'service_mode' => 'dropoff',
        'complaint' => 'Laptop mati mendadak saat membuka program berat.',
        'preferred_date' => now()->toDateString(),
        'preferred_window' => 'morning',
        'urgency' => 'normal',
        'risk_level' => 'low',
        'status' => 'diagnosing',
        'tracking_token_hash' => hash('sha256', 'tok-q1'),
        'access_code_hash' => hash('sha256', 'code-q1'),
    ]);
}

it('previews accurate financial totals and executes create_quotation only after operator confirmation', function () {
    $order = createOrderForQuotationTest();
    $operator = User::factory()->create(['role' => 'operator', 'name' => 'Op Keuangan']);

    $response = $this->actingAs($operator)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'Buatkan penawaran untuk ADC-20261116-0101: jasa bongkar pasang Rp 200000, sparepart SSD 512GB Rp 550000, operasional antar Rp 25000, diskon Rp 25000, pajak 10%',
        ])
        ->assertOk();

    $proposal = $response->json('action_proposal');
    expect($proposal)->not->toBeNull()
        ->and($proposal['action_name'])->toBe('create_quotation')
        ->and($proposal['status'])->toBe('awaiting_confirmation')
        // Subtotal = 200000 + 550000 + 25000 = 775000; discount = 25000; taxable = 750000; tax 10% = 75000; total = 825000
        ->and($proposal['preview']['financial_impact']['amount'])->toBe(825000)
        ->and(Estimate::query()->count())->toBe(0);

    $confirmResponse = $this->actingAs($operator)
        ->postJson(route('assistant.actions.confirm', ['proposal' => $proposal['action_id']]), [
            'payload_hash' => $proposal['payload_hash'],
        ])
        ->assertOk();

    expect($confirmResponse->json('status'))->toBe('executed')
        ->and(Estimate::query()->count())->toBe(1);

    $estimate = Estimate::query()->with('items')->firstOrFail();
    expect($estimate->service_order_id)->toBe($order->id)
        ->and($estimate->subtotal)->toBe(775000)
        ->and($estimate->discount_amount)->toBe(25000)
        ->and($estimate->tax_amount)->toBe(75000)
        ->and($estimate->total)->toBe(825000)
        ->and($estimate->items)->toHaveCount(3)
        ->and(DB::table('audit_logs')->where('auditable_id', $proposal['action_id'])->where('event', 'assistant.action_executed')->exists())->toBeTrue();
});

it('denies non-operator and non-admin roles from creating quotations via assistant', function () {
    createOrderForQuotationTest();

    foreach (['supervisor', 'technician', 'warehouse', 'customer'] as $role) {
        $actor = User::factory()->create(['role' => $role]);

        $response = $this->actingAs($actor)
            ->postJson(route('assistant.messages.store'), [
                'message' => 'Buatkan penawaran untuk ADC-20261116-0101: jasa servis Rp 250000',
            ])
            ->assertOk();

        expect($response->json('action_proposal'))->toBeNull()
            ->and($response->json('reply'))->toContain('hanya diizinkan untuk Admin Operasional dan Superadmin');
    }

    expect(Estimate::query()->count())->toBe(0);
});

it('re-evaluates EstimatePolicy on confirm when actor role changes after preview', function () {
    createOrderForQuotationTest();
    $admin = User::factory()->create(['role' => 'admin']);

    $proposal = $this->actingAs($admin)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'Buatkan quotation untuk ADC-20261116-0101: jasa instalasi ulang Rp 150000',
        ])
        ->assertOk()
        ->json('action_proposal');

    expect($proposal)->not->toBeNull();

    // Downgrade role before confirmation
    $admin->update(['role' => 'supervisor']);

    $this->actingAs($admin->fresh())
        ->postJson(route('assistant.actions.confirm', ['proposal' => $proposal['action_id']]), [
            'payload_hash' => $proposal['payload_hash'],
        ])
        ->assertForbidden()
        ->assertJsonPath('status', 'denied');

    expect(Estimate::query()->count())->toBe(0);
});
