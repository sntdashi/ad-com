<?php

use App\Models\AiProvider;
use App\Models\AssistantActionProposal;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function createServiceOrderCatalog(): Service
{
    return Service::create([
        'code' => 'SRV-PC',
        'slug' => 'servis-komputer',
        'name' => 'Servis Komputer & Laptop',
        'category' => 'Perangkat',
        'service_mode' => 'dropoff',
        'risk_level' => 'medium',
        'pricing_label' => 'Mulai Rp 120.000',
        'base_price_min' => 120000,
        'base_price_max' => 450000,
        'warranty_days' => 30,
        'is_active' => true,
        'summary' => 'Perbaikan laptop, komputer, overheat, dan instalasi ulang.',
    ]);
}

it('creates a validated preview proposal for create_service_order without executing until confirmed', function () {
    $service = createServiceOrderCatalog();
    $customer = User::factory()->create([
        'role' => 'customer',
        'name' => 'Pelanggan Sari',
        'email' => 'sari@example.test',
        'phone' => '081234567890',
        'address' => 'Jl. Merdeka No. 12 Bandung',
    ]);

    $response = $this->actingAs($customer)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'Buatkan pesanan servis komputer untuk keluhan laptop sering overheat dan mati mendadak saat rendering, alamat Jl. Merdeka No. 12 Bandung, no hp 081234567890',
        ])
        ->assertOk();

    expect($response->json('action_proposal'))->not->toBeNull()
        ->and($response->json('action_proposal.action_name'))->toBe('create_service_order')
        ->and($response->json('action_proposal.status'))->toBe('awaiting_confirmation')
        ->and($response->json('action_proposal.preview.target'))->toContain('Servis Komputer')
        ->and(ServiceOrder::query()->count())->toBe(0);

    $proposalId = $response->json('action_proposal.action_id');
    $proposalHash = $response->json('action_proposal.payload_hash');

    expect(DB::table('audit_logs')->where('auditable_id', $proposalId)->where('event', 'assistant.action_requested')->exists())->toBeTrue()
        ->and(DB::table('audit_logs')->where('auditable_id', $proposalId)->where('event', 'assistant.action_validated')->exists())->toBeTrue()
        ->and(DB::table('audit_logs')->where('auditable_id', $proposalId)->where('event', 'assistant.action_previewed')->exists())->toBeTrue()
        ->and(DB::table('audit_logs')->where('auditable_id', $proposalId)->where('event', 'assistant.action_awaiting_confirmation')->exists())->toBeTrue();

    $confirmResponse = $this->actingAs($customer)
        ->postJson(route('assistant.actions.confirm', ['proposal' => $proposalId]), [
            'payload_hash' => $proposalHash,
        ])
        ->assertOk();

    expect($confirmResponse->json('status'))->toBe('executed')
        ->and($confirmResponse->json('action_proposal.status'))->toBe('executed')
        ->and($confirmResponse->json('action_proposal.result_reference'))->toStartWith('ADC-')
        ->and(ServiceOrder::query()->count())->toBe(1);

    $order = ServiceOrder::query()->firstOrFail();
    expect($order->service_id)->toBe($service->id)
        ->and($order->customer_id)->toBe($customer->id)
        ->and($order->status)->toBe('new')
        ->and(json_encode($confirmResponse->json()))->not->toContain($order->access_code_hash)
        ->and(json_encode($confirmResponse->json()))->not->toContain($order->tracking_token_hash);

    expect(DB::table('audit_logs')->where('auditable_id', $proposalId)->where('event', 'assistant.action_executed')->exists())->toBeTrue();
});

it('cancels create_service_order proposal without creating a service order', function () {
    createServiceOrderCatalog();
    $customer = User::factory()->create([
        'role' => 'customer',
        'name' => 'Pelanggan Doni',
        'email' => 'doni@example.test',
        'phone' => '081234567891',
        'address' => 'Jl. Asia Afrika No. 10 Bandung',
    ]);

    $proposal = $this->actingAs($customer)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'Buatkan pesanan servis komputer karena laptop layar berkedip dan lambat sekali, alamat Jl. Asia Afrika No. 10 Bandung, no hp 081234567891',
        ])
        ->assertOk()
        ->json('action_proposal');

    $cancelResponse = $this->actingAs($customer)
        ->postJson(route('assistant.actions.cancel', ['proposal' => $proposal['action_id']]))
        ->assertOk();

    expect($cancelResponse->json('status'))->toBe('cancelled')
        ->and($cancelResponse->json('action_proposal.status'))->toBe('cancelled')
        ->and(ServiceOrder::query()->count())->toBe(0)
        ->and(DB::table('audit_logs')->where('auditable_id', $proposal['action_id'])->where('event', 'assistant.action_cancelled')->exists())->toBeTrue();
});

it('blocks guest users from creating or confirming action proposals', function () {
    createServiceOrderCatalog();

    $response = $this->postJson(route('assistant.messages.store'), [
        'message' => 'Buatkan pesanan servis komputer untuk keluhan laptop mati total saat digunakan, alamat Jl. Sudirman No. 20, no hp 081234567899',
    ])->assertOk();

    expect($response->json('action_proposal'))->toBeNull()
        ->and($response->json('reply'))->toContain('Masuk ke akun')
        ->and(AssistantActionProposal::query()->count())->toBe(0)
        ->and(ServiceOrder::query()->count())->toBe(0);

    $this->postJson(route('assistant.actions.confirm', ['proposal' => '01JFAKEPROPOSALULID0000001']), [
        'payload_hash' => str_repeat('a', 64),
    ])->assertUnauthorized();
});

it('rejects tampered payload hash, cross-user confirm/cancel, replay confirm, and expired proposals', function () {
    createServiceOrderCatalog();
    $owner = User::factory()->create([
        'role' => 'customer',
        'phone' => '081234567890',
        'address' => 'Jl. Dipatiukur No. 5 Bandung',
    ]);
    $otherUser = User::factory()->create(['role' => 'operator']);

    $proposal = $this->actingAs($owner)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'Buatkan pesanan servis komputer untuk keluhan keyboard error dan overheat saat dipakai kerja, alamat Jl. Dipatiukur No. 5 Bandung, no hp 081234567890',
        ])
        ->assertOk()
        ->json('action_proposal');

    $proposalId = $proposal['action_id'];

    // 1. Tampered payload_hash
    $this->actingAs($owner)
        ->postJson(route('assistant.actions.confirm', ['proposal' => $proposalId]), [
            'payload_hash' => str_repeat('f', 64),
        ])
        ->assertUnprocessable()
        ->assertJsonPath('status', 'tampered');

    expect(ServiceOrder::query()->count())->toBe(0)
        ->and(DB::table('audit_logs')->where('auditable_id', $proposalId)->where('event', 'assistant.action_tamper_rejected')->exists())->toBeTrue();

    // 2. Cross-user confirmation and cancellation attempts
    $this->actingAs($otherUser)
        ->postJson(route('assistant.actions.confirm', ['proposal' => $proposalId]), [
            'payload_hash' => $proposal['payload_hash'],
        ])
        ->assertForbidden()
        ->assertJsonPath('status', 'forbidden');

    $this->actingAs($otherUser)
        ->postJson(route('assistant.actions.cancel', ['proposal' => $proposalId]))
        ->assertForbidden()
        ->assertJsonPath('status', 'forbidden');

    expect(ServiceOrder::query()->count())->toBe(0)
        ->and(DB::table('audit_logs')->where('auditable_id', $proposalId)->where('event', 'assistant.action_cross_user_rejected')->count())->toBe(2);

    // 3. Valid confirm + duplicate replay confirm
    $this->actingAs($owner)
        ->postJson(route('assistant.actions.confirm', ['proposal' => $proposalId]), [
            'payload_hash' => $proposal['payload_hash'],
        ])
        ->assertOk();

    expect(ServiceOrder::query()->count())->toBe(1);

    $this->actingAs($owner)
        ->postJson(route('assistant.actions.confirm', ['proposal' => $proposalId]), [
            'payload_hash' => $proposal['payload_hash'],
        ])
        ->assertStatus(409)
        ->assertJsonPath('status', 'executed');

    expect(ServiceOrder::query()->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('auditable_id', $proposalId)->where('event', 'assistant.action_replay_rejected')->exists())->toBeTrue();

    // 4. Expired proposal
    $secondProposal = $this->actingAs($owner)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'Buatkan pesanan servis komputer untuk keluhan baterai laptop cepat drop dan charger panas, alamat Jl. Dipatiukur No. 5 Bandung, no hp 081234567890',
        ])
        ->assertOk()
        ->json('action_proposal');

    AssistantActionProposal::query()
        ->whereKey($secondProposal['action_id'])
        ->update(['expires_at' => now()->subMinute()]);

    $this->actingAs($owner)
        ->postJson(route('assistant.actions.confirm', ['proposal' => $secondProposal['action_id']]), [
            'payload_hash' => $secondProposal['payload_hash'],
        ])
        ->assertStatus(410)
        ->assertJsonPath('status', 'expired');

    expect(ServiceOrder::query()->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('auditable_id', $secondProposal['action_id'])->where('event', 'assistant.action_expired')->exists())->toBeTrue();
});

it('requires complete validated fields and re-evaluates authorization before execution', function () {
    createServiceOrderCatalog();
    $customer = User::factory()->create([
        'role' => 'customer',
        'phone' => null,
        'address' => null,
    ]);

    $incomplete = $this->actingAs($customer)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'Buatkan pesanan servis komputer sekarang',
        ])
        ->assertOk();

    expect($incomplete->json('action_proposal'))->toBeNull()
        ->and($incomplete->json('reply'))->toContain('lengkapi detail');

    $validProposal = $this->actingAs($customer)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'Buatkan pesanan servis komputer untuk keluhan laptop sering restart sendiri ketika buka aplikasi berat, alamat Jl. Dago No. 88 Bandung, no hp 081234567890',
        ])
        ->assertOk()
        ->json('action_proposal');

    // User loses active privilege after preview but before confirm
    $customer->update(['is_active' => false]);

    $this->actingAs($customer->fresh())
        ->postJson(route('assistant.actions.confirm', ['proposal' => $validProposal['action_id']]), [
            'payload_hash' => $validProposal['payload_hash'],
        ])
        ->assertForbidden()
        ->assertJsonPath('status', 'denied');

    expect(ServiceOrder::query()->count())->toBe(0);
});

it('never executes a write action solely from an external AI reply', function () {
    createServiceOrderCatalog();
    AiProvider::create([
        'name' => 'OpenAI Test',
        'driver' => 'openai',
        'model' => 'gpt-4o-mini',
        'api_token' => 'sk-test-token',
        'base_url' => 'https://api.openai.com/v1',
        'is_active' => true,
        'is_default' => true,
    ]);

    Http::fake([
        '*' => Http::response([
            'choices' => [[
                'message' => [
                    'content' => 'Pesanan sudah saya buat secara otomatis.',
                ],
            ]],
        ], 200),
    ]);

    $customer = User::factory()->create(['role' => 'customer']);

    $this->actingAs($customer)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'Bagaimana cara merawat baterai laptop agar awet?',
        ])
        ->assertOk();

    expect(ServiceOrder::query()->count())->toBe(0)
        ->and(AssistantActionProposal::query()->count())->toBe(0);
});
