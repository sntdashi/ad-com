<?php

namespace App\Services\CustomerHistory;

use App\Models\AssetServiceRecord;
use App\Models\CustomerAsset;
use App\Models\ServiceOrder;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerDeviceService
{
    /**
     * Register a new customer device with duplicate serial protection.
     *
     * @param  array<string, mixed>  $data
     */
    public function registerDevice(array $data, User $actor, ?User $owner = null, ?string $ipAddress = null): CustomerAsset
    {
        $ownerId = $owner?->id;
        if ($ownerId === null && $actor->role === 'customer') {
            $ownerId = $actor->id;
        } elseif ($ownerId === null && ! empty($data['customer_id'])) {
            $ownerId = (int) $data['customer_id'];
        }

        $normalizedSerial = CustomerAsset::normalizeSerial(
            isset($data['serial_number']) ? (string) $data['serial_number'] : null,
        );
        $serialHash = CustomerAsset::hashSerial($normalizedSerial);
        $serialMasked = CustomerAsset::maskSerial($normalizedSerial);

        $asset = DB::transaction(function () use ($data, $actor, $ownerId, $serialHash, $serialMasked, $ipAddress): CustomerAsset {
            $this->ensureNoDuplicateSerial($ownerId, $serialHash);

            $asset = CustomerAsset::query()->create([
                'customer_id' => $ownerId,
                'origin_order_id' => ! empty($data['origin_order_id']) ? (string) $data['origin_order_id'] : null,
                'device_code' => $this->generateDeviceCode(),
                'name' => trim((string) $data['name']),
                'category' => trim((string) $data['category']),
                'brand' => $this->cleanNullableString($data['brand'] ?? null),
                'model' => $this->cleanNullableString($data['model'] ?? null),
                'serial_masked' => $serialMasked,
                'serial_number_hash' => $serialHash,
                'location' => $this->cleanNullableString($data['location'] ?? null),
                'status' => in_array((string) ($data['status'] ?? 'active'), ['active', 'in_service', 'retired', 'transferred'], true)
                    ? (string) ($data['status'] ?? 'active')
                    : 'active',
                'notes' => $this->cleanNullableString($data['notes'] ?? null),
                'registered_at' => now(),
            ]);

            DB::table('audit_logs')->insert([
                'actor_id' => $actor->id,
                'event' => 'customer_asset.registered',
                'auditable_type' => CustomerAsset::class,
                'auditable_id' => $asset->id,
                'before' => null,
                'after' => json_encode([
                    'device_code' => $asset->device_code,
                    'customer_id' => $asset->customer_id,
                    'name' => $asset->name,
                    'category' => $asset->category,
                    'serial_masked' => $asset->serial_masked,
                    'status' => $asset->status,
                ]),
                'ip_address' => $ipAddress,
                'created_at' => now(),
            ]);

            return $asset;
        });

        return $asset;
    }

    /**
     * Update device metadata while preserving historical asset_service_records snapshots.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateDevice(CustomerAsset $asset, array $data, User $actor, ?string $ipAddress = null): CustomerAsset
    {
        $normalizedSerial = array_key_exists('serial_number', $data)
            ? CustomerAsset::normalizeSerial($data['serial_number'] !== null ? (string) $data['serial_number'] : null)
            : null;
        $hasSerialUpdate = array_key_exists('serial_number', $data);
        $serialHash = $hasSerialUpdate ? CustomerAsset::hashSerial($normalizedSerial) : $asset->serial_number_hash;
        $serialMasked = $hasSerialUpdate ? CustomerAsset::maskSerial($normalizedSerial) : $asset->serial_masked;

        return DB::transaction(function () use ($asset, $data, $actor, $hasSerialUpdate, $serialHash, $serialMasked, $ipAddress): CustomerAsset {
            $locked = CustomerAsset::query()->lockForUpdate()->findOrFail($asset->id);

            if ($hasSerialUpdate) {
                $this->ensureNoDuplicateSerial($locked->customer_id, $serialHash, $locked->id);
            }

            $before = $locked->only(['name', 'category', 'brand', 'model', 'serial_masked', 'location', 'status', 'notes']);

            $locked->forceFill([
                'name' => isset($data['name']) ? trim((string) $data['name']) : $locked->name,
                'category' => isset($data['category']) ? trim((string) $data['category']) : $locked->category,
                'brand' => array_key_exists('brand', $data) ? $this->cleanNullableString($data['brand']) : $locked->brand,
                'model' => array_key_exists('model', $data) ? $this->cleanNullableString($data['model']) : $locked->model,
                'serial_masked' => $serialMasked,
                'serial_number_hash' => $serialHash,
                'location' => array_key_exists('location', $data) ? $this->cleanNullableString($data['location']) : $locked->location,
                'status' => isset($data['status']) && in_array((string) $data['status'], ['active', 'in_service', 'retired', 'transferred'], true)
                    ? (string) $data['status']
                    : $locked->status,
                'notes' => array_key_exists('notes', $data) ? $this->cleanNullableString($data['notes']) : $locked->notes,
            ])->save();

            DB::table('audit_logs')->insert([
                'actor_id' => $actor->id,
                'event' => 'customer_asset.updated',
                'auditable_type' => CustomerAsset::class,
                'auditable_id' => $locked->id,
                'before' => json_encode($before),
                'after' => json_encode($locked->only(['name', 'category', 'brand', 'model', 'serial_masked', 'location', 'status', 'notes'])),
                'ip_address' => $ipAddress,
                'created_at' => now(),
            ]);

            return $locked;
        });
    }

    /**
     * Link a service order to a device and preserve an immutable historical snapshot.
     *
     * @param  array{diagnosis?: string|null, action_taken?: string|null, test_result?: string|null, recommendation?: string|null, warranty_until?: string|null}  $recordNotes
     */
    public function linkOrderToDevice(
        ServiceOrder $order,
        CustomerAsset $asset,
        User $actor,
        array $recordNotes = [],
        ?string $ipAddress = null,
    ): AssetServiceRecord {
        if ($order->customer_id !== null && $asset->customer_id !== null && $order->customer_id !== $asset->customer_id) {
            throw ValidationException::withMessages([
                'customer_asset_id' => 'Perangkat yang dipilih dimiliki oleh akun pelanggan yang berbeda.',
            ]);
        }

        return DB::transaction(function () use ($order, $asset, $actor, $recordNotes, $ipAddress): AssetServiceRecord {
            $lockedOrder = ServiceOrder::query()->lockForUpdate()->findOrFail($order->id);
            $lockedAsset = CustomerAsset::query()->lockForUpdate()->findOrFail($asset->id);

            $previousAssetId = $lockedOrder->customer_asset_id;
            $lockedOrder->forceFill(['customer_asset_id' => $lockedAsset->id])->save();

            if ($lockedAsset->origin_order_id === null) {
                $lockedAsset->origin_order_id = $lockedOrder->id;
            }

            if ($lockedOrder->completed_at !== null && ($lockedAsset->last_serviced_at === null || $lockedOrder->completed_at->greaterThan($lockedAsset->last_serviced_at))) {
                $lockedAsset->last_serviced_at = $lockedOrder->completed_at;
            } elseif ($lockedAsset->last_serviced_at === null) {
                $lockedAsset->last_serviced_at = $lockedOrder->created_at;
            }

            $lockedAsset->save();

            $existing = AssetServiceRecord::query()
                ->where('customer_asset_id', $lockedAsset->id)
                ->where('service_order_id', $lockedOrder->id)
                ->first();

            $snapshot = $existing->device_snapshot ?? [
                'device_code' => $lockedAsset->device_code,
                'name' => $lockedAsset->name,
                'category' => $lockedAsset->category,
                'brand' => $lockedAsset->brand,
                'model' => $lockedAsset->model,
                'serial_masked' => $lockedAsset->serial_masked,
                'location' => $lockedAsset->location,
            ];

            $record = AssetServiceRecord::query()->updateOrCreate(
                [
                    'customer_asset_id' => $lockedAsset->id,
                    'service_order_id' => $lockedOrder->id,
                ],
                [
                    'device_snapshot' => $snapshot,
                    'diagnosis' => $this->cleanNullableString($recordNotes['diagnosis'] ?? $existing?->diagnosis),
                    'action_taken' => $this->cleanNullableString($recordNotes['action_taken'] ?? $existing?->action_taken),
                    'test_result' => $this->cleanNullableString($recordNotes['test_result'] ?? $existing?->test_result),
                    'recommendation' => $this->cleanNullableString($recordNotes['recommendation'] ?? $existing?->recommendation),
                    'warranty_until' => $recordNotes['warranty_until'] ?? $existing?->warranty_until,
                ],
            );

            DB::table('audit_logs')->insert([
                'actor_id' => $actor->id,
                'event' => 'service_order.device_linked',
                'auditable_type' => ServiceOrder::class,
                'auditable_id' => $lockedOrder->id,
                'before' => json_encode(['customer_asset_id' => $previousAssetId]),
                'after' => json_encode([
                    'customer_asset_id' => $lockedAsset->id,
                    'device_code' => $lockedAsset->device_code,
                ]),
                'ip_address' => $ipAddress,
                'created_at' => now(),
            ]);

            return $record;
        });
    }

    /**
     * Build a unified historical timeline for a single service order strictly from persisted records.
     *
     * @return list<array<string, mixed>>
     */
    public function buildOrderTimeline(ServiceOrder $order, User $viewer): array
    {
        $order->loadMissing([
            'service:id,name',
            'statusEvents.actor:id,name,role',
            'assignments.user:id,name,role',
            'workSessions.technician:id,name',
            'spareParts.item:id,name,sku,unit',
            'spareParts.requester:id,name',
            'spareParts.approver:id,name',
            'spareParts.returner:id,name',
            'workEvidences.uploader:id,name,role',
            'workEvidences.confirmer:id,name',
            'warranties.activatedBy:id,name',
            'warranties.voidedBy:id,name',
            'warrantyClaims.submitter:id,name',
            'warrantyClaims.reviewer:id,name',
            'warrantyClaims.resolver:id,name',
        ]);

        $isInternal = $viewer->role !== 'customer';
        $events = [];

        foreach ($order->statusEvents as $statusEvent) {
            if (! $isInternal && $statusEvent->visibility !== 'customer') {
                continue;
            }

            $events[] = $this->formatTimelineEntry(
                key: "status-{$statusEvent->id}",
                source: 'status',
                title: $statusEvent->title,
                description: $statusEvent->description,
                timestamp: $statusEvent->occurred_at,
                actor: $this->resolveTimelineActor($statusEvent->actor, $isInternal),
                ticketNumber: $order->ticket_number,
                orderHref: null,
                visibility: $statusEvent->visibility,
            );
        }

        foreach ($order->assignments as $assignment) {
            if ($assignment->assignment_role === 'technician') {
                $techName = $assignment->user->name ?? 'Teknisi';
                $events[] = $this->formatTimelineEntry(
                    key: "assign-{$assignment->id}",
                    source: 'assignment',
                    title: 'Teknisi ditugaskan',
                    description: "{$techName} ditugaskan ke tiket {$order->ticket_number}.",
                    timestamp: $assignment->assigned_at,
                    actor: $isInternal ? $techName : null,
                    ticketNumber: $order->ticket_number,
                );
            }
        }

        foreach ($order->workSessions as $session) {
            $techName = $session->technician->name ?? 'Teknisi';
            $sessionNote = $isInternal ? $session->notes : null;

            $events[] = $this->formatTimelineEntry(
                key: "ws-start-{$session->id}",
                source: 'work_session',
                title: 'Pengerjaan dimulai',
                description: $sessionNote ?: ($isInternal ? "Sesi kerja dimulai oleh {$techName}." : 'Teknisi memulai sesi pengerjaan.'),
                timestamp: $session->started_at,
                actor: $isInternal ? $techName : null,
                ticketNumber: $order->ticket_number,
            );

            if ($session->paused_at !== null) {
                $events[] = $this->formatTimelineEntry(
                    key: "ws-pause-{$session->id}",
                    source: 'work_session',
                    title: 'Pengerjaan dijeda sementara',
                    description: 'Sesi pengerjaan dihentikan sementara.',
                    timestamp: $session->paused_at,
                    actor: $isInternal ? $techName : null,
                    ticketNumber: $order->ticket_number,
                );
            }

            if ($session->resumed_at !== null) {
                $events[] = $this->formatTimelineEntry(
                    key: "ws-resume-{$session->id}",
                    source: 'work_session',
                    title: 'Pengerjaan dilanjutkan',
                    description: 'Sesi pengerjaan kembali berjalan.',
                    timestamp: $session->resumed_at,
                    actor: $isInternal ? $techName : null,
                    ticketNumber: $order->ticket_number,
                );
            }

            if ($session->completed_at !== null) {
                $minutes = (int) round($session->duration_seconds / 60);
                $events[] = $this->formatTimelineEntry(
                    key: "ws-done-{$session->id}",
                    source: 'work_session',
                    title: 'Sesi perbaikan selesai',
                    description: $minutes > 0 ? "Durasi pengerjaan tercatat {$minutes} menit." : 'Sesi pengerjaan telah diselesaikan.',
                    timestamp: $session->completed_at,
                    actor: $isInternal ? $techName : null,
                    ticketNumber: $order->ticket_number,
                );
            }
        }

        foreach ($order->spareParts as $sparePart) {
            $partLabel = $sparePart->item->name;
            $unit = $sparePart->item->unit;

            if ($isInternal || $sparePart->netIssuedQuantity() > 0) {
                $events[] = $this->formatTimelineEntry(
                    key: "sp-req-{$sparePart->id}",
                    source: 'spare_part',
                    title: 'Permintaan spare-part dicatat',
                    description: "{$partLabel} ({$sparePart->requested_quantity} {$unit}).",
                    timestamp: $sparePart->requested_at,
                    actor: $isInternal ? ($sparePart->requester->name ?? 'Tim') : null,
                    ticketNumber: $order->ticket_number,
                );
            }

            if ($sparePart->issued_at !== null) {
                $events[] = $this->formatTimelineEntry(
                    key: "sp-issue-{$sparePart->id}",
                    source: 'spare_part',
                    title: 'Spare-part dikeluarkan ke pekerjaan',
                    description: "{$partLabel} sebanyak {$sparePart->issued_quantity} {$unit} terpasang/diserahkan.",
                    timestamp: $sparePart->issued_at,
                    actor: $isInternal ? $sparePart->approver?->name : null,
                    ticketNumber: $order->ticket_number,
                );
            }

            if ($sparePart->returned_at !== null && $sparePart->returned_quantity > 0 && $isInternal) {
                $events[] = $this->formatTimelineEntry(
                    key: "sp-ret-{$sparePart->id}",
                    source: 'spare_part',
                    title: 'Spare-part dikembalikan ke gudang',
                    description: "{$partLabel} dikembalikan ({$sparePart->returned_quantity} {$unit}).",
                    timestamp: $sparePart->returned_at,
                    actor: $sparePart->returner?->name,
                    ticketNumber: $order->ticket_number,
                );
            }
        }

        foreach ($order->workEvidences as $evidence) {
            $stageLabel = match ($evidence->stage) {
                'before' => 'sebelum pengerjaan',
                'after' => 'sesudah pengerjaan',
                default => 'penyelesaian',
            };

            $events[] = $this->formatTimelineEntry(
                key: "ev-up-{$evidence->id}",
                source: 'evidence',
                title: "Bukti kerja ({$stageLabel}) diunggah",
                description: $evidence->caption ?: 'Dokumentasi visual pekerjaan ditambahkan.',
                timestamp: $evidence->created_at ?? $order->created_at,
                actor: $isInternal ? $evidence->uploader?->name : null,
                ticketNumber: $order->ticket_number,
            );

            if ($evidence->customer_confirmed_at !== null) {
                $events[] = $this->formatTimelineEntry(
                    key: "ev-conf-{$evidence->id}",
                    source: 'evidence',
                    title: 'Konfirmasi pelanggan diterima',
                    description: $evidence->confirmation_note ?: 'Pelanggan mengonfirmasi hasil bukti pekerjaan.',
                    timestamp: $evidence->customer_confirmed_at,
                    actor: $evidence->confirmer->name ?? $order->customer_name,
                    ticketNumber: $order->ticket_number,
                );
            }
        }

        foreach ($order->warranties as $warranty) {
            if ($warranty->starts_at !== null) {
                $events[] = $this->formatTimelineEntry(
                    key: "wrn-act-{$warranty->id}",
                    source: 'warranty',
                    title: "Garansi {$warranty->warranty_number} diaktifkan",
                    description: "{$warranty->item_label} · {$warranty->duration_days} hari cakupan.",
                    timestamp: $warranty->starts_at,
                    actor: $isInternal ? $warranty->activatedBy?->name : null,
                    ticketNumber: $order->ticket_number,
                );
            }

            if ($warranty->resolvedStatus() === 'expired' && $warranty->expires_at !== null) {
                $events[] = $this->formatTimelineEntry(
                    key: "wrn-exp-{$warranty->id}",
                    source: 'warranty',
                    title: "Garansi {$warranty->warranty_number} berakhir",
                    description: "Masa garansi {$warranty->item_label} telah kedaluwarsa.",
                    timestamp: $warranty->expires_at,
                    actor: null,
                    ticketNumber: $order->ticket_number,
                );
            }

            if ($warranty->voided_at !== null) {
                $events[] = $this->formatTimelineEntry(
                    key: "wrn-void-{$warranty->id}",
                    source: 'warranty',
                    title: "Garansi {$warranty->warranty_number} dinonaktifkan",
                    description: $warranty->void_reason ?: 'Garansi dibatalkan oleh tim operasional.',
                    timestamp: $warranty->voided_at,
                    actor: $isInternal ? $warranty->voidedBy?->name : null,
                    ticketNumber: $order->ticket_number,
                );
            }
        }

        foreach ($order->warrantyClaims as $claim) {
            $events[] = $this->formatTimelineEntry(
                key: "clm-sub-{$claim->id}",
                source: 'warranty_claim',
                title: "Klaim garansi {$claim->claim_number} diajukan",
                description: $claim->issue_description,
                timestamp: $claim->submitted_at,
                actor: $claim->submitter?->name,
                ticketNumber: $order->ticket_number,
            );

            if ($claim->reviewed_at !== null) {
                $reviewTitle = match ($claim->status) {
                    'approved', 'resolved' => "Klaim {$claim->claim_number} disetujui",
                    'rejected' => "Klaim {$claim->claim_number} ditolak",
                    default => "Klaim {$claim->claim_number} ditinjau",
                };
                $events[] = $this->formatTimelineEntry(
                    key: "clm-rev-{$claim->id}",
                    source: 'warranty_claim',
                    title: $reviewTitle,
                    description: $claim->review_notes ?: $claim->eligibility_notes,
                    timestamp: $claim->reviewed_at,
                    actor: $isInternal ? $claim->reviewer?->name : 'Tim AD Com',
                    ticketNumber: $order->ticket_number,
                );
            }

            if ($claim->resolved_at !== null) {
                $events[] = $this->formatTimelineEntry(
                    key: "clm-res-{$claim->id}",
                    source: 'warranty_claim',
                    title: "Klaim {$claim->claim_number} selesai ditangani",
                    description: $claim->resolution_notes,
                    timestamp: $claim->resolved_at,
                    actor: $isInternal ? $claim->resolver?->name : 'Tim AD Com',
                    ticketNumber: $order->ticket_number,
                );
            }
        }

        usort($events, static function (array $left, array $right): int {
            return strcmp((string) $left['occurred_at_iso'], (string) $right['occurred_at_iso']);
        });

        return $events;
    }

    /**
     * Build an aggregated chronological timeline across all orders & warranties attached to a device.
     *
     * @return list<array<string, mixed>>
     */
    public function buildDeviceTimeline(CustomerAsset $asset, User $viewer): array
    {
        $orders = $asset->orders()->get();
        $timeline = [];

        if ($asset->registered_at !== null) {
            $timeline[] = $this->formatTimelineEntry(
                key: "asset-reg-{$asset->id}",
                source: 'device',
                title: 'Perangkat terdaftar di riwayat AD Com',
                description: "{$asset->name} ({$asset->category}) didaftarkan.",
                timestamp: $asset->registered_at,
                actor: null,
                ticketNumber: $asset->device_code,
            );
        }

        foreach ($orders as $order) {
            foreach ($this->buildOrderTimeline($order, $viewer) as $entry) {
                $entry['order_href'] = route('orders.show', $order);
                $timeline[] = $entry;
            }
        }

        usort($timeline, static function (array $left, array $right): int {
            return strcmp((string) $right['occurred_at_iso'], (string) $left['occurred_at_iso']);
        });

        return $timeline;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatTimelineEntry(
        string $key,
        string $source,
        string $title,
        ?string $description,
        CarbonInterface $timestamp,
        ?string $actor,
        ?string $ticketNumber,
        ?string $orderHref = null,
        string $visibility = 'customer',
    ): array {
        return [
            'id' => $key,
            'source' => $source,
            'title' => $title,
            'description' => $description,
            'actor' => $actor,
            'ticket_number' => $ticketNumber,
            'order_href' => $orderHref,
            'visibility' => $visibility,
            'occurred_at_iso' => $timestamp->toIso8601String(),
            'occurred_at' => Carbon::instance($timestamp)->translatedFormat('d M Y, H.i'),
        ];
    }

    private function resolveTimelineActor(?User $actor, bool $isInternal): ?string
    {
        if ($actor === null) {
            return null;
        }

        if ($isInternal || $actor->role === 'customer') {
            return $actor->name;
        }

        return 'Tim AD Com';
    }

    private function ensureNoDuplicateSerial(?int $customerId, ?string $serialHash, ?string $ignoreAssetId = null): void
    {
        if ($serialHash === null) {
            return;
        }

        $exists = CustomerAsset::query()
            ->when($customerId !== null, fn ($q) => $q->where('customer_id', $customerId))
            ->when($customerId === null, fn ($q) => $q->whereNull('customer_id'))
            ->where('serial_number_hash', $serialHash)
            ->when($ignoreAssetId !== null, fn ($q) => $q->where('id', '!=', $ignoreAssetId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'serial_number' => 'Nomor seri perangkat ini sudah terdaftar untuk pelanggan yang sama.',
            ]);
        }
    }

    private function generateDeviceCode(): string
    {
        do {
            $code = 'DEV-'.now()->format('ym').'-'.random_int(1000, 9999);
        } while (CustomerAsset::query()->where('device_code', $code)->exists());

        return $code;
    }

    private function cleanNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
