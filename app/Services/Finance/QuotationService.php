<?php

namespace App\Services\Finance;

use App\Models\Estimate;
use App\Models\InventoryItem;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderSparePart;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuotationService
{
    public function __construct(
        private readonly NotificationDispatcher $notifications,
    ) {}

    /**
     * Read default quotation tax & validity configuration from system settings.
     *
     * @return array{default_tax_rate: string, quotation_validity_days: int}
     */
    public function defaults(): array
    {
        $settings = SystemSetting::getMany([
            'finance.default_tax_rate' => '0.00',
            'finance.quotation_validity_days' => '14',
        ]);

        return [
            'default_tax_rate' => FinanceMath::normalizeDecimalString($settings['finance.default_tax_rate'] !== '' ? $settings['finance.default_tax_rate'] : '0', 2),
            'quotation_validity_days' => max(1, (int) ($settings['finance.quotation_validity_days'] ?: 14)),
        ];
    }

    /**
     * Compute quotation totals using the authoritative document calculation logic.
     *
     * @param  array<string, mixed>  $payload
     * @return array{
     *     labor_total: int,
     *     spare_parts_total: int,
     *     operational_total: int,
     *     subtotal: int,
     *     discount_amount: int,
     *     tax_rate: string,
     *     tax_amount: int,
     *     total: int,
     *     lines: list<array<string, mixed>>
     * }
     */
    public function previewTotals(array $payload): array
    {
        $defaults = $this->defaults();
        $taxRate = $payload['tax_rate'] ?? $defaults['default_tax_rate'];
        $discountAmount = max(0, (int) ($payload['discount_amount'] ?? 0));
        /** @var list<array{item_type?: string, label?: string, quantity: int|string, unit_price: int, estimated_unit_cost?: int, inventory_item_id?: int|null, service_order_spare_part_id?: int|null}> $rawItems */
        $rawItems = is_array($payload['items'] ?? null) ? array_values($payload['items']) : [];
        $preparedLines = $this->prepareRawLines($rawItems);

        /** @var array{labor_total: int, spare_parts_total: int, operational_total: int, subtotal: int, discount_amount: int, tax_rate: string, tax_amount: int, total: int, lines: list<array<string, mixed>>} */
        return FinanceMath::calculateDocumentTotals($preparedLines, $discountAmount, $taxRate);
    }

    /**
     * Create a new quotation version associated with a service order.
     *
     * @param  array<string, mixed>  $payload
     */
    public function createQuotation(User $actor, ServiceOrder $order, array $payload): Estimate
    {
        $defaults = $this->defaults();

        /** @var Estimate $estimate */
        $estimate = DB::transaction(function () use ($actor, $order, $payload, $defaults): Estimate {
            $lockedOrder = ServiceOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            $nextVersion = ((int) Estimate::query()
                ->where('service_order_id', $lockedOrder->id)
                ->max('version')) + 1;

            $validUntil = ! empty($payload['valid_until'])
                ? Carbon::parse((string) $payload['valid_until'])->startOfDay()
                : now()->addDays($defaults['quotation_validity_days'])->startOfDay();

            $totals = $this->previewTotals($payload);

            $sendNow = (bool) ($payload['send_now'] ?? false);
            if ($sendNow && $validUntil->lt(now()->startOfDay())) {
                throw ValidationException::withMessages([
                    'valid_until' => 'Tanggal kedaluwarsa penawaran tidak boleh berada di masa lalu saat dikirim.',
                ]);
            }

            $quotationNumber = $this->generateQuotationNumber();

            $estimate = Estimate::query()->create([
                'quotation_number' => $quotationNumber,
                'service_order_id' => $lockedOrder->id,
                'customer_id' => $lockedOrder->customer_id,
                'created_by' => $actor->id,
                'version' => $nextVersion,
                'status' => $sendNow ? 'sent' : 'draft',
                'labor_total' => $totals['labor_total'],
                'spare_parts_total' => $totals['spare_parts_total'],
                'operational_total' => $totals['operational_total'],
                'subtotal' => $totals['subtotal'],
                'discount_amount' => $totals['discount_amount'],
                'tax_rate' => $totals['tax_rate'],
                'tax_amount' => $totals['tax_amount'],
                'total' => $totals['total'],
                'notes' => isset($payload['notes']) && trim((string) $payload['notes']) !== '' ? trim((string) $payload['notes']) : null,
                'valid_until' => $validUntil->toDateString(),
                'sent_at' => $sendNow ? now() : null,
            ]);

            $this->persistEstimateItems($estimate, $totals['lines']);

            $this->writeAuditLog($actor, 'finance.quotation_created', $estimate, [
                'quotation_number' => $estimate->quotation_number,
                'service_order_id' => $lockedOrder->id,
                'version' => $estimate->version,
                'status' => $estimate->status,
                'total' => $estimate->total,
            ]);

            if ($sendNow) {
                $this->writeAuditLog($actor, 'finance.quotation_sent', $estimate, [
                    'quotation_number' => $estimate->quotation_number,
                    'service_order_id' => $lockedOrder->id,
                ]);
            }

            return $estimate->fresh(['items', 'order', 'customer']) ?? $estimate;
        });

        if ($estimate->status === 'sent') {
            $this->notifyCustomerQuotationSent($estimate);
        }

        return $estimate;
    }

    /**
     * Update an existing draft quotation before sending it to the customer.
     *
     * @param  array<string, mixed>  $payload
     */
    public function updateDraftQuotation(User $actor, Estimate $estimate, array $payload): Estimate
    {
        return DB::transaction(function () use ($actor, $estimate, $payload): Estimate {
            $locked = Estimate::query()->whereKey($estimate->id)->lockForUpdate()->firstOrFail();

            if ($locked->resolvedStatus() !== 'draft') {
                throw ValidationException::withMessages([
                    'status' => 'Hanya penawaran berstatus draf yang dapat diubah. Buat versi penawaran baru jika sudah dikirim atau diputuskan.',
                ]);
            }

            $defaults = $this->defaults();
            $taxRate = $payload['tax_rate'] ?? $locked->tax_rate ?? $defaults['default_tax_rate'];
            $discountAmount = max(0, (int) ($payload['discount_amount'] ?? $locked->discount_amount));
            $validUntil = ! empty($payload['valid_until'])
                ? Carbon::parse((string) $payload['valid_until'])->toDateString()
                : $locked->valid_until?->toDateString();

            $preparedLines = $this->prepareRawLines($payload['items']);
            $totals = FinanceMath::calculateDocumentTotals($preparedLines, $discountAmount, $taxRate);

            $locked->update([
                'labor_total' => $totals['labor_total'],
                'spare_parts_total' => $totals['spare_parts_total'],
                'operational_total' => $totals['operational_total'],
                'subtotal' => $totals['subtotal'],
                'discount_amount' => $totals['discount_amount'],
                'tax_rate' => $totals['tax_rate'],
                'tax_amount' => $totals['tax_amount'],
                'total' => $totals['total'],
                'notes' => array_key_exists('notes', $payload)
                    ? (trim((string) $payload['notes']) !== '' ? trim((string) $payload['notes']) : null)
                    : $locked->notes,
                'valid_until' => $validUntil,
            ]);

            $locked->items()->delete();
            $this->persistEstimateItems($locked, $totals['lines']);

            $this->writeAuditLog($actor, 'finance.quotation_updated', $locked, [
                'quotation_number' => $locked->quotation_number,
                'total' => $locked->total,
            ]);

            return $locked->fresh(['items', 'order', 'customer']) ?? $locked;
        });
    }

    /**
     * Send a draft quotation to the customer for approval.
     */
    public function sendQuotation(User $actor, Estimate $estimate): Estimate
    {
        /** @var Estimate $sent */
        $sent = DB::transaction(function () use ($actor, $estimate): Estimate {
            $locked = Estimate::query()->whereKey($estimate->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages([
                    'status' => 'Hanya penawaran berstatus draf yang dapat dikirim ke pelanggan.',
                ]);
            }

            if ($locked->items()->count() === 0) {
                throw ValidationException::withMessages([
                    'items' => 'Penawaran harus memiliki minimal satu item biaya sebelum dikirim.',
                ]);
            }

            if ($locked->valid_until !== null && $locked->valid_until->lt(now()->startOfDay())) {
                throw ValidationException::withMessages([
                    'valid_until' => 'Tanggal berlaku penawaran sudah kedaluwarsa. Perbarui tanggal terlebih dahulu.',
                ]);
            }

            $locked->update([
                'status' => 'sent',
                'sent_at' => now(),
            ]);

            $this->writeAuditLog($actor, 'finance.quotation_sent', $locked, [
                'quotation_number' => $locked->quotation_number,
                'total' => $locked->total,
            ]);

            return $locked->fresh(['items', 'order', 'customer']) ?? $locked;
        });

        $this->notifyCustomerQuotationSent($sent);

        return $sent;
    }

    /**
     * Approve a pending quotation and freeze an immutable snapshot of its approved values.
     */
    public function approveQuotation(User $actor, Estimate $estimate, ?string $notes = null): Estimate
    {
        $wasExpired = DB::transaction(function () use ($estimate): bool {
            $locked = Estimate::query()->whereKey($estimate->id)->lockForUpdate()->firstOrFail();

            return $this->syncExpirationOnModel($locked);
        });

        if ($wasExpired) {
            throw ValidationException::withMessages([
                'status' => 'Penawaran sudah melewati masa berlaku (kedaluwarsa) dan tidak dapat disetujui.',
            ]);
        }

        /** @var Estimate $approved */
        $approved = DB::transaction(function () use ($actor, $estimate, $notes): Estimate {
            $locked = Estimate::query()->with(['items', 'order'])->whereKey($estimate->id)->lockForUpdate()->firstOrFail();

            if ($this->syncExpirationOnModel($locked) || $locked->status !== 'sent') {
                throw ValidationException::withMessages([
                    'status' => 'Hanya penawaran yang sedang menunggu persetujuan yang dapat disetujui.',
                ]);
            }

            $approvedAt = now();
            $snapshot = $this->buildApprovedSnapshot($locked, $actor, $approvedAt, $notes);

            $locked->update([
                'status' => 'approved',
                'approved_at' => $approvedAt,
                'approved_by' => $actor->id,
                'approved_snapshot' => $snapshot,
            ]);

            $locked->order()->update([
                'estimated_total' => $locked->total,
            ]);

            $this->writeAuditLog($actor, 'finance.quotation_approved', $locked, [
                'quotation_number' => $locked->quotation_number,
                'total' => $locked->total,
                'service_order_id' => $locked->service_order_id,
            ]);

            return $locked->fresh(['items', 'order', 'customer', 'approver']) ?? $locked;
        });

        $this->notifyStaffQuotationDecision($approved, true);

        return $approved;
    }

    /**
     * Reject a pending quotation with an explicit customer or authorized staff reason.
     */
    public function rejectQuotation(User $actor, Estimate $estimate, string $reason): Estimate
    {
        $cleanReason = trim($reason);
        if ($cleanReason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Alasan penolakan penawaran wajib diisi.',
            ]);
        }

        $wasExpired = DB::transaction(function () use ($estimate): bool {
            $locked = Estimate::query()->whereKey($estimate->id)->lockForUpdate()->firstOrFail();

            return $this->syncExpirationOnModel($locked);
        });

        if ($wasExpired) {
            throw ValidationException::withMessages([
                'status' => 'Penawaran sudah melewati masa berlaku (kedaluwarsa).',
            ]);
        }

        /** @var Estimate $rejected */
        $rejected = DB::transaction(function () use ($actor, $estimate, $cleanReason): Estimate {
            $locked = Estimate::query()->with(['order'])->whereKey($estimate->id)->lockForUpdate()->firstOrFail();

            if ($this->syncExpirationOnModel($locked) || $locked->status !== 'sent') {
                throw ValidationException::withMessages([
                    'status' => 'Hanya penawaran yang sedang menunggu persetujuan yang dapat ditolak.',
                ]);
            }

            $locked->update([
                'status' => 'rejected',
                'rejected_at' => now(),
                'rejected_by' => $actor->id,
                'rejection_reason' => $cleanReason,
            ]);

            $this->writeAuditLog($actor, 'finance.quotation_rejected', $locked, [
                'quotation_number' => $locked->quotation_number,
                'reason' => $cleanReason,
            ]);

            return $locked->fresh(['items', 'order', 'customer', 'rejecter']) ?? $locked;
        });

        $this->notifyStaffQuotationDecision($rejected, false);

        return $rejected;
    }

    /**
     * Sync overdue sent quotations to persisted expired status cleanly.
     */
    public function expireOverdueQuotations(?CarbonInterface $at = null): int
    {
        $today = ($at !== null ? Carbon::instance($at) : now())->toDateString();

        $overdue = Estimate::query()
            ->where('status', 'sent')
            ->whereNotNull('valid_until')
            ->whereDate('valid_until', '<', $today)
            ->get();

        $count = 0;
        foreach ($overdue as $estimate) {
            DB::transaction(function () use ($estimate, &$count): void {
                $locked = Estimate::query()->whereKey($estimate->id)->lockForUpdate()->first();
                if ($locked === null || $locked->status !== 'sent') {
                    return;
                }

                if ($this->syncExpirationOnModel($locked)) {
                    $count++;
                }
            });
        }

        return $count;
    }

    /**
     * Strip internal cost/margin attributes when returning an approved snapshot to customers.
     *
     * @param  array<string, mixed>|null  $snapshot
     * @return array<string, mixed>|null
     */
    public function sanitizeSnapshotForRole(?array $snapshot, string $role): ?array
    {
        if ($snapshot === null) {
            return null;
        }

        if (! in_array($role, ['customer', 'warehouse'], true)) {
            return $snapshot;
        }

        $items = is_array($snapshot['items'] ?? null) ? $snapshot['items'] : [];
        $sanitizedItems = array_map(static function (mixed $item): array {
            if (! is_array($item)) {
                return [];
            }

            unset($item['estimated_unit_cost']);

            return $item;
        }, $items);

        $snapshot['items'] = $sanitizedItems;

        return $snapshot;
    }

    private function syncExpirationOnModel(Estimate $estimate): bool
    {
        if ($estimate->status === 'sent' && $estimate->resolvedStatus() === 'expired') {
            $estimate->update(['status' => 'expired']);

            $this->writeAuditLog(null, 'finance.quotation_expired', $estimate, [
                'quotation_number' => $estimate->quotation_number,
                'valid_until' => $estimate->valid_until?->toDateString(),
            ]);

            return true;
        }

        return $estimate->status === 'expired';
    }

    /**
     * @param  list<array{
     *     item_type?: string,
     *     label?: string,
     *     quantity: string|int,
     *     unit_price: int,
     *     estimated_unit_cost?: int,
     *     inventory_item_id?: int|null,
     *     service_order_spare_part_id?: int|null
     * }>  $rawItems
     * @return list<array<string, mixed>>
     */
    private function prepareRawLines(array $rawItems): array
    {
        $prepared = [];

        foreach ($rawItems as $index => $item) {
            $inventoryItemId = isset($item['inventory_item_id']) && $item['inventory_item_id'] ? (int) $item['inventory_item_id'] : null;
            $sparePartId = isset($item['service_order_spare_part_id']) && $item['service_order_spare_part_id'] ? (int) $item['service_order_spare_part_id'] : null;

            $sparePart = $sparePartId !== null ? ServiceOrderSparePart::query()->with('item')->find($sparePartId) : null;
            if ($sparePart !== null && $inventoryItemId === null) {
                $inventoryItemId = $sparePart->inventory_item_id;
            }

            $inventoryItem = $inventoryItemId !== null ? InventoryItem::query()->find($inventoryItemId) : null;

            $type = (string) ($item['item_type'] ?? ($inventoryItemId !== null || $sparePartId !== null ? 'spare_part' : 'labor'));
            $label = trim((string) ($item['label'] ?? ''));
            if ($label === '' && $inventoryItem !== null) {
                $label = $inventoryItem->name;
            }

            $estimatedUnitCost = isset($item['estimated_unit_cost']) ? max(0, (int) $item['estimated_unit_cost']) : 0;
            if ($estimatedUnitCost === 0 && $sparePart !== null) {
                $estimatedUnitCost = $sparePart->unit_cost;
            } elseif ($estimatedUnitCost === 0 && $inventoryItem !== null) {
                $estimatedUnitCost = $inventoryItem->unit_cost;
            }

            $prepared[] = [
                'item_type' => $type,
                'label' => $label,
                'quantity' => $item['quantity'],
                'unit_price' => (int) $item['unit_price'],
                'unit_cost' => $estimatedUnitCost,
                'estimated_unit_cost' => $estimatedUnitCost,
                'inventory_item_id' => $inventoryItemId,
                'service_order_spare_part_id' => $sparePartId,
                'sort_order' => $index,
            ];
        }

        return $prepared;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function persistEstimateItems(Estimate $estimate, array $lines): void
    {
        foreach ($lines as $line) {
            $estimate->items()->create([
                'item_type' => $line['item_type'],
                'inventory_item_id' => $line['inventory_item_id'] ?? null,
                'service_order_spare_part_id' => $line['service_order_spare_part_id'] ?? null,
                'label' => $line['label'],
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'estimated_unit_cost' => $line['estimated_unit_cost'] ?? 0,
                'total' => $line['line_total'],
                'sort_order' => $line['sort_order'],
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildApprovedSnapshot(Estimate $estimate, User $actor, CarbonInterface $approvedAt, ?string $notes): array
    {
        return [
            'quotation_number' => $estimate->quotation_number,
            'service_order_id' => $estimate->service_order_id,
            'ticket_number' => $estimate->order->ticket_number,
            'version' => $estimate->version,
            'customer_id' => $estimate->customer_id,
            'labor_total' => $estimate->labor_total,
            'spare_parts_total' => $estimate->spare_parts_total,
            'operational_total' => $estimate->operational_total,
            'subtotal' => $estimate->subtotal,
            'discount_amount' => $estimate->discount_amount,
            'tax_rate' => (string) $estimate->tax_rate,
            'tax_amount' => $estimate->tax_amount,
            'total' => $estimate->total,
            'valid_until' => $estimate->valid_until?->toDateString(),
            'notes' => $estimate->notes,
            'approval_notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : null,
            'approved_at' => $approvedAt->toIso8601String(),
            'approved_by' => $actor->id,
            'approved_by_name' => $actor->name,
            'items' => $estimate->items->map(static fn ($item): array => [
                'id' => $item->id,
                'item_type' => $item->item_type,
                'label' => $item->label,
                'quantity' => (string) $item->quantity,
                'unit_price' => $item->unit_price,
                'estimated_unit_cost' => $item->estimated_unit_cost,
                'total' => $item->total,
                'inventory_item_id' => $item->inventory_item_id,
                'service_order_spare_part_id' => $item->service_order_spare_part_id,
                'sort_order' => $item->sort_order,
            ])->values()->all(),
        ];
    }

    private function generateQuotationNumber(): string
    {
        $prefix = 'QUO-'.now()->format('ym').'-';
        $latest = Estimate::query()
            ->where('quotation_number', 'like', $prefix.'%')
            ->orderByDesc('quotation_number')
            ->value('quotation_number');

        $seq = 1;
        if (is_string($latest) && preg_match('/^QUO-\d{4}-(\d+)/', $latest, $matches) === 1) {
            $seq = ((int) $matches[1]) + 1;
        }

        do {
            $candidate = $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
            $exists = Estimate::query()->where('quotation_number', $candidate)->exists();
            $seq++;
        } while ($exists);

        return $candidate;
    }

    private function notifyCustomerQuotationSent(Estimate $estimate): void
    {
        if ($estimate->customer === null) {
            return;
        }

        $totalFormatted = 'Rp '.number_format($estimate->total, 0, ',', '.');
        $this->notifications->notifyUsers(
            [$estimate->customer],
            'finance.quotation_sent',
            "Penawaran {$estimate->quotation_number} siap ditinjau",
            "Total estimasi {$totalFormatted} untuk tiket {$estimate->order->ticket_number}. Silakan setujui atau tolak penawaran sebelum masa berlaku habis.",
            "/finance/quotations/{$estimate->id}",
            [
                'estimate_id' => $estimate->id,
                'quotation_number' => $estimate->quotation_number,
                'service_order_id' => $estimate->service_order_id,
                'total' => $estimate->total,
            ],
            dedupeKey: "finance-quo-sent-{$estimate->id}",
        );
    }

    private function notifyStaffQuotationDecision(Estimate $estimate, bool $approved): void
    {
        $recipients = User::query()
            ->whereIn('role', ['operator', 'admin'])
            ->where('is_active', true)
            ->get();

        $statusLabel = $approved ? 'disetujui' : 'ditolak';
        $totalFormatted = 'Rp '.number_format($estimate->total, 0, ',', '.');

        $this->notifications->notifyUsers(
            $recipients,
            'finance.quotation_decided',
            "Penawaran {$estimate->quotation_number} {$statusLabel}",
            "Penawaran ({$totalFormatted}) untuk tiket {$estimate->order->ticket_number} telah {$statusLabel}.",
            "/finance/quotations/{$estimate->id}",
            [
                'estimate_id' => $estimate->id,
                'quotation_number' => $estimate->quotation_number,
                'service_order_id' => $estimate->service_order_id,
                'status' => $estimate->status,
            ],
            dedupeKey: "finance-quo-decided-{$estimate->id}-{$estimate->status}",
        );
    }

    /**
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>|null  $before
     */
    private function writeAuditLog(?User $actor, string $event, Estimate $estimate, array $after, ?array $before = null): void
    {
        DB::table('audit_logs')->insert([
            'actor_id' => $actor?->id,
            'event' => $event,
            'auditable_type' => Estimate::class,
            'auditable_id' => $estimate->id,
            'before' => $before !== null ? json_encode($before) : null,
            'after' => json_encode($after),
            'created_at' => now(),
        ]);
    }
}
