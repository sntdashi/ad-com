<?php

use App\Models\AiProvider;
use App\Models\AssistantSetting;
use App\Models\User;
use App\Services\Ai\AiProviderClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

test('non-superadmin roles are forbidden from ai provider manager', function (string $role) {
    $user = User::factory()->create(['role' => $role]);

    $this->actingAs($user)->get(route('ai-providers.index'))->assertForbidden();
    $this->actingAs($user)->post(route('ai-providers.store'), [
        'driver' => 'openai',
        'name' => 'Test',
        'api_token' => 'sk-test-123456',
    ])->assertForbidden();
})->with(['admin', 'supervisor', 'technician', 'warehouse', 'customer']);

test('custom provider rejects http and private internal ips to prevent ssrf', function (string $badUrl) {
    $operator = User::factory()->create(['role' => 'operator']);

    $this->actingAs($operator)
        ->post(route('ai-providers.store'), [
            'driver' => 'custom_openai',
            'name' => 'Internal SSRF',
            'base_url' => $badUrl,
            'api_token' => 'sk-test-123456',
        ])
        ->assertSessionHasErrors('base_url');
})->with([
    'plain http' => 'http://api.example.com/v1',
    'localhost' => 'https://localhost/v1',
    'loopback' => 'https://127.0.0.1/v1',
    'private lan' => 'https://192.168.1.10/v1',
]);

test('superadmin stores encrypted token, discovers models dynamically, and never leaks secrets', function () {
    $operator = User::factory()->create(['role' => 'operator']);
    $secret = 'sk-secret-provider-998877';

    $this->actingAs($operator)
        ->post(route('ai-providers.store'), [
            'driver' => 'openai',
            'name' => 'OpenAI Utama',
            'api_token' => $secret,
            'is_default' => true,
        ])
        ->assertRedirect();

    $provider = AiProvider::query()->firstOrFail();
    $rawDbToken = DB::table('ai_providers')->where('id', $provider->id)->value('api_token');
    expect($rawDbToken)->not->toBe($secret);
    expect($provider->api_token)->toBe($secret);

    Http::fake([
        'https://api.openai.com/v1/models' => Http::response([
            'data' => [
                ['id' => 'gpt-4o-mini', 'name' => 'GPT-4o Mini'],
                ['id' => 'gpt-4.1', 'name' => 'GPT-4.1'],
            ],
        ], 200),
    ]);

    $this->actingAs($operator)
        ->post(route('ai-providers.discover', $provider))
        ->assertRedirect();

    $provider->refresh();
    expect($provider->connection_status)->toBe('connected');
    expect(count($provider->discovered_models ?? []))->toBe(2);
    expect($provider->model_id)->toBe('gpt-4.1');

    $pageResponse = $this->actingAs($operator)->get(route('ai-providers.index'));
    $pageResponse->assertOk();
    expect($pageResponse->getContent())->not->toContain($secret);

    $auditPayloads = DB::table('audit_logs')->pluck('after')->implode(' ');
    expect($auditPayloads)->not->toContain($secret);
});

test('discover handles invalid token and manual model fallback handles unavailable model', function () {
    $operator = User::factory()->create(['role' => 'operator']);
    $provider = AiProvider::query()->create([
        'driver' => 'custom_openai',
        'name' => 'Custom API',
        'base_url' => 'https://api.example.com/v1',
        'api_token' => 'sk-bad-token-1234',
        'is_active' => true,
    ]);

    Http::fake([
        'https://api.example.com/v1/models' => Http::sequence()
            ->push(['error' => 'Unauthorized'], 401)
            ->push([], 404),
        'https://api.example.com/v1/chat/completions' => Http::sequence()
            ->push(['error' => 'Model not found'], 404)
            ->push(['choices' => [['message' => ['content' => 'ok']]]], 200),
    ]);

    $this->actingAs($operator)
        ->post(route('ai-providers.discover', $provider))
        ->assertSessionHasErrors('provider');

    expect($provider->fresh()->connection_status)->toBe('failed');

    // Provider without model listing (404 on /models) -> manual model validation
    $this->actingAs($operator)
        ->post(route('ai-providers.discover', $provider))
        ->assertRedirect();

    $this->actingAs($operator)
        ->post(route('ai-providers.validate-model', $provider), ['model_id' => 'nonexistent-model'])
        ->assertSessionHasErrors('model_id');

    $this->actingAs($operator)
        ->post(route('ai-providers.validate-model', $provider), ['model_id' => 'custom-llama-3'])
        ->assertRedirect();

    expect($provider->fresh()->model_id)->toBe('custom-llama-3');
});

test('adi switches active provider and falls back safely on provider timeout', function () {
    $operator = User::factory()->create(['role' => 'operator']);
    $customer = User::factory()->create(['role' => 'customer']);

    $gemini = AiProvider::query()->create([
        'driver' => 'gemini',
        'name' => 'Gemini Flash',
        'api_token' => 'AIzaFakeToken123456',
        'model_id' => 'gemini-2.5-flash',
        'is_active' => true,
        'is_default' => false,
    ]);

    $this->actingAs($operator)
        ->post(route('ai-providers.activate', $gemini))
        ->assertRedirect();

    Http::fake([
        'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent' => Http::response([
            'candidates' => [
                ['content' => ['parts' => [['text' => 'Jawaban dari Gemini Flash untuk kendala Wi-Fi Anda.']]]],
            ],
        ], 200),
    ]);

    $this->actingAs($customer)
        ->postJson(route('assistant.messages.store'), ['message' => 'Wi-Fi kantor saya lambat sekali'])
        ->assertOk()
        ->assertJsonPath('mode', 'ai')
        ->assertSee('Gemini Flash');

    // Timeout fallback -> returns safe local guide response
    Http::fake(function () {
        throw new ConnectionException('cURL error 28: Operation timed out');
    });

    $this->actingAs($customer)
        ->postJson(route('assistant.messages.store'), ['message' => 'Wi-Fi kantor saya lambat sekali'])
        ->assertOk()
        ->assertJsonPath('mode', 'guide');

    expect($gemini->fresh()->connection_status)->toBe('failed');
});

test('test connection supports all five drivers and diagnoses 401, 403, 404, 429, 500, and timeout without exposing tokens', function () {
    $operator = User::factory()->create(['role' => 'operator']);
    $admin = User::factory()->create(['role' => 'admin']);

    $drivers = [
        ['driver' => 'openai', 'base_url' => null, 'url' => 'https://api.openai.com/v1/models'],
        ['driver' => 'gemini', 'base_url' => null, 'url' => 'https://generativelanguage.googleapis.com/v1beta/models'],
        ['driver' => 'anthropic', 'base_url' => null, 'url' => 'https://api.anthropic.com/v1/models'],
        ['driver' => 'openrouter', 'base_url' => null, 'url' => 'https://openrouter.ai/api/v1/models'],
        ['driver' => 'custom_openai', 'base_url' => 'https://llm.example.com/v1', 'url' => 'https://llm.example.com/v1/models'],
    ];

    foreach ($drivers as $index => $spec) {
        $provider = AiProvider::query()->create([
            'driver' => $spec['driver'],
            'name' => 'Provider '.$spec['driver'],
            'base_url' => $spec['base_url'],
            'api_token' => 'sk-secret-'.$spec['driver'].'-9999',
            'is_active' => true,
        ]);

        if ($index === 0) {
            $this->actingAs($admin)->post(route('ai-providers.test', $provider))->assertForbidden();
        }

        // Sequential responses for 200, 401, 403, 404, 429, 500
        $sequence = Http::sequence()->push(['data' => []], 200);
        foreach ([401, 403, 404, 429, 500] as $status) {
            $sequence->push(['error' => ['message' => "Simulated {$status} with sk-secret-{$spec['driver']}-9999"]], $status);
        }
        Http::fake([$spec['url'] => $sequence]);

        if ($index === 0) {
            $this->actingAs($operator)
                ->post(route('ai-providers.test', $provider))
                ->assertRedirect()
                ->assertSessionHas('success');
            expect($provider->fresh()->connection_status)->toBe('connected');

            foreach ([401, 403, 404, 429, 500] as $status) {
                $this->actingAs($operator)
                    ->post(route('ai-providers.test', $provider))
                    ->assertSessionHasErrors('provider');

                $fresh = $provider->fresh();
                expect($fresh->connection_status)->toBe('failed')
                    ->and($fresh->last_error)->toContain("HTTP {$status}")
                    ->and($fresh->last_error)->not->toContain('sk-secret-');
            }
        } else {
            $client = app(AiProviderClient::class);
            $okResult = $client->testConnection($provider);
            expect($okResult['ok'])->toBeTrue();

            foreach ([401, 403, 404, 429, 500] as $status) {
                $errResult = $client->testConnection($provider);
                expect($errResult['ok'])->toBeFalse()
                    ->and($errResult['http_status'])->toBe($status)
                    ->and($errResult['message'])->toContain("HTTP {$status}")
                    ->and($errResult['message'])->not->toContain('sk-secret-');
            }
        }
    }
});

test('test connection diagnoses timeout cleanly', function () {
    $operator = User::factory()->create(['role' => 'operator']);
    $provider = AiProvider::query()->create([
        'driver' => 'openai',
        'name' => 'OpenAI Timeout',
        'api_token' => 'sk-secret-timeout-9999',
        'is_active' => true,
    ]);

    Http::fake(function () {
        throw new ConnectionException('cURL error 28: Operation timed out after 5000 milliseconds');
    });

    $this->actingAs($operator)
        ->post(route('ai-providers.test', $provider))
        ->assertSessionHasErrors('provider');

    expect($provider->fresh()->connection_status)->toBe('failed')
        ->and($provider->fresh()->last_error)->toContain('timeout');
});

test('single source of truth surfaces active ai provider across admin control center, assistant settings, and integrations without erasing existing assistant config', function () {
    $this->withoutVite();
    $operator = User::factory()->create(['role' => 'operator']);

    AssistantSetting::query()->create([
        'provider' => 'openai',
        'model' => 'gpt-4.1-mini',
        'api_key' => 'sk-legacy-preserved',
        'is_enabled' => true,
        'max_output_tokens' => 500,
        'custom_instructions' => 'Utamakan ramah.',
    ]);

    AiProvider::query()->create([
        'driver' => 'openrouter',
        'name' => 'OpenRouter Production',
        'api_token' => 'sk-or-v1-prod1234',
        'model_id' => 'openai/gpt-4o-mini',
        'connection_status' => 'connected',
        'is_active' => true,
        'is_default' => true,
    ]);

    $this->actingAs($operator)->get(route('admin.control-center'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('assistant.provider', 'OpenRouter Production')
            ->where('assistant.model', 'openai/gpt-4o-mini')
            ->where('assistant.key_configured', true));

    $this->actingAs($operator)->get(route('assistant-settings.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('activeProvider.name', 'OpenRouter Production')
            ->where('activeProvider.model_id', 'openai/gpt-4o-mini'));

    $this->actingAs($operator)->get(route('integrations-settings.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('aiProviderSummary.name', 'OpenRouter Production')
            ->where('aiProviderSummary.model_id', 'openai/gpt-4o-mini'));

    // Updating behavior settings on /settings/assistant preserves legacy model/api_key
    $this->actingAs($operator)->put(route('assistant-settings.update'), [
        'is_enabled' => true,
        'max_output_tokens' => 700,
        'custom_instructions' => 'Instruksi baru.',
    ])->assertRedirect();

    $legacy = AssistantSetting::query()->firstOrFail();
    expect($legacy->api_key)->toBe('sk-legacy-preserved')
        ->and($legacy->model)->toBe('gpt-4.1-mini')
        ->and($legacy->max_output_tokens)->toBe(700);
});
