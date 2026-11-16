<?php

use App\Jobs\SendWhatsAppNotificationJob;
use App\Models\InventoryItem;
use App\Models\NotificationDelivery;
use App\Models\OrderStatusEvent;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\TechnicianWorkSession;
use App\Models\User;
use App\Services\FonnteMessageSender;
use App\Services\Inventory\InventoryService;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

it('manages centralized notification inbox pagination, filters, read state, archive, delete, and user isolation', function (): void {
    Queue::fake();

    $customerA = User::factory()->create(['role' => 'customer']);
    $customerB = User::factory()->create(['role' => 'customer']);
    $dispatcher = app(NotificationDispatcher::class);

    foreach (range(1, 18) as $i) {
        $dispatcher->notifyUsers(
            [$customerA],
            $i === 18 ? 'warranty.approaching_expiration' : 'order.status_changed',
            "Notifikasi Tiket #{$i}",
            "Detail pembaruan nomor {$i}",
            '/dashboard',
            [],
            false,
        );
    }

    $dispatcher->notifyUsers(
        [$customerB],
        'order.status_changed',
        'Rahasia Customer B',
        'Tidak boleh terlihat oleh Customer A',
        '/dashboard',
        [],
        false,
    );

    // Page 1 pagination (15 per page) + unread stats
    $this->actingAs($customerA)
        ->get(route('notifications.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('notifications/index')
            ->where('stats.unread', 18)
            ->has('inbox.data', 15)
        );

    // Search & category filter
    $this->actingAs($customerA)
        ->get(route('notifications.index', ['category' => 'warranty', 'search' => '#18']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('inbox.data', 1)
            ->where('inbox.data.0.priority', 'high')
            ->where('inbox.data.0.category', 'warranty')
        );

    // JSON endpoint compatibility
    $this->actingAs($customerA)
        ->getJson(route('notifications.index'))
        ->assertOk()
        ->assertJsonPath('unread_count', 18);

    // Customer A cannot archive or delete Customer B's notification
    $bNotifId = (string) DB::table('notifications')->where('notifiable_id', $customerB->id)->value('id');
    $this->actingAs($customerA)
        ->patch(route('notifications.archive', $bNotifId))
        ->assertNotFound();
    $this->actingAs($customerA)
        ->delete(route('notifications.destroy', $bNotifId))
        ->assertNotFound();

    // Mark one read, archive, mark-all-read, delete
    $aNotifId = (string) DB::table('notifications')->where('notifiable_id', $customerA->id)->value('id');
    $this->actingAs($customerA)->patch(route('notifications.read', $aNotifId))->assertRedirect();
    expect(DB::table('notifications')->where('id', $aNotifId)->value('read_at'))->not->toBeNull();

    $this->actingAs($customerA)->patch(route('notifications.archive', $aNotifId))->assertRedirect();
    expect(DB::table('notifications')->where('id', $aNotifId)->value('archived_at'))->not->toBeNull();

    $this->actingAs($customerA)->patch(route('notifications.read-all'))->assertRedirect();
    expect(
        DB::table('notifications')
            ->where('notifiable_id', $customerA->id)
            ->whereNull('archived_at')
            ->whereNull('read_at')
            ->count()
    )->toBe(0);

    $this->actingAs($customerA)->delete(route('notifications.destroy', $aNotifId))->assertRedirect();
    expect(DB::table('notifications')->where('id', $aNotifId)->exists())->toBeFalse();
});

it('enforces user notification preferences, mandatory security lock, and deduplication', function (): void {
    Queue::fake();

    $customer = User::factory()->create(['role' => 'customer', 'phone' => '6281111222333']);

    // Customer disables orders (both channels) and attempts to tamper-disable security category
    $this->actingAs($customer)
        ->put(route('notifications.preferences.update'), [
            'preferences' => [
                [
                    'category' => 'orders',
                    'in_app_enabled' => false,
                    'whatsapp_enabled' => false,
                ],
                [
                    'category' => 'security',
                    'in_app_enabled' => false,
                    'whatsapp_enabled' => false,
                ],
            ],
        ])
        ->assertRedirect();

    $dispatcher = app(NotificationDispatcher::class);

    // Orders notification should be skipped due to preference
    $dispatcher->notifyUsers(
        [$customer],
        'order.status_changed',
        'Update Pesanan',
        'Pesanan sedang diproses.',
        '/dashboard',
    );
    expect(DB::table('notifications')->where('notifiable_id', $customer->id)->count())->toBe(0);
    Queue::assertNothingPushed();

    // Mandatory security notification MUST still be delivered & deduplicated via dedupeKey
    $dispatcher->notifyUsers(
        [$customer],
        'security.alert',
        'Perubahan Keamanan Akun',
        'Kata sandi baru saja diperbarui.',
        '/settings/security',
        [],
        true,
        'sec-pw-reset-1',
    );
    $dispatcher->notifyUsers(
        [$customer],
        'security.alert',
        'Perubahan Keamanan Akun',
        'Kata sandi baru saja diperbarui.',
        '/settings/security',
        [],
        true,
        'sec-pw-reset-1',
    );

    expect(DB::table('notifications')->where('notifiable_id', $customer->id)->where('category', 'security')->count())->toBe(1)
        ->and(NotificationDelivery::query()->where('user_id', $customer->id)->count())->toBe(1);
    Queue::assertPushed(SendWhatsAppNotificationJob::class, 1);
});

it('enforces role-scoped deep links, priority alerts, and activity vs audit feed privacy', function (): void {
    $customer = User::factory()->create(['role' => 'customer']);
    $otherCustomer = User::factory()->create(['role' => 'customer']);
    $technician = User::factory()->create(['role' => 'technician']);
    $operator = User::factory()->create(['role' => 'operator']);
    $warehouse = User::factory()->create(['role' => 'warehouse']);

    $service = Service::factory()->create();
    $order = ServiceOrder::factory()->create([
        'customer_id' => $customer->id,
        'service_id' => $service->id,
        'assigned_technician_id' => null,
        'status' => 'in_progress',
        'response_due_at' => now()->subHours(4),
        'resolution_due_at' => now()->subHour(),
    ]);

    OrderStatusEvent::create([
        'service_order_id' => $order->id,
        'actor_id' => $operator->id,
        'status' => 'in_progress',
        'title' => 'Catatan Internal Rahasia',
        'description' => 'Margin keuntungan tinggi — hanya internal.',
        'visibility' => 'internal',
        'occurred_at' => now()->subMinutes(5),
    ]);

    OrderStatusEvent::create([
        'service_order_id' => $order->id,
        'actor_id' => $operator->id,
        'status' => 'in_progress',
        'title' => 'Teknisi mulai pengecekan',
        'description' => 'Perangkat sedang diperiksa.',
        'visibility' => 'customer',
        'occurred_at' => now()->subMinutes(2),
    ]);

    TechnicianWorkSession::create([
        'service_order_id' => $order->id,
        'technician_id' => $technician->id,
        'started_at' => now()->subMinutes(4),
        'notes' => 'Catatan privat teknisi: solder ulang IC power',
    ]);

    $item = InventoryItem::create([
        'sku' => 'SKU-OOS-01',
        'name' => 'Thermal Paste High-Perf',
        'category' => 'Consumable',
        'unit' => 'pcs',
        'quantity_on_hand' => 1,
        'reorder_level' => 2,
        'unit_cost' => 125000,
        'is_active' => true,
    ]);

    app(InventoryService::class)->recordMovement([
        'inventory_item_id' => $item->id,
        'type' => 'stock_out',
        'quantity' => 1,
        'notes' => 'Dipakai habis',
    ], $warehouse, '127.0.0.1');

    DB::table('audit_logs')->insert([
        'actor_id' => $operator->id,
        'event' => 'integration.secret_rotated',
        'auditable_type' => User::class,
        'auditable_id' => (string) $operator->id,
        'created_at' => now(),
    ]);

    // Technician receives stale notification pointing to an order they are no longer assigned to -> href stripped
    app(NotificationDispatcher::class)->notifyUsers(
        [$technician],
        'order.assigned',
        'Penugasan lama',
        'Anda pernah ditugaskan pada tiket ini.',
        route('orders.show', $order),
        [],
        false,
    );

    $this->actingAs($technician)
        ->get(route('notifications.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('inbox.data.0.href', null)
            ->where('inbox.data.0.href_accessible', false)
        );

    // Customer activity feed MUST NOT expose internal status events, technician private notes, inventory movements, audit logs, or delivery logs
    $this->actingAs($customer)
        ->get(route('notifications.index', ['tab' => 'activity']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('deliveries.authorized', false)
            ->where('deliveries.items', [])
            ->where('activityFeed', function ($feed): bool {
                $serialized = json_encode($feed);

                return str_contains((string) $serialized, 'Teknisi mulai pengecekan')
                    && ! str_contains((string) $serialized, 'Catatan Internal Rahasia')
                    && ! str_contains((string) $serialized, 'Catatan privat teknisi')
                    && ! str_contains((string) $serialized, 'Thermal Paste')
                    && ! str_contains((string) $serialized, 'integration.secret_rotated');
            })
        );

    // Operator sees breached SLA + out-of-stock priority alerts + audit feed + delivery panel
    $this->actingAs($operator)
        ->get(route('notifications.index', ['tab' => 'activity']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('deliveries.authorized', true)
            ->where('priorityAlerts', function ($alerts): bool {
                $keys = collect($alerts)->pluck('key')->all();

                return in_array('sla-breached', $keys, true)
                    && in_array('inventory-out-of-stock', $keys, true);
            })
            ->where('activityFeed', fn ($feed): bool => collect($feed)->contains('feed_type', 'audit'))
        );

    // Other customer cannot get customer A's events
    $this->actingAs($otherCustomer)
        ->get(route('notifications.index', ['tab' => 'activity']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('activityFeed', [])
        );
});

it('sanitizes gateway errors and enforces safe idempotent retry permissions on notification deliveries', function (): void {
    Queue::fake();

    $operator = User::factory()->create(['role' => 'operator']);
    $admin = User::factory()->create(['role' => 'admin']);
    $customer = User::factory()->create(['role' => 'customer']);

    $sender = app(FonnteMessageSender::class);
    $rawError = 'cURL error: https://api.fonnte.com/send?token=super_secret_token_999 Authorization: Bearer secret_bearer_123';
    $redacted = $sender->sanitizeErrorMessage($rawError, 'super_secret_token_999');

    expect($redacted)->not->toContain('super_secret_token_999')
        ->and($redacted)->not->toContain('secret_bearer_123')
        ->and($redacted)->toContain('[REDACTED]');

    $failedDelivery = NotificationDelivery::create([
        'user_id' => $customer->id,
        'channel' => 'whatsapp',
        'event_key' => 'order.status_changed',
        'recipient_phone' => '6281234567890',
        'status' => 'failed',
        'attempts' => 3,
        'last_error' => $rawError,
        'payload_summary' => ['title' => 'Update Tiket', 'target' => '6281****90'],
    ]);

    $sentDelivery = NotificationDelivery::create([
        'user_id' => $customer->id,
        'channel' => 'whatsapp',
        'event_key' => 'order.status_changed',
        'recipient_phone' => '6281234567890',
        'status' => 'sent',
        'attempts' => 1,
        'sent_at' => now(),
        'payload_summary' => ['title' => 'Terkirim', 'target' => '6281****90'],
    ]);

    // Admin sees delivery observability with sanitized error, but cannot retry (operator-only)
    $this->actingAs($admin)
        ->get(route('notifications.index', ['tab' => 'deliveries']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('deliveries.can_retry', false)
            ->where('deliveries.items.1.last_error', fn (?string $err): bool => $err !== null && ! str_contains($err, 'secret_bearer_123'))
        );

    $this->actingAs($admin)
        ->post(route('notifications.deliveries.retry', $failedDelivery))
        ->assertForbidden();

    // Operator retries failed delivery cleanly -> queued + retrier tracked + audit logged
    $this->actingAs($operator)
        ->post(route('notifications.deliveries.retry', $failedDelivery))
        ->assertRedirect();

    $failedDelivery->refresh();
    expect($failedDelivery->status)->toBe('queued')
        ->and($failedDelivery->retried_by)->toBe($operator->id)
        ->and($failedDelivery->retried_at)->not->toBeNull();
    Queue::assertPushed(SendWhatsAppNotificationJob::class, 1);

    // Retrying an already-sent delivery is blocked cleanly
    $this->actingAs($operator)
        ->post(route('notifications.deliveries.retry', $sentDelivery))
        ->assertSessionHasErrors('delivery');
});
