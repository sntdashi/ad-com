<?php

use App\Jobs\SendWhatsAppNotificationJob;
use App\Models\InventoryItem;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\PasswordOtpService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

test('password otp service enforces phone validation and 60s cooldown', function () {
    config()->set('services.fonnte.token', 'fake-token');
    Http::fake([
        'https://api.fonnte.com/send' => Http::response(['status' => true], 200),
    ]);

    $badPhoneUser = User::factory()->create(['phone' => '123']);
    expect(fn () => app(PasswordOtpService::class)->issue($badPhoneUser, 'password_change'))
        ->toThrow(ValidationException::class);

    $user = User::factory()->create(['phone' => '081234567890']);
    $issued = app(PasswordOtpService::class)->issue($user, 'password_change');
    expect($issued)->toBeTrue();

    expect(fn () => app(PasswordOtpService::class)->issue($user, 'password_change'))
        ->toThrow(ValidationException::class);
});

test('low stock movement creates in-app notifications and queues whatsapp job safely', function () {
    Queue::fake();

    $warehouse = User::factory()->create(['role' => 'warehouse', 'phone' => '081234567891']);
    $operator = User::factory()->create(['role' => 'operator', 'phone' => '081234567892']);

    $item = InventoryItem::create([
        'sku' => 'SKU-NOTIF-01',
        'name' => 'SSD NVMe 512GB',
        'category' => 'Storage',
        'unit' => 'pcs',
        'quantity_on_hand' => 5,
        'reorder_level' => 3,
        'unit_cost' => 650000,
        'storage_location' => 'Rak B2',
        'is_active' => true,
    ]);

    $this->actingAs($warehouse)
        ->post(route('inventory.movements.store'), [
            'inventory_item_id' => $item->id,
            'type' => 'stock_out',
            'quantity' => 3,
            'notes' => 'Dipakai perbaikan',
        ])
        ->assertRedirect();

    expect(DB::table('notifications')->where('notifiable_id', $warehouse->id)->count())->toBe(1);
    expect(DB::table('notifications')->where('notifiable_id', $operator->id)->count())->toBe(1);
    Queue::assertPushed(SendWhatsAppNotificationJob::class, 2);

    $this->actingAs($warehouse)
        ->patch(route('notifications.read-all'))
        ->assertRedirect();

    expect(DB::table('notifications')->where('notifiable_id', $warehouse->id)->whereNull('read_at')->count())->toBe(0);
});

test('whatsapp notification job skips safely when fonnte token is missing and sends when configured', function () {
    $user = User::factory()->create(['phone' => '081234567890']);

    $delivery = NotificationDelivery::create([
        'user_id' => $user->id,
        'channel' => 'whatsapp',
        'event_key' => 'order.created',
        'recipient_phone' => '081234567890',
        'status' => 'queued',
        'attempts' => 0,
        'payload_summary' => ['title' => 'Tiket baru'],
    ]);

    config()->set('services.fonnte.token', '');
    SendWhatsAppNotificationJob::dispatchSync($delivery->id, '[AD Com] Tiket baru');
    expect($delivery->fresh()->status)->toBe('skipped');

    config()->set('services.fonnte.token', 'real-test-token');
    Http::fake([
        'https://api.fonnte.com/send' => Http::response(['status' => true], 200),
    ]);

    SendWhatsAppNotificationJob::dispatchSync($delivery->id, '[AD Com] Tiket baru');
    expect($delivery->fresh()->status)->toBe('sent');
});
