<?php

use App\Jobs\SendWhatsAppNotificationJob;
use App\Models\AssistantSetting;
use App\Models\NotificationDelivery;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

test('non-superadmin roles are forbidden from integration center endpoints', function (string $role) {
    $user = User::factory()->create(['role' => $role]);

    $this->actingAs($user)->get(route('integrations-settings.edit'))->assertForbidden();
    $this->actingAs($user)->put(route('integrations-settings.fonnte.update'), ['token' => 'secret-token-123'])->assertForbidden();
    $this->actingAs($user)->post(route('integrations-settings.fonnte.test'))->assertForbidden();
    $this->actingAs($user)->post(route('integrations-settings.fonnte.send-test'), ['phone' => '6281234567890'])->assertForbidden();
    $this->actingAs($user)->put(route('integrations-settings.openai.update'), ['model' => 'gpt-4o-mini'])->assertForbidden();
    $this->actingAs($user)->post(route('integrations-settings.openai.test'))->assertForbidden();
    $this->actingAs($user)->put(route('integrations-settings.notifications.update'), ['events' => []])->assertForbidden();
})->with(['admin', 'supervisor', 'technician', 'warehouse', 'customer']);

test('superadmin stores encrypted secrets that never leak to frontend or audit logs', function () {
    $operator = User::factory()->create(['role' => 'operator']);
    $fonnteSecret = 'fonnte-super-secret-998877';
    $openAiSecret = 'sk-super-secret-openai-998877';

    $this->actingAs($operator)
        ->put(route('integrations-settings.fonnte.update'), ['token' => $fonnteSecret])
        ->assertRedirect();

    $rawStoredFonnte = DB::table('system_settings')->where('key', 'secret:fonnte_token')->value('value');
    expect($rawStoredFonnte)->not->toBe($fonnteSecret);
    expect(SystemSetting::getEncrypted('secret:fonnte_token'))->toBe($fonnteSecret);

    $this->actingAs($operator)
        ->put(route('integrations-settings.openai.update'), [
            'api_key' => $openAiSecret,
            'model' => 'gpt-4o-mini',
            'is_enabled' => true,
        ])
        ->assertRedirect();

    $rawStoredOpenAi = DB::table('assistant_settings')->value('api_key');
    expect($rawStoredOpenAi)->not->toBe($openAiSecret);
    expect(AssistantSetting::query()->firstOrFail()->api_key)->toBe($openAiSecret);

    $response = $this->actingAs($operator)->get(route('integrations-settings.edit'));
    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('fonnte.configured', true)
            ->where('openai.configured', true)
        );

    expect($response->getContent())->not->toContain($fonnteSecret);
    expect($response->getContent())->not->toContain($openAiSecret);

    $auditPayloads = DB::table('audit_logs')->pluck('after')->implode(' ');
    expect($auditPayloads)->not->toContain($fonnteSecret);
    expect($auditPayloads)->not->toContain($openAiSecret);
});

test('test connection handles invalid tokens safely for fonnte and openai', function () {
    $operator = User::factory()->create(['role' => 'operator']);

    SystemSetting::putEncrypted('secret:fonnte_token', 'invalid-fonnte-token');
    AssistantSetting::query()->create([
        'provider' => 'openai',
        'model' => 'gpt-4o-mini',
        'api_key' => 'sk-invalid-openai',
        'is_enabled' => true,
    ]);

    Http::fake([
        'https://api.fonnte.com/device' => Http::response(['status' => false], 401),
        'https://api.openai.com/*' => Http::response(['error' => ['message' => 'Invalid API key']], 401),
    ]);

    $this->actingAs($operator)
        ->post(route('integrations-settings.fonnte.test'))
        ->assertSessionHasErrors('fonnte');

    $this->actingAs($operator)
        ->post(route('integrations-settings.openai.test'))
        ->assertSessionHasErrors('openai');
});

test('missing fonnte token still saves in-app notification and skips whatsapp without failing, and missing openai falls back in ADI', function () {
    config()->set('services.fonnte.token', '');
    config()->set('services.openai.key', '');

    $customer = User::factory()->create(['role' => 'customer', 'phone' => '081234567890']);

    app(NotificationDispatcher::class)->notifyUsers(
        [$customer],
        'order.status_changed',
        'Status diperbarui',
        'Teknisi menuju lokasi.',
    );

    expect(DB::table('notifications')->where('notifiable_id', $customer->id)->count())->toBe(1);
    $delivery = NotificationDelivery::query()->latest('id')->firstOrFail();

    SendWhatsAppNotificationJob::dispatchSync($delivery->id, '[AD Com] Status diperbarui');
    expect($delivery->fresh()->status)->toBe('skipped');

    $this->actingAs($customer)
        ->postJson(route('assistant.messages.store'), ['message' => 'Siapa kamu?'])
        ->assertOk()
        ->assertJsonPath('mode', 'guide')
        ->assertSee('ADI');
});
