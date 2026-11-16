<?php

use App\Models\AssistantSetting;
use App\Models\User;
use Illuminate\Support\Facades\Http;

test('an internal operator without authenticator OTP can view and update assistant settings', function () {
    $this->withoutVite();
    $operator = User::factory()->create(['role' => 'operator']);

    $this->actingAs($operator)->get(route('assistant-settings.edit'))->assertOk();
    $this->actingAs($operator)->put(route('assistant-settings.update'), [
        'model' => 'gpt-5.6-luna',
        'api_key' => 'sk-test-operator',
        'is_enabled' => false,
        'base_url' => 'https://api.example.com/v1',
        'max_output_tokens' => 600,
        'custom_instructions' => 'Buka Senin sampai Sabtu.',
    ])->assertRedirect();

    $saved = AssistantSetting::query()->firstOrFail();
    expect($saved->model)->toBe('gpt-5.6-luna')
        ->and($saved->is_enabled)->toBeFalse()
        ->and($saved->base_url)->toBe('https://api.example.com/v1')
        ->and($saved->max_output_tokens)->toBe(600)
        ->and($saved->custom_instructions)->toBe('Buka Senin sampai Sabtu.');
});

test('an internal admin can save the model and encrypted API key', function () {
    $admin = User::factory()->withTwoFactor()->create(['role' => 'admin']);

    $this->actingAs($admin)->put(route('assistant-settings.update'), [
        'model' => 'gpt-5.6-luna',
        'api_key' => 'sk-test-private',
    ])->assertRedirect();

    $setting = AssistantSetting::query()->firstOrFail();
    expect($setting->api_key)->toBe('sk-test-private')
        ->and($setting->getRawOriginal('api_key'))->not->toBe('sk-test-private')
        ->and($setting->model)->toBe('gpt-5.6-luna');
});

test('customers cannot view or update assistant settings', function () {
    $customer = User::factory()->create(['role' => 'customer']);

    $this->actingAs($customer)->get(route('assistant-settings.edit'))->assertForbidden();
    $this->actingAs($customer)->put(route('assistant-settings.update'), [
        'model' => 'gpt-5.6-luna',
    ])->assertForbidden();
    expect(AssistantSetting::query()->exists())->toBeFalse();
});

test('saving a blank key preserves the current encrypted key', function () {
    $admin = User::factory()->withTwoFactor()->create(['role' => 'operator']);
    AssistantSetting::query()->create(['model' => 'old-model', 'api_key' => 'sk-existing']);

    $this->actingAs($admin)->put(route('assistant-settings.update'), [
        'model' => 'new-model',
        'api_key' => '',
    ])->assertRedirect();

    expect(AssistantSetting::query()->firstOrFail()->api_key)->toBe('sk-existing');
});

test('an operator can test the connection with the submitted key without saving it', function () {
    Http::fake([
        '*/models/*' => Http::response(['id' => 'gpt-test'], 200, [
            'x-ratelimit-limit-requests' => '500',
            'x-ratelimit-remaining-requests' => '499',
        ]),
    ]);
    $operator = User::factory()->withTwoFactor()->create(['role' => 'operator']);

    $this->actingAs($operator)->postJson(route('assistant-settings.test'), [
        'model' => 'gpt-test',
        'api_key' => 'sk-live-secret',
    ])->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('rate_limit.remaining_requests', '499')
        ->assertJsonMissing(['sk-live-secret']);

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sk-live-secret'));
    expect(AssistantSetting::query()->exists())->toBeFalse();
});

test('connection test reports a rejected key and a missing key clearly', function () {
    Http::fake(['*/models/*' => Http::response([], 401)]);
    config(['services.openai.key' => null]);
    $operator = User::factory()->withTwoFactor()->create(['role' => 'operator']);

    $this->actingAs($operator)->postJson(route('assistant-settings.test'), ['model' => 'gpt-test'])
        ->assertOk()->assertJsonPath('ok', false)->assertJsonPath('status', null);

    $this->actingAs($operator)->postJson(route('assistant-settings.test'), ['model' => 'gpt-test', 'api_key' => 'sk-bad'])
        ->assertOk()->assertJsonPath('ok', false)->assertJsonPath('status', 401);
});

test('customers cannot run the connection test', function () {
    $customer = User::factory()->create(['role' => 'customer']);

    $this->actingAs($customer)->postJson(route('assistant-settings.test'), ['model' => 'gpt-test'])->assertForbidden();
});
