<?php

namespace App\Services\Notifications;

use App\Jobs\SendWhatsAppNotificationJob;
use App\Models\CustomerAsset;
use App\Models\Estimate;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\NotificationDelivery;
use App\Models\OrderStatusEvent;
use App\Models\ServiceOrder;
use App\Models\TechnicianWorkSession;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Models\Warranty;
use App\Models\WarrantyClaim;
use App\Services\FonnteMessageSender;
use App\Services\Sla\SlaService;
use Carbon\CarbonInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use stdClass;

class NotificationCenterService
{
    /**
     * Query paginated in-app notifications strictly scoped to the authenticated user.
     *
     * @param  array{search?: string, category?: string, status?: string, priority?: string}  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginatedInbox(User $user, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $allowedCategories = array_keys(NotificationCatalog::categoriesForRole($user->role));
        $search = trim((string) ($filters['search'] ?? ''));
        $category = (string) ($filters['category'] ?? 'all');
        $status = (string) ($filters['status'] ?? 'all');
        $priority = (string) ($filters['priority'] ?? 'all');

        $query = DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->id)
            ->whereIn('category', $allowedCategories);

        if ($status === 'archived') {
            $query->whereNotNull('archived_at');
        } else {
            $query->whereNull('archived_at');

            if ($status === 'unread') {
                $query->whereNull('read_at');
            } elseif ($status === 'read') {
                $query->whereNotNull('read_at');
            }
        }

        if ($category !== 'all' && in_array($category, $allowedCategories, true)) {
            $query->where('category', $category);
        }

        if (in_array($priority, ['normal', 'high', 'critical'], true)) {
            $query->where('priority', $priority);
        }

        if ($search !== '') {
            $query->where('data', 'like', "%{$search}%");
        }

        /** @var LengthAwarePaginator<int, stdClass> $paginator */
        $paginator = $query
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();

        /** @var LengthAwarePaginator<int, array<string, mixed>> $mapped */
        $mapped = $paginator->through(function (stdClass $row) use ($user): array {
            /** @var array<string, mixed> $payload */
            $payload = json_decode((string) $row->data, true) ?? [];
            $eventKey = (string) ($payload['event_key'] ?? 'system');
            $categorySlug = (string) ($row->category ?: NotificationCatalog::categoryForEvent($eventKey));
            $safeHref = $this->resolveSafeHref($user, $payload['href'] ?? null);
            $createdAt = Carbon::parse((string) $row->created_at);

            return [
                'id' => (string) $row->id,
                'event_key' => $eventKey,
                'category' => $categorySlug,
                'category_label' => NotificationCatalog::CATEGORIES[$categorySlug]['label'] ?? 'Sistem',
                'priority' => (string) ($row->priority ?: NotificationCatalog::priorityForEvent($eventKey)),
                'title' => (string) ($payload['title'] ?? 'Notifikasi'),
                'body' => (string) ($payload['body'] ?? ''),
                'href' => $safeHref,
                'href_accessible' => $safeHref !== null,
                'channel_label' => 'In-App',
                'read_at' => $row->read_at !== null ? Carbon::parse((string) $row->read_at)->translatedFormat('d M Y, H.i') : null,
                'is_read' => $row->read_at !== null,
                'is_archived' => $row->archived_at !== null,
                'created_at' => $createdAt->translatedFormat('d M Y, H.i'),
                'created_at_relative' => $createdAt->diffForHumans(),
            ];
        });

        return $mapped;
    }

    /**
     * Compute badge and summary statistics strictly for the authenticated user.
     *
     * @return array{unread: int, high_priority_unread: int, archived: int, total_active: int}
     */
    public function stats(User $user): array
    {
        $allowedCategories = array_keys(NotificationCatalog::categoriesForRole($user->role));
        $base = DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->id)
            ->whereIn('category', $allowedCategories);

        return [
            'unread' => (clone $base)->whereNull('archived_at')->whereNull('read_at')->count(),
            'high_priority_unread' => (clone $base)->whereNull('archived_at')->whereNull('read_at')->whereIn('priority', ['high', 'critical'])->count(),
            'archived' => (clone $base)->whereNotNull('archived_at')->count(),
            'total_active' => (clone $base)->whereNull('archived_at')->count(),
        ];
    }

    /**
     * Return user notification preferences for every category permitted for the user's role.
     *
     * @return list<array{category: string, label: string, description: string, mandatory: bool, in_app_enabled: bool, whatsapp_enabled: bool}>
     */
    public function preferencesForUser(User $user): array
    {
        $categories = NotificationCatalog::categoriesForRole($user->role);
        $stored = UserNotificationPreference::query()
            ->where('user_id', $user->id)
            ->get()
            ->keyBy('category');

        $result = [];
        foreach ($categories as $slug => $meta) {
            /** @var UserNotificationPreference|null $pref */
            $pref = $stored->get($slug);
            $mandatory = $meta['mandatory'];

            $result[] = [
                'category' => $slug,
                'label' => $meta['label'],
                'description' => $meta['description'],
                'mandatory' => $mandatory,
                'in_app_enabled' => $mandatory ? true : ($pref->in_app_enabled ?? true),
                'whatsapp_enabled' => $mandatory ? true : ($pref->whatsapp_enabled ?? true),
            ];
        }

        return $result;
    }

    /**
     * Persist user notification preferences while protecting mandatory categories.
     *
     * @param  list<array{category: string, in_app_enabled: bool, whatsapp_enabled: bool}>  $items
     * @return list<array{category: string, in_app_enabled: bool, whatsapp_enabled: bool}>
     */
    public function updatePreferences(User $user, array $items, ?string $ipAddress = null): array
    {
        $allowed = NotificationCatalog::categoriesForRole($user->role);
        $saved = [];

        DB::transaction(function () use ($user, $items, $allowed, $ipAddress, &$saved): void {
            foreach ($items as $entry) {
                $category = (string) $entry['category'];
                if (! array_key_exists($category, $allowed)) {
                    continue;
                }

                $mandatory = (bool) $allowed[$category]['mandatory'];
                $inApp = $mandatory ? true : (bool) $entry['in_app_enabled'];
                $whatsapp = $mandatory ? true : (bool) $entry['whatsapp_enabled'];

                UserNotificationPreference::query()->updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'category' => $category,
                    ],
                    [
                        'in_app_enabled' => $inApp,
                        'whatsapp_enabled' => $whatsapp,
                    ],
                );

                $saved[] = [
                    'category' => $category,
                    'in_app_enabled' => $inApp,
                    'whatsapp_enabled' => $whatsapp,
                ];
            }

            DB::table('audit_logs')->insert([
                'actor_id' => $user->id,
                'event' => 'user.notification_preferences_updated',
                'auditable_type' => User::class,
                'auditable_id' => (string) $user->id,
                'before' => null,
                'after' => json_encode(['preferences' => $saved]),
                'ip_address' => $ipAddress,
                'created_at' => now(),
            ]);
        });

        return $saved;
    }

    /**
     * Build deduplicated, role-scoped priority operational alerts from live persisted state.
     *
     * @return list<array{key: string, severity: string, category: string, title: string, description: string, count: int, href: string|null}>
     */
    public function priorityAlerts(User $user): array
    {
        $alerts = [];

        if (in_array($user->role, ['operator', 'admin', 'supervisor', 'technician'], true)) {
            $slaQuery = ServiceOrder::query()
                ->whereNotIn('status', ['completed', 'warranty', 'closed', 'cancelled']);

            if ($user->role === 'technician') {
                $slaQuery->where(function ($q) use ($user): void {
                    $q->where('assigned_technician_id', $user->id)
                        ->orWhereHas('assignments', fn ($a) => $a->where('user_id', $user->id)->where('status', 'active'));
                });
            }

            $breachedCount = app(SlaService::class)->metricsForQuery($slaQuery)['breached'];
            if ($breachedCount > 0) {
                $alerts[] = [
                    'key' => 'sla-breached',
                    'severity' => 'critical',
                    'category' => 'SLA',
                    'title' => "{$breachedCount} tiket melewati tenggat SLA",
                    'description' => 'Segera tindak lanjuti tiket prioritas dengan status SLA breached.',
                    'count' => $breachedCount,
                    'href' => in_array($user->role, ['operator', 'admin', 'supervisor'], true)
                        ? route('orders.index')
                        : route('workforce.index'),
                ];
            }
        }

        if (in_array($user->role, ['operator', 'admin', 'supervisor', 'warehouse', 'technician'], true)) {
            $outOfStockCount = InventoryItem::query()
                ->where('is_active', true)
                ->where('quantity_on_hand', '<=', 0)
                ->count();

            if ($outOfStockCount > 0) {
                $alerts[] = [
                    'key' => 'inventory-out-of-stock',
                    'severity' => 'critical',
                    'category' => 'Inventaris',
                    'title' => "{$outOfStockCount} item stok habis (0 unit)",
                    'description' => 'Lakukan restock segera agar pengerjaan teknisi tidak tertunda.',
                    'count' => $outOfStockCount,
                    'href' => route('inventory.index'),
                ];
            }
        }

        if (in_array($user->role, ['operator', 'admin', 'supervisor'], true)) {
            $openClaimsCount = WarrantyClaim::query()
                ->whereIn('status', WarrantyClaim::ACTIVE_STATUSES)
                ->count();

            if ($openClaimsCount > 0) {
                $alerts[] = [
                    'key' => 'warranty-open-claims',
                    'severity' => 'high',
                    'category' => 'Garansi',
                    'title' => "{$openClaimsCount} klaim garansi menunggu penanganan",
                    'description' => 'Verifikasi kelayakan dan putuskan tindak lanjut klaim pelanggan.',
                    'count' => $openClaimsCount,
                    'href' => route('warranties.index'),
                ];
            }
        } elseif ($user->role === 'customer') {
            $expiringOwnedCount = Warranty::query()
                ->where('customer_id', $user->id)
                ->where('status', 'active')
                ->where('expires_at', '>', now())
                ->where('expires_at', '<=', now()->addDays(7))
                ->count();

            if ($expiringOwnedCount > 0) {
                $alerts[] = [
                    'key' => 'customer-expiring-warranty',
                    'severity' => 'high',
                    'category' => 'Garansi Saya',
                    'title' => "{$expiringOwnedCount} garansi perangkat segera berakhir",
                    'description' => 'Periksa cakupan garansi perangkat Anda sebelum masa berlaku habis.',
                    'count' => $expiringOwnedCount,
                    'href' => route('devices.index'),
                ];
            }
        }

        if (in_array($user->role, ['operator', 'admin'], true)) {
            $failedDeliveriesCount = NotificationDelivery::query()
                ->where('status', 'failed')
                ->count();

            if ($failedDeliveriesCount > 0) {
                $alerts[] = [
                    'key' => 'delivery-failures',
                    'severity' => 'high',
                    'category' => 'Pengiriman WA',
                    'title' => "{$failedDeliveriesCount} pengiriman WhatsApp gagal",
                    'description' => 'Notifikasi in-app tetap tersimpan; periksa tab Pengiriman WA untuk detail dan kirim ulang.',
                    'count' => $failedDeliveriesCount,
                    'href' => route('notifications.index', ['tab' => 'deliveries']),
                ];
            }
        }

        return $alerts;
    }

    /**
     * Build a chronological role-scoped operational activity & audit feed from persisted records.
     *
     * @return list<array<string, mixed>>
     */
    public function activityFeed(User $user, string $filter = 'all', int $limit = 35): array
    {
        $entries = [];
        $isManager = in_array($user->role, ['operator', 'admin', 'supervisor'], true);
        $isCustomer = $user->role === 'customer';
        $isTechnician = $user->role === 'technician';
        $isWarehouse = $user->role === 'warehouse';

        // 1. Order status events (role-scoped, never exposing internal events to customers or unlinked warehouse users)
        if (! $isWarehouse && in_array($filter, ['all', 'orders', 'sla'], true)) {
            $eventsQuery = OrderStatusEvent::query()
                ->with(['order:id,ticket_number,customer_id,assigned_technician_id,customer_name', 'actor:id,name,role'])
                ->latest('occurred_at')
                ->limit(25);

            if ($isCustomer) {
                $eventsQuery
                    ->where('visibility', 'customer')
                    ->whereHas('order', fn ($q) => $q->where('customer_id', $user->id));
            } elseif ($isTechnician) {
                $eventsQuery->whereHas('order', function ($q) use ($user): void {
                    $q->where('assigned_technician_id', $user->id)
                        ->orWhereHas('assignments', fn ($a) => $a->where('user_id', $user->id)->where('status', 'active'));
                });
            }

            foreach ($eventsQuery->get() as $event) {
                $cat = str_contains(mb_strtolower($event->title), 'sla') ? 'sla' : 'orders';
                if ($filter !== 'all' && $filter !== $cat) {
                    continue;
                }

                $order = $event->order;
                $entries[] = $this->formatActivityEntry(
                    id: "evt-{$event->id}",
                    feedType: 'activity',
                    category: $cat,
                    categoryLabel: $cat === 'sla' ? 'SLA' : 'Pesanan',
                    title: "{$event->title} · {$order->ticket_number}",
                    description: $event->description ?: "Pembaruan status pada tiket {$order->ticket_number}.",
                    actor: $isCustomer ? ($event->actor?->role === 'customer' ? $event->actor->name : 'Tim AD Com') : ($event->actor->name ?? 'Sistem'),
                    href: route('orders.show', $order),
                    timestamp: $event->occurred_at,
                );
            }
        }

        // 2. Technician work sessions (customers see generic non-private milestone without internal technician notes)
        if (! $isWarehouse && in_array($filter, ['all', 'workforce'], true)) {
            $sessionsQuery = TechnicianWorkSession::query()
                ->with(['serviceOrder:id,ticket_number,customer_id', 'technician:id,name'])
                ->latest('started_at')
                ->limit(15);

            if ($isCustomer) {
                $sessionsQuery->whereHas('serviceOrder', fn ($q) => $q->where('customer_id', $user->id));
            } elseif ($isTechnician) {
                $sessionsQuery->where('technician_id', $user->id);
            }

            foreach ($sessionsQuery->get() as $session) {
                $statusText = $session->completed_at !== null ? 'Sesi pengerjaan selesai' : 'Sesi pengerjaan berlangsung';
                $desc = $isCustomer
                    ? "Pengerjaan teknis untuk tiket {$session->serviceOrder->ticket_number}."
                    : ($session->notes ?: "Teknisi {$session->technician->name} menangani tiket {$session->serviceOrder->ticket_number}.");

                $entries[] = $this->formatActivityEntry(
                    id: "ws-{$session->id}",
                    feedType: 'activity',
                    category: 'workforce',
                    categoryLabel: 'Workforce',
                    title: "{$statusText} · {$session->serviceOrder->ticket_number}",
                    description: $desc,
                    actor: $isCustomer ? 'Teknisi AD Com' : $session->technician->name,
                    href: route('orders.show', $session->serviceOrder),
                    timestamp: $session->completed_at ?? $session->started_at,
                );
            }
        }

        // 3. Inventory & spare-part movements (strictly hidden from customers)
        if (! $isCustomer && in_array($filter, ['all', 'inventory'], true)) {
            $movementsQuery = InventoryMovement::query()
                ->with(['item:id,sku,name,unit', 'actor:id,name'])
                ->latest('created_at')
                ->limit(20);

            if ($isTechnician) {
                $movementsQuery->where('actor_id', $user->id);
            }

            foreach ($movementsQuery->get() as $movement) {
                $typeLabel = match ($movement->type) {
                    'stock_in' => 'Stok masuk',
                    'stock_out' => 'Stok keluar',
                    'spare_part_issue' => 'Spare-part dikeluarkan',
                    'spare_part_return' => 'Spare-part dikembalikan',
                    default => 'Penyesuaian stok',
                };

                $entries[] = $this->formatActivityEntry(
                    id: "inv-{$movement->id}",
                    feedType: 'activity',
                    category: 'inventory',
                    categoryLabel: 'Inventaris',
                    title: "{$typeLabel} · {$movement->item->name}",
                    description: abs($movement->quantity)." {$movement->item->unit}".($movement->notes ? " — {$movement->notes}" : ''),
                    actor: $movement->actor->name ?? 'Tim Gudang',
                    href: route('inventory.index'),
                    timestamp: $movement->created_at,
                );
            }
        }

        // 4. Warranties & Claims (customer sees only own; managers see all)
        if (($isManager || $isCustomer) && in_array($filter, ['all', 'warranty'], true)) {
            $claimsQuery = WarrantyClaim::query()
                ->with(['warranty:id,warranty_number,item_label', 'customer:id,name', 'reviewer:id,name'])
                ->latest('submitted_at')
                ->limit(15);

            if ($isCustomer) {
                $claimsQuery->where('customer_id', $user->id);
            }

            foreach ($claimsQuery->get() as $claim) {
                $entries[] = $this->formatActivityEntry(
                    id: "clm-{$claim->id}",
                    feedType: 'activity',
                    category: 'warranty',
                    categoryLabel: 'Garansi',
                    title: "Klaim {$claim->claim_number} ({$claim->status})",
                    description: "{$claim->warranty->item_label} — {$claim->issue_description}",
                    actor: $isCustomer
                        ? ($claim->reviewed_at !== null ? 'Tim AD Com' : ($claim->customer->name ?? 'Saya'))
                        : ($claim->reviewer->name ?? $claim->customer->name ?? 'Pelanggan'),
                    href: $isManager
                        ? route('warranties.index')
                        : ($claim->customer_asset_id ? route('devices.show', $claim->customer_asset_id) : route('orders.show', $claim->original_service_order_id)),
                    timestamp: $claim->resolved_at ?? $claim->reviewed_at ?? $claim->submitted_at,
                );
            }
        }

        // 5. Internal Audit Logs (STRICTLY manager roles: operator, admin, supervisor; NEVER customers/technician/warehouse)
        if ($isManager && in_array($filter, ['all', 'audit'], true)) {
            $auditRows = DB::table('audit_logs')
                ->leftJoin('users', 'audit_logs.actor_id', '=', 'users.id')
                ->select(['audit_logs.id', 'audit_logs.event', 'audit_logs.auditable_type', 'audit_logs.auditable_id', 'audit_logs.created_at', 'users.name as actor_name'])
                ->orderByDesc('audit_logs.created_at')
                ->limit(20)
                ->get();

            foreach ($auditRows as $audit) {
                $entries[] = $this->formatActivityEntry(
                    id: "aud-{$audit->id}",
                    feedType: 'audit',
                    category: 'audit',
                    categoryLabel: 'Log Audit',
                    title: (string) $audit->event,
                    description: class_basename((string) $audit->auditable_type).' #'.$audit->auditable_id,
                    actor: $audit->actor_name ? (string) $audit->actor_name : 'Sistem',
                    href: null,
                    timestamp: Carbon::parse((string) $audit->created_at),
                );
            }
        }

        usort($entries, static function (array $left, array $right): int {
            return strcmp((string) $right['occurred_at_iso'], (string) $left['occurred_at_iso']);
        });

        return array_slice($entries, 0, $limit);
    }

    /**
     * Fetch WhatsApp delivery observability logs for operator/admin roles only.
     *
     * @return array{
     *     authorized: bool,
     *     can_retry: bool,
     *     queue_connection: string,
     *     pending_jobs: int,
     *     failed_jobs: int,
     *     items: list<array<string, mixed>>
     * }
     */
    public function deliveryLogs(User $user, FonnteMessageSender $sender): array
    {
        if (! in_array($user->role, ['operator', 'admin'], true)) {
            return [
                'authorized' => false,
                'can_retry' => false,
                'queue_connection' => '',
                'pending_jobs' => 0,
                'failed_jobs' => 0,
                'items' => [],
            ];
        }

        $pendingJobs = Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0;
        $failedJobs = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;

        $items = array_values(NotificationDelivery::query()
            ->with(['user:id,name,role', 'retrier:id,name'])
            ->latest('id')
            ->limit(25)
            ->get()
            ->map(function (NotificationDelivery $delivery) use ($user, $sender): array {
                $rawError = is_string($delivery->last_error) ? $delivery->last_error : null;

                return [
                    'id' => $delivery->id,
                    'event_key' => $delivery->event_key,
                    'channel' => strtoupper($delivery->channel),
                    'recipient_name' => $delivery->user->name ?? 'Pengguna',
                    'target' => (string) ($delivery->payload_summary['target'] ?? '—'),
                    'title' => (string) ($delivery->payload_summary['title'] ?? $delivery->event_key),
                    'status' => $delivery->status,
                    'attempts' => $delivery->attempts,
                    'last_error' => $rawError !== null ? $sender->sanitizeErrorMessage($rawError) : null,
                    'sent_at' => $delivery->sent_at?->translatedFormat('d M Y, H.i'),
                    'retried_at' => $delivery->retried_at?->translatedFormat('d M Y, H.i'),
                    'retrier_name' => $delivery->retrier?->name,
                    'created_at' => $delivery->created_at?->translatedFormat('d M Y, H.i'),
                    'can_retry' => $user->role === 'operator' && in_array($delivery->status, ['failed', 'skipped'], true),
                ];
            })
            ->all());

        return [
            'authorized' => true,
            'can_retry' => $user->role === 'operator',
            'queue_connection' => (string) config('queue.default'),
            'pending_jobs' => $pendingJobs,
            'failed_jobs' => $failedJobs,
            'items' => $items,
        ];
    }

    /**
     * Safely retry a failed or skipped WhatsApp notification delivery once.
     */
    public function retryDelivery(NotificationDelivery $delivery, User $actor, ?string $ipAddress = null): NotificationDelivery
    {
        return DB::transaction(function () use ($delivery, $actor, $ipAddress): NotificationDelivery {
            $locked = NotificationDelivery::query()->lockForUpdate()->findOrFail($delivery->id);

            if (! in_array($locked->status, ['failed', 'skipped'], true)) {
                throw ValidationException::withMessages([
                    'delivery' => 'Pengiriman dengan status saat ini tidak perlu dikirim ulang.',
                ]);
            }

            $now = now();
            $locked->forceFill([
                'status' => 'queued',
                'last_error' => null,
                'retried_by' => $actor->id,
                'retried_at' => $now,
            ])->save();

            DB::table('audit_logs')->insert([
                'actor_id' => $actor->id,
                'event' => 'notification_delivery.retried',
                'auditable_type' => NotificationDelivery::class,
                'auditable_id' => (string) $locked->id,
                'before' => json_encode(['status' => $delivery->status]),
                'after' => json_encode(['status' => 'queued']),
                'ip_address' => $ipAddress,
                'created_at' => $now,
            ]);

            $title = (string) ($locked->payload_summary['title'] ?? $locked->event_key);
            SendWhatsAppNotificationJob::dispatch(
                $locked->id,
                "[AD Com] {$title} (Kirim ulang)",
            );

            return $locked;
        });
    }

    /**
     * Validate notification target URLs against the current viewer's authorization policies.
     */
    public function resolveSafeHref(User $user, mixed $rawHref): ?string
    {
        if (! is_string($rawHref) || trim($rawHref) === '') {
            return null;
        }

        $path = parse_url($rawHref, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return null;
        }

        if (preg_match('#^/orders/([A-Za-z0-9\-]+)$#', $path, $matches) === 1) {
            $order = ServiceOrder::query()->find($matches[1]);
            if ($order === null || Gate::forUser($user)->denies('view', $order)) {
                return null;
            }

            return $rawHref;
        }

        if (preg_match('#^/devices/([A-Za-z0-9\-]+)$#', $path, $matches) === 1) {
            $asset = CustomerAsset::query()->find($matches[1]);
            if ($asset === null || Gate::forUser($user)->denies('view', $asset)) {
                return null;
            }

            return $rawHref;
        }

        if (str_starts_with($path, '/warranties')) {
            return in_array($user->role, ['operator', 'admin', 'supervisor'], true) ? $rawHref : null;
        }

        if (str_starts_with($path, '/inventory')) {
            return in_array($user->role, ['operator', 'admin', 'supervisor', 'technician', 'warehouse'], true) ? $rawHref : null;
        }

        if (str_starts_with($path, '/workforce')) {
            return in_array($user->role, ['operator', 'admin', 'supervisor', 'technician'], true) ? $rawHref : null;
        }

        if (preg_match('#^/finance/quotations/([A-Za-z0-9\-]+)$#', $path, $matches) === 1) {
            $estimate = Estimate::query()->find($matches[1]);
            if ($estimate === null || Gate::forUser($user)->denies('view', $estimate)) {
                return null;
            }

            return $rawHref;
        }

        if ($path === '/finance/quotations') {
            return Gate::forUser($user)->allows('viewAny', Estimate::class) ? $rawHref : null;
        }

        if (preg_match('#^/finance/invoices/([A-Za-z0-9\-]+)$#', $path, $matches) === 1) {
            $invoice = Invoice::query()->find($matches[1]);
            if ($invoice === null || Gate::forUser($user)->denies('view', $invoice)) {
                return null;
            }

            return $rawHref;
        }

        if ($path === '/finance/invoices') {
            return Gate::forUser($user)->allows('viewAny', Invoice::class) ? $rawHref : null;
        }

        if (str_starts_with($path, '/finance')) {
            return in_array($user->role, ['operator', 'admin'], true) ? $rawHref : null;
        }

        if (str_starts_with($path, '/settings/integrations')) {
            return $user->role === 'operator' ? $rawHref : null;
        }

        return $rawHref;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatActivityEntry(
        string $id,
        string $feedType,
        string $category,
        string $categoryLabel,
        string $title,
        string $description,
        string $actor,
        ?string $href,
        CarbonInterface $timestamp,
    ): array {
        return [
            'id' => $id,
            'feed_type' => $feedType,
            'category' => $category,
            'category_label' => $categoryLabel,
            'title' => $title,
            'description' => $description,
            'actor' => $actor,
            'href' => $href,
            'occurred_at' => $timestamp->translatedFormat('d M Y, H.i'),
            'occurred_at_relative' => $timestamp->diffForHumans(),
            'occurred_at_iso' => $timestamp->toIso8601String(),
        ];
    }
}
