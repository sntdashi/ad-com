<?php

namespace App\Services\Warranty;

use App\Models\AssetServiceRecord;
use App\Models\CustomerAsset;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderSparePart;
use App\Models\User;
use App\Models\Warranty;
use App\Models\WarrantyClaim;
use App\Services\Notifications\NotificationDispatcher;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WarrantyService
{
    public function __construct(
        private readonly NotificationDispatcher $notifications,
    ) {}

    /**
     * Explicitly register a service-level or spare-part-level warranty for a service order.
     *
     * @param  array<string, mixed>  $data
     */
    public function registerWarranty(
        ServiceOrder $order,
        array $data,
        User $actor,
        ?string $ipAddress = null,
    ): Warranty {
        $scopeType = (string) $data['scope_type'];
        if (! in_array($scopeType, ['service', 'spare_part'], true)) {
            throw ValidationException::withMessages([
                'scope_type' => 'Tipe cakupan garansi harus berupa layanan atau spare-part.',
            ]);
        }

        $sparePartId = ! empty($data['service_order_spare_part_id'])
            ? (int) $data['service_order_spare_part_id']
            : null;

        $warranty = DB::transaction(function () use ($order, $data, $scopeType, $sparePartId, $actor, $ipAddress): Warranty {
            $lockedOrder = ServiceOrder::query()
                ->with(['service', 'device', 'customer'])
                ->lockForUpdate()
                ->findOrFail($order->id);

            $sparePart = null;
            $itemLabel = '';
            $durationDays = isset($data['duration_days']) && $data['duration_days'] !== ''
                ? (int) $data['duration_days']
                : null;
            $coverageDescription = isset($data['coverage_description']) && trim((string) $data['coverage_description']) !== ''
                ? trim((string) $data['coverage_description'])
                : null;

            if ($scopeType === 'spare_part') {
                if ($sparePartId === null) {
                    throw ValidationException::withMessages([
                        'service_order_spare_part_id' => 'Pilih spare-part terpasang untuk garansi komponen.',
                    ]);
                }

                $sparePart = ServiceOrderSparePart::query()
                    ->with('item')
                    ->where('service_order_id', $lockedOrder->id)
                    ->whereKey($sparePartId)
                    ->firstOrFail();

                if ($sparePart->netIssuedQuantity() <= 0) {
                    throw ValidationException::withMessages([
                        'service_order_spare_part_id' => 'Spare-part belum dikeluarkan atau seluruhnya sudah dikembalikan.',
                    ]);
                }

                $duplicatePartWarranty = Warranty::query()
                    ->where('service_order_spare_part_id', $sparePart->id)
                    ->where('status', '!=', 'void')
                    ->exists();

                if ($duplicatePartWarranty) {
                    throw ValidationException::withMessages([
                        'service_order_spare_part_id' => 'Spare-part ini sudah memiliki catatan garansi aktif.',
                    ]);
                }

                $itemLabel = $sparePart->item->name;
                if ($durationDays === null && $sparePart->item->warranty_days !== null && $sparePart->item->warranty_days > 0) {
                    $durationDays = (int) $sparePart->item->warranty_days;
                }
                if ($coverageDescription === null && $sparePart->item->warranty_terms !== null) {
                    $coverageDescription = $sparePart->item->warranty_terms;
                }
            } else {
                $duplicateServiceWarranty = Warranty::query()
                    ->where('service_order_id', $lockedOrder->id)
                    ->where('scope_type', 'service')
                    ->where('status', '!=', 'void')
                    ->exists();

                if ($duplicateServiceWarranty) {
                    throw ValidationException::withMessages([
                        'scope_type' => 'Garansi layanan untuk tiket ini sudah terdaftar.',
                    ]);
                }

                $itemLabel = $lockedOrder->service->name;
                if ($durationDays === null && $lockedOrder->service->warranty_days > 0) {
                    $durationDays = (int) $lockedOrder->service->warranty_days;
                }
            }

            if ($durationDays === null || $durationDays < 1) {
                throw ValidationException::withMessages([
                    'duration_days' => 'Durasi garansi wajib diisi (minimal 1 hari) karena tidak dikonfigurasi otomatis.',
                ]);
            }

            $assetId = ! empty($data['customer_asset_id'])
                ? (string) $data['customer_asset_id']
                : $lockedOrder->customer_asset_id;

            if ($assetId !== null) {
                $asset = CustomerAsset::query()->findOrFail($assetId);
                if ($lockedOrder->customer_id !== null && $asset->customer_id !== null && $lockedOrder->customer_id !== $asset->customer_id) {
                    throw ValidationException::withMessages([
                        'customer_asset_id' => 'Perangkat tidak dimiliki oleh pelanggan tiket ini.',
                    ]);
                }
            }

            $isPendingStart = (bool) ($data['is_pending'] ?? false);
            $startsAt = ! empty($data['starts_at'])
                ? CarbonImmutable::parse((string) $data['starts_at'])
                : ($isPendingStart ? null : CarbonImmutable::now());

            $expiresAt = $startsAt !== null ? $startsAt->addDays($durationDays) : null;
            $now = CarbonImmutable::now();

            if ($isPendingStart || $startsAt === null || $startsAt->greaterThan($now)) {
                $status = 'pending';
            } elseif ($expiresAt !== null && $expiresAt->lessThanOrEqualTo($now)) {
                $status = 'expired';
            } else {
                $status = 'active';
            }

            $warranty = Warranty::query()->create([
                'warranty_number' => $this->generateWarrantyNumber(),
                'customer_id' => $lockedOrder->customer_id,
                'customer_asset_id' => $assetId,
                'service_order_id' => $lockedOrder->id,
                'service_order_spare_part_id' => $sparePart?->id,
                'scope_type' => $scopeType,
                'item_label' => $itemLabel,
                'reference_number' => isset($data['reference_number']) && trim((string) $data['reference_number']) !== ''
                    ? trim((string) $data['reference_number'])
                    : null,
                'duration_days' => $durationDays,
                'coverage_description' => $coverageDescription ?: "Cakupan garansi {$scopeType} selama {$durationDays} hari.",
                'status' => $status,
                'starts_at' => $startsAt,
                'expires_at' => $expiresAt,
                'activated_by' => $status === 'pending' ? null : $actor->id,
                'activated_notified_at' => $status === 'active' ? $now : null,
            ]);

            if ($assetId !== null && $expiresAt !== null) {
                AssetServiceRecord::query()
                    ->where('customer_asset_id', $assetId)
                    ->where('service_order_id', $lockedOrder->id)
                    ->update(['warranty_until' => $expiresAt->toDateString()]);
            }

            DB::table('audit_logs')->insert([
                'actor_id' => $actor->id,
                'event' => 'warranty.registered',
                'auditable_type' => Warranty::class,
                'auditable_id' => (string) $warranty->id,
                'before' => null,
                'after' => json_encode([
                    'warranty_number' => $warranty->warranty_number,
                    'service_order_id' => $warranty->service_order_id,
                    'scope_type' => $warranty->scope_type,
                    'duration_days' => $warranty->duration_days,
                    'status' => $warranty->status,
                ]),
                'ip_address' => $ipAddress,
                'created_at' => $now,
            ]);

            return $warranty;
        });

        if ($warranty->status === 'active' && $warranty->customer !== null) {
            $expiryLabel = $warranty->expires_at?->translatedFormat('d M Y') ?? '-';
            $this->notifications->notifyUsers(
                [$warranty->customer],
                'warranty.activated',
                "Garansi {$warranty->warranty_number} aktif",
                "Garansi untuk {$warranty->item_label} aktif selama {$warranty->duration_days} hari hingga {$expiryLabel}.",
                route('orders.show', $warranty->service_order_id),
                [
                    'warranty_number' => $warranty->warranty_number,
                    'ticket_number' => $order->ticket_number,
                ],
            );
        }

        return $warranty;
    }

    /**
     * Void an existing warranty with an audited reason.
     */
    public function voidWarranty(Warranty $warranty, string $reason, User $actor, ?string $ipAddress = null): Warranty
    {
        $cleanReason = trim($reason);
        if ($cleanReason === '') {
            throw ValidationException::withMessages([
                'void_reason' => 'Alasan pembatalan garansi wajib diisi.',
            ]);
        }

        return DB::transaction(function () use ($warranty, $cleanReason, $actor, $ipAddress): Warranty {
            $locked = Warranty::query()->lockForUpdate()->findOrFail($warranty->id);

            if ($locked->resolvedStatus() === 'void') {
                throw ValidationException::withMessages([
                    'warranty' => 'Garansi sudah berstatus nonaktif (void).',
                ]);
            }

            $before = ['status' => $locked->status, 'voided_at' => $locked->voided_at?->toIso8601String()];
            $now = now();

            $locked->forceFill([
                'status' => 'void',
                'voided_at' => $now,
                'voided_by' => $actor->id,
                'void_reason' => $cleanReason,
            ])->save();

            DB::table('audit_logs')->insert([
                'actor_id' => $actor->id,
                'event' => 'warranty.voided',
                'auditable_type' => Warranty::class,
                'auditable_id' => (string) $locked->id,
                'before' => json_encode($before),
                'after' => json_encode([
                    'status' => 'void',
                    'void_reason' => $cleanReason,
                ]),
                'ip_address' => $ipAddress,
                'created_at' => $now,
            ]);

            return $locked;
        });
    }

    /**
     * Submit a warranty claim after validating server-side eligibility and active claim uniqueness.
     */
    public function submitClaim(
        Warranty $warranty,
        User $actor,
        string $issueDescription,
        ?string $ipAddress = null,
    ): WarrantyClaim {
        $cleanDescription = trim($issueDescription);
        if (mb_strlen($cleanDescription) < 10) {
            throw ValidationException::withMessages([
                'issue_description' => 'Jelaskan keluhan garansi minimal 10 karakter.',
            ]);
        }

        $claim = DB::transaction(function () use ($warranty, $actor, $cleanDescription, $ipAddress): WarrantyClaim {
            $locked = Warranty::query()
                ->with(['serviceOrder', 'customer', 'asset'])
                ->lockForUpdate()
                ->findOrFail($warranty->id);

            $resolved = $locked->resolvedStatus();
            if ($resolved !== 'active') {
                if ($resolved === 'expired' && $locked->status !== 'expired') {
                    $locked->forceFill(['status' => 'expired'])->save();
                }

                throw ValidationException::withMessages([
                    'warranty' => match ($resolved) {
                        'expired' => 'Masa garansi telah berakhir sehingga klaim tidak dapat diajukan.',
                        'void' => 'Garansi berstatus nonaktif (void) dan tidak berlaku untuk klaim.',
                        default => 'Garansi belum aktif dan belum dapat diklaim.',
                    },
                ]);
            }

            $hasOpenClaim = WarrantyClaim::query()
                ->where('warranty_id', $locked->id)
                ->whereIn('status', WarrantyClaim::ACTIVE_STATUSES)
                ->exists();

            if ($hasOpenClaim) {
                throw ValidationException::withMessages([
                    'warranty' => 'Masih terdapat pengajuan klaim garansi aktif yang sedang diproses untuk cakupan ini.',
                ]);
            }

            $now = now();
            $claim = WarrantyClaim::query()->create([
                'claim_number' => $this->generateClaimNumber(),
                'warranty_id' => $locked->id,
                'customer_id' => $locked->customer_id ?? $locked->serviceOrder->customer_id,
                'customer_asset_id' => $locked->customer_asset_id ?? $locked->serviceOrder->customer_asset_id,
                'original_service_order_id' => $locked->service_order_id,
                'submitted_by' => $actor->id,
                'status' => 'submitted',
                'issue_description' => $cleanDescription,
                'eligibility_checked' => false,
                'submitted_at' => $now,
            ]);

            DB::table('audit_logs')->insert([
                'actor_id' => $actor->id,
                'event' => 'warranty_claim.submitted',
                'auditable_type' => WarrantyClaim::class,
                'auditable_id' => (string) $claim->id,
                'before' => null,
                'after' => json_encode([
                    'claim_number' => $claim->claim_number,
                    'warranty_id' => $locked->id,
                    'warranty_number' => $locked->warranty_number,
                    'status' => 'submitted',
                ]),
                'ip_address' => $ipAddress,
                'created_at' => $now,
            ]);

            return $claim;
        });

        $staff = User::query()
            ->whereIn('role', ['operator', 'admin', 'supervisor'])
            ->where('is_active', true)
            ->get();

        $this->notifications->notifyUsers(
            $staff,
            'warranty.claim_submitted',
            "Klaim garansi {$claim->claim_number} diajukan",
            "Klaim untuk garansi {$warranty->warranty_number} ({$warranty->item_label}) menunggu tinjauan tim.",
            route('warranties.index'),
            ['claim_number' => $claim->claim_number, 'warranty_number' => $warranty->warranty_number],
        );

        return $claim;
    }

    /**
     * Review, verify eligibility, approve or reject a warranty claim, and optionally create a follow-up ticket.
     *
     * @param  array<string, mixed>  $data
     */
    public function reviewClaim(
        WarrantyClaim $claim,
        User $actor,
        array $data,
        ?string $ipAddress = null,
    ): WarrantyClaim {
        $nextStatus = (string) $data['status'];
        if (! in_array($nextStatus, ['under_review', 'approved', 'rejected'], true)) {
            throw ValidationException::withMessages([
                'status' => 'Keputusan tinjauan klaim tidak valid.',
            ]);
        }

        $createOrder = (bool) ($data['create_service_order'] ?? false);

        $updatedClaim = DB::transaction(function () use ($claim, $actor, $data, $nextStatus, $createOrder, $ipAddress): WarrantyClaim {
            $lockedClaim = WarrantyClaim::query()
                ->with(['warranty', 'originalOrder', 'customer'])
                ->lockForUpdate()
                ->findOrFail($claim->id);

            if (! in_array($lockedClaim->status, ['submitted', 'under_review'], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Klaim garansi sudah diproses sebelumnya.',
                ]);
            }

            if ($nextStatus === 'rejected' && trim((string) ($data['review_notes'] ?? '')) === '') {
                throw ValidationException::withMessages([
                    'review_notes' => 'Alasan penolakan klaim wajib diisi.',
                ]);
            }

            $before = ['status' => $lockedClaim->status];
            $now = now();
            $claimOrderId = $lockedClaim->claim_service_order_id;

            if ($nextStatus === 'approved' && $createOrder && $claimOrderId === null) {
                $claimOrder = $this->createFollowUpWarrantyOrder($lockedClaim, $actor);
                $claimOrderId = $claimOrder->id;
            }

            $lockedClaim->forceFill([
                'status' => $nextStatus,
                'eligibility_checked' => array_key_exists('eligibility_checked', $data)
                    ? (bool) $data['eligibility_checked']
                    : true,
                'eligibility_notes' => isset($data['eligibility_notes']) && trim((string) $data['eligibility_notes']) !== ''
                    ? trim((string) $data['eligibility_notes'])
                    : $lockedClaim->eligibility_notes,
                'review_notes' => isset($data['review_notes']) && trim((string) $data['review_notes']) !== ''
                    ? trim((string) $data['review_notes'])
                    : $lockedClaim->review_notes,
                'claim_service_order_id' => $claimOrderId,
                'reviewed_by' => $actor->id,
                'reviewed_at' => $now,
            ])->save();

            DB::table('audit_logs')->insert([
                'actor_id' => $actor->id,
                'event' => 'warranty_claim.reviewed',
                'auditable_type' => WarrantyClaim::class,
                'auditable_id' => (string) $lockedClaim->id,
                'before' => json_encode($before),
                'after' => json_encode([
                    'status' => $lockedClaim->status,
                    'eligibility_checked' => $lockedClaim->eligibility_checked,
                    'claim_service_order_id' => $lockedClaim->claim_service_order_id,
                ]),
                'ip_address' => $ipAddress,
                'created_at' => $now,
            ]);

            return $lockedClaim;
        });

        if (in_array($nextStatus, ['approved', 'rejected'], true) && $updatedClaim->customer !== null) {
            $decisionLabel = $nextStatus === 'approved' ? 'disetujui' : 'ditolak';
            $this->notifications->notifyUsers(
                [$updatedClaim->customer],
                'warranty.claim_reviewed',
                "Klaim {$updatedClaim->claim_number} {$decisionLabel}",
                $updatedClaim->review_notes
                    ?: ($nextStatus === 'approved'
                        ? 'Klaim garansi Anda memenuhi syarat dan disetujui oleh tim AD Com.'
                        : 'Pengajuan klaim garansi Anda tidak disetujui.'),
                $updatedClaim->claim_service_order_id
                    ? route('orders.show', $updatedClaim->claim_service_order_id)
                    : route('orders.show', $updatedClaim->original_service_order_id),
                ['claim_number' => $updatedClaim->claim_number, 'status' => $nextStatus],
            );
        }

        return $updatedClaim;
    }

    /**
     * Mark an approved warranty claim as resolved with resolution notes.
     */
    public function resolveClaim(
        WarrantyClaim $claim,
        User $actor,
        string $resolutionNotes,
        ?string $ipAddress = null,
    ): WarrantyClaim {
        $cleanNotes = trim($resolutionNotes);
        if ($cleanNotes === '') {
            throw ValidationException::withMessages([
                'resolution_notes' => 'Catatan penyelesaian klaim wajib diisi.',
            ]);
        }

        $resolved = DB::transaction(function () use ($claim, $actor, $cleanNotes, $ipAddress): WarrantyClaim {
            $locked = WarrantyClaim::query()->with('customer')->lockForUpdate()->findOrFail($claim->id);

            if ($locked->status !== 'approved') {
                throw ValidationException::withMessages([
                    'status' => 'Hanya klaim berstatus disetujui yang dapat diselesaikan.',
                ]);
            }

            $now = now();
            $locked->forceFill([
                'status' => 'resolved',
                'resolution_notes' => $cleanNotes,
                'resolved_by' => $actor->id,
                'resolved_at' => $now,
            ])->save();

            DB::table('audit_logs')->insert([
                'actor_id' => $actor->id,
                'event' => 'warranty_claim.resolved',
                'auditable_type' => WarrantyClaim::class,
                'auditable_id' => (string) $locked->id,
                'before' => json_encode(['status' => 'approved']),
                'after' => json_encode(['status' => 'resolved', 'resolution_notes' => $cleanNotes]),
                'ip_address' => $ipAddress,
                'created_at' => $now,
            ]);

            return $locked;
        });

        if ($resolved->customer !== null) {
            $this->notifications->notifyUsers(
                [$resolved->customer],
                'warranty.claim_resolved',
                "Klaim {$resolved->claim_number} selesai",
                $cleanNotes,
                route('orders.show', $resolved->claim_service_order_id ?? $resolved->original_service_order_id),
                ['claim_number' => $resolved->claim_number],
            );
        }

        return $resolved;
    }

    /**
     * Evaluate active warranties for approaching expiration or expiry and dispatch deduplicated alerts.
     *
     * @return array{scanned: int, expired_marked: int, expiring_notified: int, expired_notified: int}
     */
    public function monitorWarranties(?CarbonInterface $now = null, int $expiringWindowDays = 7): array
    {
        $reference = CarbonImmutable::instance($now ?? now());
        $windowEnd = $reference->addDays($expiringWindowDays);

        $candidates = Warranty::query()
            ->with(['customer', 'serviceOrder'])
            ->where('status', 'active')
            ->get();

        $staff = User::query()
            ->whereIn('role', ['operator', 'admin', 'supervisor'])
            ->where('is_active', true)
            ->get();

        $expiredMarked = 0;
        $expiringNotified = 0;
        $expiredNotified = 0;

        foreach ($candidates as $warranty) {
            if ($warranty->expires_at !== null && $warranty->expires_at->lessThanOrEqualTo($reference)) {
                $shouldNotify = DB::transaction(function () use ($warranty, $reference, &$expiredMarked): bool {
                    $locked = Warranty::query()->lockForUpdate()->findOrFail($warranty->id);
                    if ($locked->status === 'void') {
                        return false;
                    }

                    $updates = ['status' => 'expired'];
                    $notify = $locked->expired_notified_at === null;
                    if ($notify) {
                        $updates['expired_notified_at'] = $reference;
                    }
                    $locked->forceFill($updates)->save();
                    $expiredMarked++;

                    return $notify;
                });

                if ($shouldNotify) {
                    $recipients = $staff->when($warranty->customer !== null, fn ($c) => $c->push($warranty->customer));
                    $this->notifications->notifyUsers(
                        $recipients,
                        'warranty.expired',
                        "Garansi {$warranty->warranty_number} berakhir",
                        "Masa garansi untuk {$warranty->item_label} pada tiket {$warranty->serviceOrder->ticket_number} telah berakhir.",
                        route('orders.show', $warranty->service_order_id),
                        ['warranty_number' => $warranty->warranty_number],
                    );
                    $expiredNotified++;
                }

                continue;
            }

            if (
                $warranty->expires_at !== null
                && $warranty->expires_at->greaterThan($reference)
                && $warranty->expires_at->lessThanOrEqualTo($windowEnd)
                && $warranty->expiring_notified_at === null
            ) {
                $claimed = DB::transaction(function () use ($warranty, $reference): bool {
                    $locked = Warranty::query()->lockForUpdate()->findOrFail($warranty->id);
                    if ($locked->status !== 'active' || $locked->expiring_notified_at !== null) {
                        return false;
                    }

                    $locked->forceFill(['expiring_notified_at' => $reference])->save();

                    return true;
                });

                if ($claimed) {
                    $recipients = $staff->when($warranty->customer !== null, fn ($c) => $c->push($warranty->customer));
                    $expiresLabel = $warranty->expires_at->translatedFormat('d M Y');
                    $this->notifications->notifyUsers(
                        $recipients,
                        'warranty.approaching_expiration',
                        "Garansi {$warranty->warranty_number} segera berakhir",
                        "Garansi untuk {$warranty->item_label} berakhir pada {$expiresLabel}.",
                        route('orders.show', $warranty->service_order_id),
                        ['warranty_number' => $warranty->warranty_number],
                    );
                    $expiringNotified++;
                }
            }
        }

        return [
            'scanned' => $candidates->count(),
            'expired_marked' => $expiredMarked,
            'expiring_notified' => $expiringNotified,
            'expired_notified' => $expiredNotified,
        ];
    }

    private function createFollowUpWarrantyOrder(WarrantyClaim $claim, User $actor): ServiceOrder
    {
        $origin = $claim->originalOrder;

        do {
            $ticket = 'ADC-'.now()->format('ym').'-'.random_int(1000, 9999);
        } while (ServiceOrder::query()->where('ticket_number', $ticket)->exists());

        $followUp = ServiceOrder::query()->create([
            'ticket_number' => $ticket,
            'tracking_token_hash' => hash('sha256', Str::random(64)),
            'access_code_hash' => hash('sha256', Str::upper(Str::random(8))),
            'customer_id' => $origin->customer_id,
            'customer_asset_id' => $claim->customer_asset_id ?? $origin->customer_asset_id,
            'service_id' => $origin->service_id,
            'customer_name' => $origin->customer_name,
            'phone' => $origin->phone,
            'email' => $origin->email,
            'address' => $origin->address,
            'district' => $origin->district,
            'service_mode' => $origin->getAttribute('service_mode') ?: 'dropoff',
            'complaint' => "[Klaim Garansi {$claim->claim_number} / Tiket {$origin->ticket_number}] {$claim->issue_description}",
            'urgency' => 'soon',
            'risk_level' => 'unassessed',
            'status' => 'new',
        ]);

        $followUp->statusEvents()->create([
            'actor_id' => $actor->id,
            'status' => 'new',
            'title' => 'Tiket tindak lanjut klaim garansi dibuat',
            'description' => "Dibuat otomatis dari klaim {$claim->claim_number} (garansi {$claim->warranty->warranty_number}).",
            'visibility' => 'customer',
            'occurred_at' => now(),
        ]);

        if ($followUp->customer_asset_id !== null) {
            $asset = CustomerAsset::query()->find($followUp->customer_asset_id);
            if ($asset !== null) {
                AssetServiceRecord::query()->updateOrCreate(
                    [
                        'customer_asset_id' => $asset->id,
                        'service_order_id' => $followUp->id,
                    ],
                    [
                        'device_snapshot' => [
                            'device_code' => $asset->device_code,
                            'name' => $asset->name,
                            'category' => $asset->category,
                            'brand' => $asset->brand,
                            'model' => $asset->model,
                            'serial_masked' => $asset->serial_masked,
                            'location' => $asset->location,
                        ],
                        'diagnosis' => "Tindak lanjut klaim garansi {$claim->claim_number}.",
                    ],
                );
            }
        }

        return $followUp;
    }

    private function generateWarrantyNumber(): string
    {
        do {
            $code = 'WRN-'.now()->format('ym').'-'.random_int(1000, 9999);
        } while (Warranty::query()->where('warranty_number', $code)->exists());

        return $code;
    }

    private function generateClaimNumber(): string
    {
        do {
            $code = 'CLM-'.now()->format('ym').'-'.random_int(1000, 9999);
        } while (WarrantyClaim::query()->where('claim_number', $code)->exists());

        return $code;
    }
}
