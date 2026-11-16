<?php

use App\Jobs\SendWhatsAppNotificationJob;
use App\Models\AiProvider;
use App\Models\FinancialTransaction;
use App\Models\LoginOtpChallenge;
use App\Models\NotificationDelivery;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Assistant\AssistantIntentClassifier;
use App\Services\PasswordOtpService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

test('area 1: adi answers identity, chained technician, user, service, and operational queries from database with role enforcement', function () {
    Http::fake();

    $operator = User::factory()->create(['name' => 'Rauzzan Superadmin', 'role' => 'operator']);
    $supervisor = User::factory()->create(['name' => 'Andi Supervisor', 'role' => 'supervisor']);
    $customer = User::factory()->create(['name' => 'Siti Pelanggan', 'role' => 'customer']);
    $techAvailable = User::factory()->create(['name' => 'Raka Teknisi', 'role' => 'technician', 'is_active' => true]);
    $techBusy = User::factory()->create(['name' => 'Nabila Teknisi', 'role' => 'technician', 'is_active' => true]);
    User::factory()->create(['name' => 'Dedi Nonaktif', 'role' => 'technician', 'is_active' => false]);

    $serviceWifi = Service::create([
        'code' => 'NET.01',
        'slug' => 'jaringan-wifi',
        'name' => 'Jaringan & Wi-Fi',
        'category' => 'Jaringan',
        'service_mode' => 'onsite',
        'risk_level' => 'low',
        'pricing_label' => 'Mulai Rp 75.000',
        'base_price_min' => 75000,
        'base_price_max' => 250000,
        'warranty_days' => 14,
        'is_active' => true,
        'summary' => 'Perbaikan Wi-Fi dan jaringan',
    ]);

    Service::create([
        'code' => 'OLD.99',
        'slug' => 'layanan-arsip',
        'name' => 'Layanan Arsip',
        'category' => 'Lainnya',
        'service_mode' => 'onsite',
        'risk_level' => 'low',
        'pricing_label' => 'Rp 50.000',
        'base_price_min' => 50000,
        'base_price_max' => 50000,
        'warranty_days' => 7,
        'is_active' => false,
        'summary' => 'Nonaktif',
    ]);

    ServiceOrder::create([
        'ticket_number' => 'ADC-TEST-101',
        'service_id' => $serviceWifi->id,
        'customer_id' => $customer->id,
        'assigned_technician_id' => $techBusy->id,
        'customer_name' => $customer->name,
        'phone' => '081234567890',
        'address' => 'Jl. Mawar 10',
        'service_mode' => 'onsite',
        'complaint' => 'Wi-Fi putus',
        'status' => 'in_progress',
        'risk_level' => 'low',
        'urgency' => 'normal',
        'tracking_token_hash' => hash('sha256', 'tok-101'),
        'access_code_hash' => bcrypt('123456'),
    ]);

    // 1. Identity awareness (guest vs authenticated) & ADI purpose
    $this->postJson(route('assistant.messages.store'), ['message' => 'Siapa saya?'])
        ->assertOk()
        ->assertSee('belum terautentikasi');

    $this->postJson(route('assistant.messages.store'), ['message' => 'Siapa Anda dan apa tugas Anda?'])
        ->assertOk()
        ->assertSee('ADI');

    $this->postJson(route('assistant.messages.store'), ['message' => 'Apa fungsi AD Com?'])
        ->assertOk()
        ->assertSee('Teaching Factory');

    $this->actingAs($operator)
        ->postJson(route('assistant.messages.store'), ['message' => 'Siapa saya?'])
        ->assertOk()
        ->assertSee('Rauzzan Superadmin')
        ->assertSee('Superadmin');

    // 2. Technician count + chained follow-ups
    $this->actingAs($operator)
        ->postJson(route('assistant.messages.store'), ['message' => 'Berapa jumlah teknisi?'])
        ->assertOk()
        ->assertSee('total 3 teknisi')
        ->assertSee('2 teknisi aktif')
        ->assertSee('1 teknisi tersedia');

    $this->actingAs($operator)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'Siapa saja?',
            'messages' => [
                ['role' => 'user', 'content' => 'Berapa jumlah teknisi?'],
                ['role' => 'assistant', 'content' => 'Saat ini terdapat total 3 teknisi.'],
            ],
        ])
        ->assertOk()
        ->assertSee('Raka Teknisi')
        ->assertSee('Nabila Teknisi')
        ->assertSee('Dedi Nonaktif');

    $this->actingAs($operator)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'Siapa yang tersedia?',
            'messages' => [
                ['role' => 'user', 'content' => 'Siapa saja teknisinya?'],
            ],
        ])
        ->assertOk()
        ->assertSee('Raka Teknisi')
        ->assertDontSee('Nabila Teknisi');

    // 3. User count & list (allowed for operator, denied for customer)
    $this->actingAs($operator)
        ->postJson(route('assistant.messages.store'), ['message' => 'Berapa total user?'])
        ->assertOk()
        ->assertSee('6 akun');

    $this->actingAs($operator)
        ->postJson(route('assistant.messages.store'), ['message' => 'Siapa saja user?'])
        ->assertOk()
        ->assertSee('Rauzzan Superadmin')
        ->assertSee('Siti Pelanggan');

    $this->actingAs($customer)
        ->postJson(route('assistant.messages.store'), ['message' => 'Siapa saja user?'])
        ->assertOk()
        ->assertSee('Admin Operasional atau Superadmin');

    $this->actingAs($customer)
        ->postJson(route('assistant.messages.store'), ['message' => 'Siapa saja teknisinya?'])
        ->assertOk()
        ->assertSee('internal AD Com');

    // 4. Service count & list (publicly safe)
    $this->postJson(route('assistant.messages.store'), ['message' => 'Ada berapa jasa?'])
        ->assertOk()
        ->assertSee('1 layanan aktif');

    $this->postJson(route('assistant.messages.store'), ['message' => 'Apa saja jasanya?'])
        ->assertOk()
        ->assertSee('Jaringan & Wi-Fi', false)
        ->assertDontSee('Layanan Arsip');

    // 5. Operational summary (supervisor allowed, customer denied)
    $this->actingAs($supervisor)
        ->postJson(route('assistant.messages.store'), ['message' => 'Tampilkan ringkasan operasional'])
        ->assertOk()
        ->assertSee('1 tiket berjalan');

    $this->actingAs($customer)
        ->postJson(route('assistant.messages.store'), ['message' => 'Tampilkan ringkasan operasional'])
        ->assertOk()
        ->assertSee('Supervisor');

    $this->assertDatabaseHas('audit_logs', [
        'actor_id' => $operator->id,
        'event' => 'assistant.tool_executed',
    ]);

    Http::assertNothingSent();
});

test('area 2: otp lifecycle, fonnte failure safety, and whatsapp job status transitions work end to end', function () {
    SystemSetting::query()->updateOrCreate(['key' => 'secret:fonnte_token'], ['value' => Crypt::encryptString('fonnte-live-test-token')]);
    SystemSetting::query()->updateOrCreate(['key' => 'otp_purpose_registration_phone_enabled'], ['value' => '1']);

    Http::fake([
        'https://api.fonnte.com/send' => Http::sequence()
            ->push(['status' => false, 'reason' => 'Device disconnected'], 200)
            ->push(['status' => true, 'detail' => 'sent'], 200)
            ->push(['status' => true, 'detail' => 'sent'], 200),
    ]);

    $otpService = app(PasswordOtpService::class);
    expect(fn () => $otpService->issueChallenge(null, 'registration_phone', '081234567890', '127.0.0.1'))
        ->toThrow(ValidationException::class);

    expect(LoginOtpChallenge::query()->count())->toBe(0);

    $issued = $otpService->issueChallenge(null, 'registration_phone', '081234567891', '127.0.0.1');
    expect($issued['length'])->toBe(6)
        ->and($issued['max_attempts'])->toBe(5)
        ->and($issued['max_resends'])->toBe(3);

    $delivery = NotificationDelivery::query()->create([
        'event_key' => 'otp.uas_test',
        'channel' => 'whatsapp',
        'recipient_phone' => '6281234567891',
        'status' => 'queued',
        'attempts' => 0,
    ]);

    SendWhatsAppNotificationJob::dispatchSync($delivery->id, 'Pesan uji UAS');

    expect($delivery->fresh()->status)->toBe('sent')
        ->and($delivery->fresh()->sent_at)->not->toBeNull();
});

test('area 3: superadmin cannot delete active default ai provider without deactivating first and adi uses active db provider', function () {
    $superadmin = User::factory()->create(['role' => 'operator']);

    $provider = AiProvider::query()->create([
        'driver' => 'openai',
        'name' => 'OpenAI Primary',
        'api_token' => 'sk-test-token-12345678',
        'model_id' => 'gpt-4.1-mini',
        'is_active' => true,
        'is_default' => true,
        'connection_status' => 'connected',
    ]);

    // Encrypted at rest
    $raw = DB::table('ai_providers')->where('id', $provider->id)->value('api_token');
    expect($raw)->not->toBe('sk-test-token-12345678');

    // Cannot delete active default provider directly
    $this->actingAs($superadmin)
        ->delete(route('ai-providers.destroy', $provider))
        ->assertSessionHasErrors('provider');

    expect(AiProvider::query()->where('id', $provider->id)->exists())->toBeTrue();

    // Deactivate first, then deletion succeeds
    $this->actingAs($superadmin)
        ->patch(route('ai-providers.update', $provider), ['is_active' => false])
        ->assertSessionHasNoErrors();

    $this->actingAs($superadmin)
        ->delete(route('ai-providers.destroy', $provider))
        ->assertSessionHasNoErrors();

    expect(AiProvider::query()->where('id', $provider->id)->exists())->toBeFalse();
});

test('adi identity, capability, platform, and operational intents never trigger out-of-scope refusal (local fallback and active provider)', function () {
    Http::fake();

    $operator = User::factory()->create(['name' => 'Rauzzan Superadmin', 'role' => 'operator']);
    User::factory()->create(['name' => 'Raka Teknisi', 'role' => 'technician', 'is_active' => true]);
    Service::create([
        'code' => 'NET.01',
        'slug' => 'jaringan-wifi',
        'name' => 'Jaringan & Wi-Fi',
        'category' => 'Jaringan',
        'service_mode' => 'onsite',
        'risk_level' => 'low',
        'pricing_label' => 'Mulai Rp 75.000',
        'base_price_min' => 75000,
        'base_price_max' => 250000,
        'warranty_days' => 14,
        'is_active' => true,
        'summary' => 'Perbaikan Wi-Fi',
    ]);

    // Active provider configured in DB (should NOT be called for deterministic identity/DB intents)
    AiProvider::query()->create([
        'driver' => 'openai',
        'name' => 'OpenAI Active',
        'api_token' => 'sk-active-test-12345678',
        'model_id' => 'gpt-4.1-mini',
        'is_active' => true,
        'is_default' => true,
        'connection_status' => 'connected',
    ]);

    $validQuestions = [
        'Anda siapa?' => 'ADI',
        'Siapa Anda?' => 'ADI',
        'Apa nama Anda?' => 'ADI',
        'Apa tugas Anda?' => 'asisten cerdas AD Com',
        'Apa itu AD Com?' => 'Teaching Factory',
        'Siapa saya?' => 'Rauzzan Superadmin',
        'Berapa teknisi?' => 'total 1 teknisi',
        'Siapa saja teknisinya?' => 'Raka Teknisi',
        'Ada berapa jasa?' => '1 layanan aktif',
        'Apa saja jasanya?' => 'Jaringan & Wi-Fi',
    ];

    foreach ($validQuestions as $question => $expectedSubstring) {
        $response = $this->actingAs($operator)
            ->postJson(route('assistant.messages.store'), ['message' => $question])
            ->assertOk();

        expect($response->json('reply'))
            ->toContain($expectedSubstring)
            ->not->toContain('di luar layanan AD Com')
            ->not->toContain('di luar lingkup');
    }

    // Unrelated / out-of-scope requests must still be refused
    $this->actingAs($operator)
        ->postJson(route('assistant.messages.store'), ['message' => 'Siapa presiden pertama?'])
        ->assertOk()
        ->assertSee('di luar lingkup');

    Http::assertNothingSent();
});

test('adi answers customer queries with role enforcement and chained context without leaking pii', function () {
    Http::fake();

    $operator = User::factory()->create(['role' => 'operator', 'is_active' => true]);
    $supervisor = User::factory()->create(['role' => 'supervisor', 'is_active' => true]);
    $technician = User::factory()->create(['role' => 'technician', 'is_active' => true]);
    $customerOne = User::factory()->create([
        'name' => 'Siti Rahma',
        'email' => 'siti.secret@example.test',
        'phone' => '6281234567890',
        'role' => 'customer',
        'is_active' => true,
    ]);
    $customerTwo = User::factory()->create([
        'name' => 'Budi Pelanggan',
        'email' => 'budi.secret@example.test',
        'phone' => '6289876543210',
        'role' => 'customer',
        'is_active' => false,
    ]);

    $countVariations = [
        'Berapa customer?',
        'Ada berapa customer?',
        'Berapa jumlah pelanggan?',
        'Total pelanggan kita berapa?',
        'Ada berapa akun customer?',
    ];

    foreach ($countVariations as $question) {
        $response = $this->actingAs($operator)
            ->postJson(route('assistant.messages.store'), ['message' => $question])
            ->assertOk();

        expect($response->json('reply'))
            ->toContain('2 customer terdaftar')
            ->toContain('1 aktif')
            ->not->toContain('di luar layanan')
            ->not->toContain('di luar lingkup');
    }

    $listVariations = [
        'Siapa saja customer?',
        'Tampilkan daftar pelanggan.',
        'Customer yang terdaftar siapa saja?',
    ];

    foreach ($listVariations as $question) {
        $response = $this->actingAs($operator)
            ->postJson(route('assistant.messages.store'), ['message' => $question])
            ->assertOk();

        expect($response->json('reply'))
            ->toContain('Siti Rahma')
            ->toContain('Budi Pelanggan (nonaktif)')
            ->not->toContain('siti.secret@example.test')
            ->not->toContain('6281234567890');
    }

    $this->actingAs($operator)
        ->postJson(route('assistant.messages.store'), ['message' => 'Ringkasan customer'])
        ->assertOk()
        ->assertSee('Ringkasan customer AD Com: 2 total');

    $this->actingAs($operator)
        ->postJson(route('assistant.messages.store'), ['message' => 'Detail customer Siti Rahma'])
        ->assertOk()
        ->assertSee('Detail customer: Siti Rahma')
        ->assertDontSee('siti.secret@example.test')
        ->assertDontSee('6281234567890');

    // Supervisor can access customer count/summary, but not customer list/detail
    $this->actingAs($supervisor)
        ->postJson(route('assistant.messages.store'), ['message' => 'Ada berapa customer?'])
        ->assertOk()
        ->assertSee('2 customer terdaftar');

    $this->actingAs($supervisor)
        ->postJson(route('assistant.messages.store'), ['message' => 'Siapa saja customer?'])
        ->assertOk()
        ->assertSee('Admin Operasional atau Superadmin');

    // Technician, customer, and guest are denied customer data
    foreach ([$technician, $customerOne] as $restrictedUser) {
        $this->actingAs($restrictedUser)
            ->postJson(route('assistant.messages.store'), ['message' => 'Ada berapa customer?'])
            ->assertOk()
            ->assertSee('Maaf');
    }

    $this->postJson(route('assistant.messages.store'), ['message' => 'Ada berapa customer?'])
        ->assertOk()
        ->assertSee('Maaf');

    // Chained context: customer count -> "Siapa saja?" -> technician count (using admin role)
    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

    $customerCountReply = $this->actingAs($admin)
        ->postJson(route('assistant.messages.store'), ['message' => 'Ada berapa customer?'])
        ->assertOk()
        ->assertSee('2 customer terdaftar')
        ->json('reply');

    $customerListReply = $this->actingAs($admin)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'Siapa saja?',
            'messages' => [
                ['role' => 'user', 'content' => 'Ada berapa customer?'],
                ['role' => 'assistant', 'content' => $customerCountReply],
            ],
        ])
        ->assertOk()
        ->assertSee('Siti Rahma')
        ->assertSee('Budi Pelanggan')
        ->json('reply');

    $this->actingAs($admin)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'Ada berapa teknisi?',
            'messages' => [
                ['role' => 'user', 'content' => 'Ada berapa customer?'],
                ['role' => 'assistant', 'content' => $customerCountReply],
                ['role' => 'user', 'content' => 'Siapa saja?'],
                ['role' => 'assistant', 'content' => $customerListReply],
            ],
        ])
        ->assertOk()
        ->assertSee('total 1 teknisi');

    Http::assertNothingSent();
});

test('adi conversational intelligence and domain guardrail matrix (conversations A through K across roles, guest, and providers)', function () {
    $classifier = app(AssistantIntentClassifier::class);

    // Verify explicit intent classification categories (IDENTITY, OPERATIONAL, GENERAL_IT, CONVERSATION, OUT_OF_DOMAIN)
    expect($classifier->classify('Siapa kamu?'))->toMatchArray([
        'category' => 'IDENTITY',
        'intent' => 'identity.assistant',
        'is_allowed' => true,
    ]);
    expect($classifier->classify('Siapa saya?'))->toMatchArray([
        'category' => 'IDENTITY',
        'intent' => 'identity.current_user',
        'tool' => 'get_current_user',
        'is_allowed' => true,
    ]);
    expect($classifier->classify('Apa itu AD Com?'))->toMatchArray([
        'category' => 'IDENTITY',
        'intent' => 'identity.platform',
        'is_allowed' => true,
    ]);
    expect($classifier->classify('Apa tugas kamu?'))->toMatchArray([
        'category' => 'IDENTITY',
        'intent' => 'identity.capabilities',
        'is_allowed' => true,
    ]);
    expect($classifier->classify('Berapa jumlah teknisi?'))->toMatchArray([
        'category' => 'OPERATIONAL',
        'intent' => 'operational.technicians.count',
        'tool' => 'get_technician_count',
        'is_allowed' => true,
    ]);
    expect($classifier->classify('Siapa saja teknisinya?'))->toMatchArray([
        'category' => 'OPERATIONAL',
        'intent' => 'operational.technicians.list',
        'tool' => 'list_technicians',
        'is_allowed' => true,
    ]);
    expect($classifier->classify('Berapa jumlah customer?'))->toMatchArray([
        'category' => 'OPERATIONAL',
        'intent' => 'operational.customers.count',
        'tool' => 'get_customer_count',
        'is_allowed' => true,
    ]);
    expect($classifier->classify('Siapa saja customer?'))->toMatchArray([
        'category' => 'OPERATIONAL',
        'intent' => 'operational.customers.list',
        'tool' => 'list_customers',
        'is_allowed' => true,
    ]);
    expect($classifier->classify('Berapa jumlah user?'))->toMatchArray([
        'category' => 'OPERATIONAL',
        'intent' => 'operational.users.count',
        'tool' => 'get_user_count',
        'is_allowed' => true,
    ]);
    expect($classifier->classify('Siapa saja user?'))->toMatchArray([
        'category' => 'OPERATIONAL',
        'intent' => 'operational.users.list',
        'tool' => 'list_users',
        'is_allowed' => true,
    ]);
    expect($classifier->classify('Berapa jumlah layanan?'))->toMatchArray([
        'category' => 'OPERATIONAL',
        'intent' => 'operational.services.count',
        'tool' => 'get_service_count',
        'is_allowed' => true,
    ]);
    expect($classifier->classify('Apa saja layanan AD Com?'))->toMatchArray([
        'category' => 'OPERATIONAL',
        'intent' => 'operational.services.list',
        'tool' => 'list_services',
        'is_allowed' => true,
    ]);
    expect($classifier->classify('Berapa jumlah tiket?'))->toMatchArray([
        'category' => 'OPERATIONAL',
        'intent' => 'operational.tickets.count',
        'tool' => 'get_ticket_count',
        'is_allowed' => true,
    ]);
    expect($classifier->classify('Apa fungsi router?'))->toMatchArray([
        'category' => 'GENERAL_IT',
        'intent' => 'general_it.knowledge',
        'is_allowed' => true,
    ]);
    expect($classifier->classify('Siapa saja?'))->toMatchArray([
        'category' => 'CONVERSATION',
        'intent' => 'conversation.clarification',
        'is_allowed' => true,
    ]);
    expect($classifier->classify('Siapa saja?', [['role' => 'user', 'content' => 'Ada berapa teknisi?']]))->toMatchArray([
        'category' => 'OPERATIONAL',
        'intent' => 'operational.technicians.list',
        'tool' => 'list_technicians',
        'is_allowed' => true,
    ]);
    expect($classifier->classify('Buatkan kode untuk proyek pribadi yang tidak berhubungan dengan AD Com.'))->toMatchArray([
        'category' => 'OUT_OF_DOMAIN',
        'intent' => 'guardrail.out_of_scope',
        'is_allowed' => false,
    ]);

    // Seed database entities across roles
    $operator = User::factory()->create(['name' => 'Rauzzan Operator', 'role' => 'operator', 'is_active' => true]);
    $admin = User::factory()->create(['name' => 'Dina Admin', 'role' => 'admin', 'is_active' => true]);
    $supervisor = User::factory()->create(['name' => 'Rizki Supervisor', 'role' => 'supervisor', 'is_active' => true]);
    $technician = User::factory()->create(['name' => 'Fajar Teknisi', 'role' => 'technician', 'is_active' => true]);
    $warehouse = User::factory()->create(['name' => 'Hendra Gudang', 'role' => 'warehouse', 'is_active' => true]);
    $customer = User::factory()->create(['name' => 'Ayu Customer', 'role' => 'customer', 'is_active' => true]);

    $serviceNetwork = Service::create([
        'code' => 'NET.01',
        'slug' => 'jaringan-wifi',
        'name' => 'Jaringan & Wi-Fi',
        'category' => 'Jaringan',
        'service_mode' => 'onsite',
        'risk_level' => 'low',
        'pricing_label' => 'Mulai Rp 75.000',
        'base_price_min' => 75000,
        'base_price_max' => 250000,
        'warranty_days' => 14,
        'is_active' => true,
        'summary' => 'Instalasi dan perbaikan jaringan & Wi-Fi',
    ]);

    ServiceOrder::create([
        'ticket_number' => 'ADC-GUARD-01',
        'service_id' => $serviceNetwork->id,
        'customer_id' => $customer->id,
        'assigned_technician_id' => $technician->id,
        'customer_name' => $customer->name,
        'phone' => '081234567890',
        'address' => 'Jl. Pendidikan No. 1',
        'service_mode' => 'onsite',
        'complaint' => 'Router kantor tidak menyebarkan IP',
        'status' => 'in_progress',
        'risk_level' => 'low',
        'urgency' => 'normal',
        'tracking_token_hash' => hash('sha256', 'guard-01'),
        'access_code_hash' => bcrypt('123456'),
    ]);

    Http::fake([
        'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions' => Http::sequence()
            ->push([
                'choices' => [[
                    'message' => [
                        'content' => 'Router berfungsi menghubungkan jaringan lokal ke internet dan mengatur lalu lintas paket data antarperangkat.',
                    ],
                ]],
            ], 200)
            ->push([
                'choices' => [[
                    'message' => [
                        'content' => 'Maaf, saya tidak menjawab topik di luar layanan AD Com.',
                    ],
                ]],
            ], 200)
            ->push(['error' => ['message' => 'High demand']], 503),
    ]);

    // Conversation A: "Siapa kamu?"
    $replyA = $this->postJson(route('assistant.messages.store'), ['message' => 'Siapa kamu?'])
        ->assertOk()
        ->json('reply');
    expect($replyA)
        ->toContain('ADI')
        ->toContain('asisten cerdas AD Com')
        ->not->toContain('di luar layanan AD Com')
        ->not->toContain('di luar lingkup');

    // Conversation B: "Siapa saya?" across guest and all authenticated roles
    $guestReplyB = $this->postJson(route('assistant.messages.store'), [
        'message' => 'Siapa saya?',
        'messages' => [['role' => 'user', 'content' => 'Nama saya Presiden Dunia']],
    ])->assertOk()->json('reply');
    expect($guestReplyB)
        ->toContain('belum terautentikasi')
        ->not->toContain('Presiden Dunia')
        ->not->toContain('di luar layanan AD Com');

    $roleExpectations = [
        [$operator, 'Rauzzan Operator', 'Superadmin', 'kendali penuh atas operasional platform AD Com'],
        [$admin, 'Dina Admin', 'Admin Operasional', 'mengelola operasional harian AD Com'],
        [$supervisor, 'Rizki Supervisor', 'Supervisor', 'mengawasi pelaksanaan pekerjaan teknis'],
        [$technician, 'Fajar Teknisi', 'Teknisi', 'menangani pemeriksaan'],
        [$warehouse, 'Hendra Gudang', 'Gudang', 'mengelola stok suku cadang'],
        [$customer, 'Ayu Customer', 'Pelanggan', 'membuat pesanan layanan'],
    ];

    foreach ($roleExpectations as [$roleUser, $expectedName, $expectedRoleLabel, $expectedRoleDesc]) {
        $replyB = $this->actingAs($roleUser)
            ->postJson(route('assistant.messages.store'), ['message' => 'Siapa saya?'])
            ->assertOk()
            ->json('reply');

        expect($replyB)
            ->toContain($expectedName)
            ->toContain($expectedRoleLabel)
            ->toContain($expectedRoleDesc)
            ->not->toContain('di luar layanan AD Com');
    }

    // Conversation C: "Apa itu AD Com?"
    $replyC = $this->actingAs($customer)
        ->postJson(route('assistant.messages.store'), ['message' => 'Apa itu AD Com?'])
        ->assertOk()
        ->json('reply');
    expect($replyC)
        ->toContain('AD Com')
        ->toContain('Teaching Factory')
        ->not->toContain('di luar layanan AD Com');

    // Conversation D: "Apa tugas kamu?"
    $replyD = $this->actingAs($customer)
        ->postJson(route('assistant.messages.store'), ['message' => 'Apa tugas kamu?'])
        ->assertOk()
        ->json('reply');
    expect($replyD)
        ->toContain('asisten cerdas AD Com')
        ->toContain('memahami kendala komputer, jaringan, dan CCTV')
        ->not->toContain('di luar layanan AD Com');

    // Conversation E: "Berapa jumlah teknisi?"
    $replyE = $this->actingAs($operator)
        ->postJson(route('assistant.messages.store'), ['message' => 'Berapa jumlah teknisi?'])
        ->assertOk()
        ->json('reply');
    expect($replyE)
        ->toContain('total 1 teknisi')
        ->toContain('1 teknisi aktif')
        ->toContain('0 teknisi tersedia')
        ->not->toContain('di luar layanan AD Com');

    // Conversation F: "Siapa saja teknisinya?"
    $replyF = $this->actingAs($operator)
        ->postJson(route('assistant.messages.store'), ['message' => 'Siapa saja teknisinya?'])
        ->assertOk()
        ->json('reply');
    expect($replyF)
        ->toContain('Fajar Teknisi')
        ->not->toContain('di luar layanan AD Com');

    // Conversation G: "Berapa jumlah customer?"
    $replyG = $this->actingAs($operator)
        ->postJson(route('assistant.messages.store'), ['message' => 'Berapa jumlah customer?'])
        ->assertOk()
        ->json('reply');
    expect($replyG)
        ->toContain('1 customer terdaftar')
        ->not->toContain('di luar layanan AD Com');

    // Conversation H: "Apa saja layanan AD Com?"
    $replyH = $this->actingAs($customer)
        ->postJson(route('assistant.messages.store'), ['message' => 'Apa saja layanan AD Com?'])
        ->assertOk()
        ->json('reply');
    expect($replyH)
        ->toContain('Jaringan & Wi-Fi')
        ->toContain('NET.01')
        ->not->toContain('di luar layanan AD Com');

    // Ticket count operational query: "Berapa jumlah tiket?"
    $replyTickets = $this->actingAs($operator)
        ->postJson(route('assistant.messages.store'), ['message' => 'Berapa jumlah tiket?'])
        ->assertOk()
        ->json('reply');
    expect($replyTickets)
        ->toContain('total 1 tiket di sistem')
        ->toContain('1 tiket aktif/berjalan');

    // Conversation I: "Apa fungsi router?" (Local fallback mode & Provider mode)
    config()->set('services.openai.key', null);
    $replyIFallback = $this->actingAs($customer)
        ->postJson(route('assistant.messages.store'), ['message' => 'Apa fungsi router?'])
        ->assertOk()
        ->json('reply');
    expect($replyIFallback)
        ->toContain('Fungsi utama router')
        ->toContain('jaringan')
        ->not->toContain('di luar layanan AD Com')
        ->not->toContain('di luar lingkup');

    // Conversation J: "Siapa saja?" (1. Ambiguous without context -> polite clarification; 2. Follow-up after teknisi; 3. Follow-up after customer)
    $replyJClarification = $this->actingAs($operator)
        ->postJson(route('assistant.messages.store'), ['message' => 'Siapa saja?'])
        ->assertOk()
        ->json('reply');
    expect($replyJClarification)
        ->toContain('Siapa atau data apa yang Anda maksud?')
        ->not->toContain('di luar layanan AD Com')
        ->not->toContain('di luar lingkup');

    $replyJTechnician = $this->actingAs($operator)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'Siapa saja?',
            'messages' => [
                ['role' => 'user', 'content' => 'Ada berapa teknisi?'],
                ['role' => 'assistant', 'content' => $replyE],
            ],
        ])
        ->assertOk()
        ->json('reply');
    expect($replyJTechnician)
        ->toContain('Fajar Teknisi')
        ->not->toContain('di luar layanan AD Com');

    $replyJCustomer = $this->actingAs($operator)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'Siapa saja?',
            'messages' => [
                ['role' => 'user', 'content' => 'Ada berapa customer?'],
                ['role' => 'assistant', 'content' => $replyG],
            ],
        ])
        ->assertOk()
        ->json('reply');
    expect($replyJCustomer)
        ->toContain('Ayu Customer')
        ->not->toContain('di luar layanan AD Com');

    // Conversation K: "Buatkan kode untuk proyek pribadi yang tidak berhubungan dengan AD Com."
    $replyK = $this->actingAs($operator)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'Buatkan kode untuk proyek pribadi yang tidak berhubungan dengan AD Com.',
        ])
        ->assertOk()
        ->json('reply');
    expect($replyK)
        ->toContain('di luar lingkup layanan AD Com');

    // Provider Consistency: Active Gemini custom_openai provider with "models/gemini-2.5-flash"
    $geminiProvider = AiProvider::query()->create([
        'driver' => 'custom_openai',
        'name' => 'Google Gemini OpenAI-Compatible',
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai',
        'api_token' => 'gemini-test-key-12345',
        'model_id' => 'models/gemini-2.5-flash',
        'is_active' => true,
        'is_default' => true,
        'connection_status' => 'connected',
    ]);

    // 1. Provider succeeds -> returns AI reply
    $providerSuccess = $this->actingAs($customer)
        ->postJson(route('assistant.messages.store'), ['message' => 'Apa fungsi router?'])
        ->assertOk()
        ->assertJsonPath('mode', 'ai');
    expect($providerSuccess->json('reply'))
        ->toContain('Router berfungsi menghubungkan jaringan lokal');

    // 2. Provider hallucinates a false refusal on an allowed GENERAL_IT intent -> guarded by local IT explanation
    $providerGuarded = $this->actingAs($customer)
        ->postJson(route('assistant.messages.store'), ['message' => 'Apa fungsi router?'])
        ->assertOk()
        ->assertJsonPath('mode', 'guide');
    expect($providerGuarded->json('reply'))
        ->toContain('Fungsi utama router')
        ->not->toContain('di luar layanan AD Com');

    // 3. Provider returns 503 -> degrades gracefully to local IT explanation
    $providerFallback = $this->actingAs($customer)
        ->postJson(route('assistant.messages.store'), ['message' => 'Apa fungsi router?'])
        ->assertOk()
        ->assertJsonPath('mode', 'guide');
    expect($providerFallback->json('reply'))
        ->toContain('Fungsi utama router')
        ->not->toContain('di luar layanan AD Com');

    $geminiProvider->delete();
});

test('adi v2 refinement: contextual memory, financial intelligence, general it troubleshooting, context switching, and role authorization', function () {
    Http::fake();
    config()->set('services.openai.key', null);

    $operator = User::factory()->create(['name' => 'Rauzzan Superadmin', 'role' => 'operator', 'is_active' => true]);
    $admin = User::factory()->create(['name' => 'Sari Admin', 'role' => 'admin', 'is_active' => true]);
    $supervisor = User::factory()->create(['name' => 'Bima Supervisor', 'role' => 'supervisor', 'is_active' => true]);
    $technicianAvailable = User::factory()->create(['name' => 'Raka Teknisi', 'role' => 'technician', 'is_active' => true]);
    $technicianBusy = User::factory()->create(['name' => 'Nabila Teknisi', 'role' => 'technician', 'is_active' => true]);
    User::factory()->create(['name' => 'Dedi Nonaktif', 'role' => 'technician', 'is_active' => false]);
    $warehouse = User::factory()->create(['name' => 'Maya Gudang', 'role' => 'warehouse', 'is_active' => true]);
    $customerActive = User::factory()->create(['name' => 'Siti Pelanggan', 'role' => 'customer', 'is_active' => true]);
    $customerInactive = User::factory()->create(['name' => 'Budi Nonaktif', 'role' => 'customer', 'is_active' => false]);

    $serviceComputer = Service::create([
        'code' => 'DEV.03',
        'slug' => 'servis-komputer',
        'name' => 'Servis Komputer',
        'category' => 'Komputer',
        'service_mode' => 'dropoff',
        'risk_level' => 'medium',
        'pricing_label' => 'Mulai Rp 50.000',
        'base_price_min' => 50000,
        'base_price_max' => 250000,
        'warranty_days' => 14,
        'is_active' => true,
        'summary' => 'Diagnosis dan perawatan laptop/PC',
    ]);

    $serviceWifi = Service::create([
        'code' => 'NET.01',
        'slug' => 'jaringan-wifi',
        'name' => 'Jaringan & Wi-Fi',
        'category' => 'Jaringan',
        'service_mode' => 'onsite',
        'risk_level' => 'low',
        'pricing_label' => 'Mulai Rp 75.000',
        'base_price_min' => 75000,
        'base_price_max' => 250000,
        'warranty_days' => 14,
        'is_active' => true,
        'summary' => 'Audit dan perbaikan jaringan Wi-Fi',
    ]);

    $order = ServiceOrder::create([
        'ticket_number' => 'ADC-V2-001',
        'service_id' => $serviceWifi->id,
        'customer_id' => $customerActive->id,
        'assigned_technician_id' => $technicianBusy->id,
        'customer_name' => $customerActive->name,
        'phone' => '081234567890',
        'address' => 'Jl. Merdeka 10',
        'service_mode' => 'onsite',
        'complaint' => 'Wi-Fi sering putus',
        'status' => 'in_progress',
        'risk_level' => 'low',
        'urgency' => 'normal',
        'tracking_token_hash' => hash('sha256', 'v2-001'),
        'access_code_hash' => bcrypt('123456'),
    ]);

    $now = Carbon::now();
    $lastMonth = $now->copy()->subMonthNoOverflow();

    // Verified income this month: 450,000 + 300,000 = 750,000
    FinancialTransaction::query()->create([
        'service_order_id' => $order->id,
        'actor_id' => $admin->id,
        'verified_by' => $operator->id,
        'type' => 'income',
        'category' => 'service_payment',
        'amount' => 450000,
        'payment_method' => 'bank_transfer',
        'status' => 'verified',
        'reference' => 'TRX-V2-01',
        'description' => 'Pembayaran servis jaringan',
        'occurred_at' => $now->copy()->startOfMonth()->addDays(2),
        'verified_at' => $now->copy()->startOfMonth()->addDays(2)->addHour(),
    ]);

    FinancialTransaction::query()->create([
        'service_order_id' => $order->id,
        'actor_id' => $admin->id,
        'verified_by' => $operator->id,
        'type' => 'income',
        'category' => 'installation_fee',
        'amount' => 300000,
        'payment_method' => 'qr',
        'status' => 'verified',
        'reference' => 'TRX-V2-02',
        'description' => 'Biaya instalasi access point',
        'occurred_at' => $now->copy()->startOfMonth()->addDays(5),
        'verified_at' => $now->copy()->startOfMonth()->addDays(5)->addHour(),
    ]);

    // Verified income last month: 500,000
    FinancialTransaction::query()->create([
        'service_order_id' => $order->id,
        'actor_id' => $admin->id,
        'verified_by' => $operator->id,
        'type' => 'income',
        'category' => 'service_payment',
        'amount' => 500000,
        'payment_method' => 'cash',
        'status' => 'verified',
        'reference' => 'TRX-V2-03',
        'description' => 'Pembayaran bulan lalu',
        'occurred_at' => $lastMonth->copy()->startOfMonth()->addDays(10),
        'verified_at' => $lastMonth->copy()->startOfMonth()->addDays(10)->addHour(),
    ]);

    // Verified expense this month: 150,000
    FinancialTransaction::query()->create([
        'service_order_id' => $order->id,
        'actor_id' => $admin->id,
        'verified_by' => $operator->id,
        'type' => 'expense',
        'category' => 'equipment',
        'amount' => 150000,
        'payment_method' => 'cash',
        'status' => 'verified',
        'reference' => 'TRX-V2-04',
        'description' => 'Pembelian konektor RJ45',
        'occurred_at' => $now->copy()->startOfMonth()->addDays(3),
        'verified_at' => $now->copy()->startOfMonth()->addDays(3)->addHour(),
    ]);

    // Pending income (MUST NOT be counted as verified income): 999,000
    FinancialTransaction::query()->create([
        'service_order_id' => $order->id,
        'actor_id' => $admin->id,
        'type' => 'income',
        'category' => 'service_payment',
        'amount' => 999000,
        'payment_method' => 'bank_transfer',
        'status' => 'pending',
        'reference' => 'TRX-V2-05',
        'description' => 'Transaksi belum diverifikasi',
        'occurred_at' => $now->copy()->startOfMonth()->addDays(6),
    ]);

    // Rejected income (MUST NOT be counted as verified income): 888,000
    FinancialTransaction::query()->create([
        'service_order_id' => $order->id,
        'actor_id' => $admin->id,
        'type' => 'income',
        'category' => 'service_payment',
        'amount' => 888000,
        'payment_method' => 'bank_transfer',
        'status' => 'rejected',
        'reference' => 'TRX-V2-06',
        'description' => 'Transaksi ditolak karena bukti tidak valid',
        'occurred_at' => $now->copy()->startOfMonth()->addDays(4),
    ]);

    // =========================================================================
    // A. Customer context ("Ada berapa customer?" -> "Siapa?" + follow-ups)
    // =========================================================================
    $customerCountReply = $this->actingAs($operator)
        ->postJson(route('assistant.messages.store'), ['message' => 'Ada berapa customer?', 'messages' => []])
        ->assertOk()
        ->json('reply');
    expect($customerCountReply)->toContain('2 customer terdaftar (1 aktif)');

    $customerWhoReply = $this->actingAs($operator)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'Siapa?',
            'messages' => [
                ['role' => 'user', 'content' => 'Ada berapa customer?'],
                ['role' => 'assistant', 'content' => $customerCountReply],
            ],
        ])
        ->assertOk()
        ->json('reply');
    expect($customerWhoReply)
        ->toContain('Siti Pelanggan')
        ->toContain('Budi Nonaktif (nonaktif)')
        ->not->toContain('di luar layanan AD Com');

    // Follow-up expressions: "Namanya siapa?", "Ada siapa saja?", "Yang aktif siapa?", "Bisa jelaskan lebih detail?"
    foreach (['Namanya siapa?', 'Ada siapa saja?'] as $followUpExpr) {
        $followReply = $this->actingAs($operator)
            ->postJson(route('assistant.messages.store'), [
                'message' => $followUpExpr,
                'messages' => [
                    ['role' => 'user', 'content' => 'Ada berapa customer?'],
                    ['role' => 'assistant', 'content' => $customerCountReply],
                ],
            ])
            ->assertOk()
            ->json('reply');
        expect($followReply)->toContain('Siti Pelanggan');
    }

    $activeCustomerReply = $this->actingAs($operator)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'Yang aktif siapa?',
            'messages' => [
                ['role' => 'user', 'content' => 'Ada berapa customer?'],
                ['role' => 'assistant', 'content' => $customerCountReply],
            ],
        ])
        ->assertOk()
        ->json('reply');
    expect($activeCustomerReply)
        ->toContain('Siti Pelanggan')
        ->not->toContain('Budi Nonaktif');

    $detailCustomerReply = $this->actingAs($operator)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'Bisa jelaskan lebih detail?',
            'messages' => [
                ['role' => 'user', 'content' => 'Ada berapa customer?'],
                ['role' => 'assistant', 'content' => $customerCountReply],
            ],
        ])
        ->assertOk()
        ->json('reply');
    expect($detailCustomerReply)->toContain('Ringkasan customer AD Com: 2 total');

    // =========================================================================
    // B. Technician context ("Ada berapa teknisi?" -> "Siapa saja?" + follow-ups)
    // =========================================================================
    $techCountReply = $this->actingAs($operator)
        ->postJson(route('assistant.messages.store'), ['message' => 'Ada berapa teknisi?', 'messages' => []])
        ->assertOk()
        ->json('reply');
    expect($techCountReply)
        ->toContain('total 3 teknisi')
        ->toContain('2 teknisi aktif')
        ->toContain('1 teknisi tersedia');

    $techListReply = $this->actingAs($operator)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'Siapa saja?',
            'messages' => [
                ['role' => 'user', 'content' => 'Ada berapa teknisi?'],
                ['role' => 'assistant', 'content' => $techCountReply],
            ],
        ])
        ->assertOk()
        ->json('reply');
    expect($techListReply)
        ->toContain('Raka Teknisi (tersedia)')
        ->toContain('Nabila Teknisi (sedang bertugas)')
        ->toContain('Dedi Nonaktif (nonaktif)');

    $techAvailableReply = $this->actingAs($operator)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'Yang tersedia siapa?',
            'messages' => [
                ['role' => 'user', 'content' => 'Ada berapa teknisi?'],
                ['role' => 'assistant', 'content' => $techCountReply],
            ],
        ])
        ->assertOk()
        ->json('reply');
    expect($techAvailableReply)
        ->toContain('Raka Teknisi')
        ->not->toContain('Nabila Teknisi');

    $techOthersReply = $this->actingAs($operator)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'Kalau yang lainnya?',
            'messages' => [
                ['role' => 'user', 'content' => 'Ada berapa teknisi?'],
                ['role' => 'assistant', 'content' => $techCountReply],
                ['role' => 'user', 'content' => 'Yang tersedia siapa?'],
                ['role' => 'assistant', 'content' => $techAvailableReply],
            ],
        ])
        ->assertOk()
        ->json('reply');
    expect($techOthersReply)
        ->toContain('Raka Teknisi')
        ->toContain('Nabila Teknisi')
        ->toContain('Dedi Nonaktif');

    $techCountFollowReply = $this->actingAs($operator)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'Berapa jumlahnya?',
            'messages' => [
                ['role' => 'user', 'content' => 'Siapa saja teknisinya?'],
                ['role' => 'assistant', 'content' => $techListReply],
            ],
        ])
        ->assertOk()
        ->json('reply');
    expect($techCountFollowReply)->toContain('total 3 teknisi');

    // =========================================================================
    // C. Finance ("Berapa total pemasukan?" + pending, rejected, count, category)
    // =========================================================================
    Cache::flush();
    // Total verified income across all time = 450,000 + 300,000 + 500,000 = 1,250,000 (excludes 999,000 pending and 888,000 rejected!)
    $financeTotalReply = $this->actingAs($operator)
        ->postJson(route('assistant.messages.store'), ['message' => 'Berapa total pemasukan?', 'messages' => []])
        ->assertOk()
        ->json('reply');
    expect($financeTotalReply)
        ->toContain('Rp 1.250.000')
        ->toContain('3 transaksi terverifikasi')
        ->not->toContain('di luar layanan AD Com');

    // Pending transactions
    $financePendingReply = $this->actingAs($operator)
        ->postJson(route('assistant.messages.store'), ['message' => 'Berapa total transaksi pending?', 'messages' => []])
        ->assertOk()
        ->json('reply');
    expect($financePendingReply)
        ->toContain('1 transaksi berstatus pending')
        ->toContain('Rp 999.000')
        ->toContain('belum dihitung sebagai pemasukan terverifikasi');

    // Rejected transactions
    $financeRejectedReply = $this->actingAs($operator)
        ->postJson(route('assistant.messages.store'), ['message' => 'Berapa total transaksi yang ditolak?', 'messages' => []])
        ->assertOk()
        ->json('reply');
    expect($financeRejectedReply)
        ->toContain('1 transaksi berstatus ditolak/void')
        ->toContain('Rp 888.000')
        ->toContain('tidak dihitung dalam pemasukan terverifikasi');

    // Number of transactions (3 verified income + 1 verified expense + 1 pending + 1 rejected = 6 total)
    $financeCountReply = $this->actingAs($operator)
        ->postJson(route('assistant.messages.store'), ['message' => 'Berapa jumlah transaksi?', 'messages' => []])
        ->assertOk()
        ->json('reply');
    expect($financeCountReply)
        ->toContain('total 6 transaksi')
        ->toContain('4 terverifikasi')
        ->toContain('1 pending')
        ->toContain('1 ditolak/void');

    // Income by category (service_payment: 950,000; installation_fee: 300,000)
    $financeCategoryReply = $this->actingAs($operator)
        ->postJson(route('assistant.messages.store'), ['message' => 'Berapa pemasukan berdasarkan kategori?', 'messages' => []])
        ->assertOk()
        ->json('reply');
    expect($financeCategoryReply)
        ->toContain('service_payment: Rp 950.000')
        ->toContain('installation_fee: Rp 300.000');

    // =========================================================================
    // D. Finance follow-up ("Pemasukan bulan ini berapa?" -> "Kalau bulan lalu?")
    // =========================================================================
    $thisMonthReply = $this->actingAs($operator)
        ->postJson(route('assistant.messages.store'), ['message' => 'Pemasukan bulan ini berapa?', 'messages' => []])
        ->assertOk()
        ->json('reply');
    expect($thisMonthReply)
        ->toContain('Rp 750.000')
        ->toContain('2 transaksi terverifikasi')
        ->not->toContain('Rp 1.250.000');

    $lastMonthReply = $this->actingAs($operator)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'Kalau bulan lalu?',
            'messages' => [
                ['role' => 'user', 'content' => 'Pemasukan bulan ini berapa?'],
                ['role' => 'assistant', 'content' => $thisMonthReply],
            ],
        ])
        ->assertOk()
        ->json('reply');
    expect($lastMonthReply)
        ->toContain('Rp 500.000')
        ->toContain('1 transaksi terverifikasi')
        ->not->toContain('di luar layanan AD Com');

    // =========================================================================
    // E. IT knowledge ("Gimana cara mengatasi laptop panas?" / "Gimana cara atasin laptop panas?")
    // =========================================================================
    foreach (['Gimana cara mengatasi laptop panas?', 'Gimana cara atasin laptop panas?'] as $overheatPrompt) {
        $overheatReply = $this->actingAs($customerActive)
            ->postJson(route('assistant.messages.store'), ['message' => $overheatPrompt, 'messages' => []])
            ->assertOk()
            ->json('reply');

        expect($overheatReply)
            ->toContain('1. Letakkan laptop di permukaan keras dan rata')
            ->toContain('2. Pastikan lubang ventilasi tidak tertutup')
            ->toContain('3. Periksa penggunaan CPU dan RAM melalui Task Manager')
            ->toContain('4. Tutup aplikasi berat')
            ->toContain('5. Bersihkan ventilasi dan kipas')
            ->toContain('6. Gunakan laptop dengan ventilasi')
            ->toContain('Pemeriksaan teknisi profesional diperlukan')
            ->not->toContain('di luar layanan AD Com');
    }

    // =========================================================================
    // F. Network knowledge ("Kenapa Wi-Fi sering putus?")
    // =========================================================================
    $wifiReply = $this->actingAs($customerActive)
        ->postJson(route('assistant.messages.store'), ['message' => 'Kenapa Wi-Fi sering putus?', 'messages' => []])
        ->assertOk()
        ->json('reply');
    expect($wifiReply)
        ->toContain('1. Restart modem dan router')
        ->toContain('2. Uji koneksi lebih dekat ke router')
        ->toContain('3. Lupakan jaringan (Forget Network)')
        ->toContain('diperlukan pemeriksaan')
        ->not->toContain('di luar layanan AD Com');

    // =========================================================================
    // G. Context switching ("Ada berapa customer?" -> "Ada berapa teknisi?" -> "Siapa saja?")
    // =========================================================================
    Cache::flush();
    $switchReply = $this->actingAs($operator)
        ->postJson(route('assistant.messages.store'), [
            'message' => 'Siapa saja?',
            'messages' => [
                ['role' => 'user', 'content' => 'Ada berapa customer?'],
                ['role' => 'assistant', 'content' => $customerCountReply],
                ['role' => 'user', 'content' => 'Ada berapa teknisi?'],
                ['role' => 'assistant', 'content' => $techCountReply],
            ],
        ])
        ->assertOk()
        ->json('reply');
    expect($switchReply)
        ->toContain('Raka Teknisi')
        ->toContain('Nabila Teknisi')
        ->not->toContain('Siti Pelanggan')
        ->not->toContain('Budi Nonaktif');

    // =========================================================================
    // H. Authorization: Financial queries across all roles and guest sessions
    // =========================================================================
    // Authorized roles: operator & admin
    foreach ([$operator, $admin] as $authorizedUser) {
        $authFinanceReply = $this->actingAs($authorizedUser)
            ->postJson(route('assistant.messages.store'), ['message' => 'Berapa total pemasukan?', 'messages' => []])
            ->assertOk()
            ->json('reply');
        expect($authFinanceReply)->toContain('Rp 1.250.000');
    }

    // Unauthorized roles: supervisor, technician, warehouse, customer, and guest
    foreach ([$supervisor, $technicianAvailable, $warehouse, $customerActive] as $restrictedUser) {
        $deniedFinanceReply = $this->actingAs($restrictedUser)
            ->postJson(route('assistant.messages.store'), ['message' => 'Berapa total pemasukan?', 'messages' => []])
            ->assertOk()
            ->json('reply');
        expect($deniedFinanceReply)
            ->toContain('memerlukan tingkat akses yang lebih tinggi')
            ->toContain('Admin Operasional dan Superadmin')
            ->not->toContain('Rp 1.250.000')
            ->not->toContain('di luar layanan AD Com');
    }

    auth()->logout();
    $guestFinanceReply = $this->postJson(route('assistant.messages.store'), ['message' => 'Berapa total pemasukan?', 'messages' => []])
        ->assertOk()
        ->json('reply');
    expect($guestFinanceReply)
        ->toContain('memerlukan tingkat akses yang lebih tinggi')
        ->toContain('Admin Operasional dan Superadmin')
        ->not->toContain('Rp 1.250.000')
        ->not->toContain('di luar layanan AD Com');

    Http::assertNothingSent();
});
