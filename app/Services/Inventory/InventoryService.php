<?php

namespace App\Services\Inventory;

use App\Models\FinancialTransaction;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderSparePart;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Sla\SlaService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    public const VALUATION_METHOD = 'weighted_average_cost';

    public const VALUATION_METHOD_LABEL = 'Weighted Average Cost (Rata-Rata Tertimbang)';

    public function __construct(
        private readonly NotificationDispatcher $notifications,
    ) {}

    /**
     * Create a new inventory master item and record initial stock movement if opening stock > 0.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createItem(array $attributes, User $actor, ?string $ipAddress = null): InventoryItem
    {
        $sku = mb_strtoupper(trim((string) $attributes['sku']));

        $item = DB::transaction(function () use ($attributes, $sku, $actor, $ipAddress): InventoryItem {
            $item = InventoryItem::query()->create([
                'sku' => $sku,
                'name' => trim((string) $attributes['name']),
                'category' => trim((string) $attributes['category']),
                'description' => isset($attributes['description']) && trim((string) $attributes['description']) !== ''
                    ? trim((string) $attributes['description'])
                    : null,
                'unit' => trim((string) $attributes['unit']),
                'quantity_on_hand' => (int) $attributes['quantity_on_hand'],
                'reorder_level' => (int) $attributes['reorder_level'],
                'warranty_days' => isset($attributes['warranty_days']) && $attributes['warranty_days'] !== ''
                    ? (int) $attributes['warranty_days']
                    : null,
                'warranty_terms' => isset($attributes['warranty_terms']) && trim((string) $attributes['warranty_terms']) !== ''
                    ? trim((string) $attributes['warranty_terms'])
                    : null,
                'unit_cost' => (int) $attributes['unit_cost'],
                'selling_price' => isset($attributes['selling_price']) && $attributes['selling_price'] !== ''
                    ? (int) $attributes['selling_price']
                    : null,
                'storage_location' => isset($attributes['storage_location']) && trim((string) $attributes['storage_location']) !== ''
                    ? trim((string) $attributes['storage_location'])
                    : null,
                'supplier_name' => isset($attributes['supplier_name']) && trim((string) $attributes['supplier_name']) !== ''
                    ? trim((string) $attributes['supplier_name'])
                    : null,
                'supplier_contact' => isset($attributes['supplier_contact']) && trim((string) $attributes['supplier_contact']) !== ''
                    ? trim((string) $attributes['supplier_contact'])
                    : null,
                'is_active' => (bool) ($attributes['is_active'] ?? true),
            ]);

            if ($item->quantity_on_hand > 0) {
                $item->movements()->create([
                    'actor_id' => $actor->id,
                    'type' => 'stock_in',
                    'quantity' => $item->quantity_on_hand,
                    'balance_before' => 0,
                    'balance_after' => $item->quantity_on_hand,
                    'unit_cost' => $item->unit_cost,
                    'supplier_name' => $item->supplier_name,
                    'notes' => 'Saldo awal inventaris.',
                    'occurred_at' => now(),
                ]);
            }

            DB::table('audit_logs')->insert([
                'actor_id' => $actor->id,
                'event' => 'inventory.item_created',
                'auditable_type' => InventoryItem::class,
                'auditable_id' => (string) $item->id,
                'before' => null,
                'after' => json_encode([
                    'sku' => $item->sku,
                    'quantity_on_hand' => $item->quantity_on_hand,
                    'unit_cost' => $item->unit_cost,
                ]),
                'ip_address' => $ipAddress,
                'created_at' => now(),
            ]);

            return $item;
        });

        $this->syncLowStockAlert($item);

        return $item->fresh() ?? $item;
    }

    /**
     * Update inventory master metadata without mutating stock quantity directly.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateItem(InventoryItem $item, array $attributes, User $actor, ?string $ipAddress = null): InventoryItem
    {
        $updated = DB::transaction(function () use ($item, $attributes, $actor, $ipAddress): InventoryItem {
            $locked = InventoryItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();

            $before = [
                'sku' => $locked->sku,
                'name' => $locked->name,
                'category' => $locked->category,
                'reorder_level' => $locked->reorder_level,
                'unit_cost' => $locked->unit_cost,
                'selling_price' => $locked->selling_price,
                'is_active' => $locked->is_active,
            ];

            $locked->update([
                'sku' => isset($attributes['sku']) ? mb_strtoupper(trim((string) $attributes['sku'])) : $locked->sku,
                'name' => trim((string) ($attributes['name'] ?? $locked->name)),
                'category' => trim((string) ($attributes['category'] ?? $locked->category)),
                'description' => array_key_exists('description', $attributes)
                    ? (trim((string) $attributes['description']) !== '' ? trim((string) $attributes['description']) : null)
                    : $locked->description,
                'unit' => trim((string) ($attributes['unit'] ?? $locked->unit)),
                'reorder_level' => (int) ($attributes['reorder_level'] ?? $locked->reorder_level),
                'warranty_days' => array_key_exists('warranty_days', $attributes)
                    ? ($attributes['warranty_days'] !== '' && $attributes['warranty_days'] !== null ? (int) $attributes['warranty_days'] : null)
                    : $locked->warranty_days,
                'warranty_terms' => array_key_exists('warranty_terms', $attributes)
                    ? (trim((string) $attributes['warranty_terms']) !== '' ? trim((string) $attributes['warranty_terms']) : null)
                    : $locked->warranty_terms,
                'unit_cost' => array_key_exists('unit_cost', $attributes) && $attributes['unit_cost'] !== null
                    ? (int) $attributes['unit_cost']
                    : $locked->unit_cost,
                'selling_price' => array_key_exists('selling_price', $attributes)
                    ? ($attributes['selling_price'] !== '' && $attributes['selling_price'] !== null ? (int) $attributes['selling_price'] : null)
                    : $locked->selling_price,
                'storage_location' => array_key_exists('storage_location', $attributes)
                    ? (trim((string) $attributes['storage_location']) !== '' ? trim((string) $attributes['storage_location']) : null)
                    : $locked->storage_location,
                'supplier_name' => array_key_exists('supplier_name', $attributes)
                    ? (trim((string) $attributes['supplier_name']) !== '' ? trim((string) $attributes['supplier_name']) : null)
                    : $locked->supplier_name,
                'supplier_contact' => array_key_exists('supplier_contact', $attributes)
                    ? (trim((string) $attributes['supplier_contact']) !== '' ? trim((string) $attributes['supplier_contact']) : null)
                    : $locked->supplier_contact,
                'is_active' => array_key_exists('is_active', $attributes) ? (bool) $attributes['is_active'] : $locked->is_active,
            ]);

            DB::table('audit_logs')->insert([
                'actor_id' => $actor->id,
                'event' => 'inventory.item_updated',
                'auditable_type' => InventoryItem::class,
                'auditable_id' => (string) $locked->id,
                'before' => json_encode($before),
                'after' => json_encode([
                    'sku' => $locked->sku,
                    'name' => $locked->name,
                    'category' => $locked->category,
                    'reorder_level' => $locked->reorder_level,
                    'unit_cost' => $locked->unit_cost,
                    'selling_price' => $locked->selling_price,
                    'is_active' => $locked->is_active,
                ]),
                'ip_address' => $ipAddress,
                'created_at' => now(),
            ]);

            return $locked;
        });

        $this->syncLowStockAlert($updated);

        return $updated->fresh() ?? $updated;
    }

    /**
     * Record an immutable stock-in, stock-out, or stock adjustment movement with row locking.
     *
     * @param  array<string, mixed>  $data
     */
    public function recordMovement(array $data, User $actor, ?string $ipAddress = null): InventoryMovement
    {
        $recordFinanceExpense = (bool) ($data['record_finance_expense'] ?? false);
        if ($recordFinanceExpense && ! in_array($actor->role, ['operator', 'admin'], true)) {
            throw new AuthorizationException('Anda tidak memiliki izin mencatat transaksi administrasi keuangan.');
        }

        $movement = DB::transaction(function () use ($data, $actor, $ipAddress, $recordFinanceExpense): InventoryMovement {
            $item = InventoryItem::query()
                ->whereKey((int) $data['inventory_item_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if (! $item->is_active) {
                throw ValidationException::withMessages([
                    'inventory_item_id' => 'Barang inventaris berstatus nonaktif.',
                ]);
            }

            $before = $item->quantity_on_hand;
            $occurredAt = isset($data['occurred_at']) && $data['occurred_at'] !== ''
                ? $data['occurred_at']
                : now();
            $inputType = $data['type'];

            $movementType = $inputType;
            $unitCostForMovement = $item->unit_cost;
            $adjustmentReason = null;

            if ($inputType === 'adjustment') {
                $reason = trim((string) ($data['adjustment_reason'] ?? ''));
                if ($reason === '') {
                    throw ValidationException::withMessages([
                        'adjustment_reason' => 'Alasan penyesuaian stok wajib diisi.',
                    ]);
                }

                if (! isset($data['new_quantity'])) {
                    throw ValidationException::withMessages([
                        'new_quantity' => 'Jumlah stok hasil penyesuaian wajib diisi.',
                    ]);
                }

                $targetQty = (int) $data['new_quantity'];
                if ($targetQty < 0) {
                    throw ValidationException::withMessages([
                        'new_quantity' => 'Jumlah stok tidak boleh bernilai negatif.',
                    ]);
                }

                if ($targetQty === $before) {
                    throw ValidationException::withMessages([
                        'new_quantity' => 'Jumlah stok baru sama dengan saldo saat ini.',
                    ]);
                }

                $after = $targetQty;
                $quantity = abs($after - $before);
                $movementType = $after > $before ? 'adjustment_in' : 'adjustment_out';
                $adjustmentReason = $reason;
            } elseif ($inputType === 'stock_out') {
                $quantity = (int) ($data['quantity'] ?? 0);
                if ($quantity <= 0) {
                    throw ValidationException::withMessages([
                        'quantity' => 'Jumlah mutasi minimal satu.',
                    ]);
                }

                if ($quantity > $before) {
                    throw ValidationException::withMessages([
                        'quantity' => 'Stok tidak cukup untuk mutasi keluar ini.',
                    ]);
                }

                $after = $before - $quantity;
            } else {
                $quantity = (int) ($data['quantity'] ?? 0);
                if ($quantity <= 0) {
                    throw ValidationException::withMessages([
                        'quantity' => 'Jumlah mutasi minimal satu.',
                    ]);
                }

                $after = $before + $quantity;
                $incomingCost = isset($data['unit_cost']) ? (int) $data['unit_cost'] : $item->unit_cost;
                $unitCostForMovement = $incomingCost;

                // Weighted Average Cost recalculation on stock_in
                $nextWeightedCost = $before <= 0
                    ? $incomingCost
                    : (int) round((($before * $item->unit_cost) + ($quantity * $incomingCost)) / $after);

                $item->unit_cost = $nextWeightedCost;
            }

            $item->quantity_on_hand = $after;
            $item->save();

            $reference = isset($data['reference']) && trim((string) $data['reference']) !== ''
                ? trim((string) $data['reference'])
                : null;
            $supplierName = isset($data['supplier_name']) && trim((string) $data['supplier_name']) !== ''
                ? trim((string) $data['supplier_name'])
                : ($inputType === 'stock_in' ? $item->supplier_name : null);

            $serviceOrderId = ! empty($data['service_order_id']) ? (string) $data['service_order_id'] : null;

            $movement = $item->movements()->create([
                'service_order_id' => $serviceOrderId,
                'actor_id' => $actor->id,
                'type' => $movementType,
                'quantity' => $quantity,
                'balance_before' => $before,
                'balance_after' => $after,
                'unit_cost' => $unitCostForMovement,
                'reference' => $reference,
                'supplier_name' => $supplierName,
                'adjustment_reason' => $adjustmentReason,
                'notes' => isset($data['notes']) && trim((string) $data['notes']) !== ''
                    ? trim((string) $data['notes'])
                    : null,
                'occurred_at' => $occurredAt,
            ]);

            if ($recordFinanceExpense && $movementType === 'stock_in' && $unitCostForMovement > 0) {
                $totalAmount = $quantity * $unitCostForMovement;
                $deterministicRef = "INV-MOV-{$movement->id}";

                if (! FinancialTransaction::query()->where('reference', $deterministicRef)->exists()) {
                    $tx = FinancialTransaction::query()->create([
                        'service_order_id' => $serviceOrderId,
                        'actor_id' => $actor->id,
                        'type' => 'expense',
                        'category' => 'inventory_purchase',
                        'amount' => $totalAmount,
                        'payment_method' => ! empty($data['payment_method']) ? (string) $data['payment_method'] : 'bank_transfer',
                        'status' => 'pending',
                        'reference' => $deterministicRef,
                        'description' => "Pembelian stok {$item->sku} · {$item->name} ({$quantity} {$item->unit})".($supplierName ? " - {$supplierName}" : ''),
                        'occurred_at' => $occurredAt,
                    ]);

                    $movement->forceFill(['financial_transaction_id' => $tx->id])->save();

                    DB::table('audit_logs')->insert([
                        'actor_id' => $actor->id,
                        'event' => 'finance.transaction_recorded',
                        'auditable_type' => FinancialTransaction::class,
                        'auditable_id' => (string) $tx->id,
                        'before' => null,
                        'after' => json_encode([
                            'type' => $tx->type,
                            'category' => $tx->category,
                            'amount' => $tx->amount,
                            'status' => 'pending',
                            'inventory_movement_id' => $movement->id,
                        ]),
                        'ip_address' => $ipAddress,
                        'created_at' => now(),
                    ]);
                }
            }

            DB::table('audit_logs')->insert([
                'actor_id' => $actor->id,
                'event' => 'inventory.stock_moved',
                'auditable_type' => InventoryItem::class,
                'auditable_id' => (string) $item->id,
                'before' => json_encode(['quantity_on_hand' => $before]),
                'after' => json_encode([
                    'quantity_on_hand' => $after,
                    'type' => $movementType,
                    'quantity' => $quantity,
                    'movement_id' => $movement->id,
                ]),
                'ip_address' => $ipAddress,
                'created_at' => now(),
            ]);

            return $movement;
        }, attempts: 3);

        $freshItem = InventoryItem::query()->find((int) $data['inventory_item_id']);
        if ($freshItem instanceof InventoryItem) {
            $this->syncLowStockAlert($freshItem, $actor, $ipAddress);
        }

        return $movement;
    }

    /**
     * Request spare parts for a service order without deducting stock prematurely.
     */
    public function requestSparePart(
        ServiceOrder $order,
        InventoryItem $item,
        User $actor,
        int $quantity,
        ?string $notes = null,
        ?string $ipAddress = null,
    ): ServiceOrderSparePart {
        Gate::forUser($actor)->authorize('requestForOrder', [ServiceOrderSparePart::class, $order]);

        if (! $item->is_active) {
            throw ValidationException::withMessages([
                'inventory_item_id' => 'Barang inventaris sedang nonaktif dan tidak dapat diminta.',
            ]);
        }

        if (in_array($order->status, SlaService::TERMINAL_STATUSES, true)) {
            throw ValidationException::withMessages([
                'spare_part' => 'Tidak dapat meminta spare-part pada tiket yang sudah selesai atau dibatalkan.',
            ]);
        }

        $request = DB::transaction(function () use ($order, $item, $actor, $quantity, $notes, $ipAddress): ServiceOrderSparePart {
            $created = ServiceOrderSparePart::query()->create([
                'service_order_id' => $order->id,
                'inventory_item_id' => $item->id,
                'requested_by' => $actor->id,
                'requested_quantity' => $quantity,
                'issued_quantity' => 0,
                'returned_quantity' => 0,
                'unit_cost' => $item->unit_cost,
                'unit_price' => $item->selling_price ?? $item->unit_cost,
                'status' => 'requested',
                'request_notes' => trim((string) $notes) !== '' ? trim((string) $notes) : null,
                'requested_at' => now(),
            ]);

            DB::table('audit_logs')->insert([
                'actor_id' => $actor->id,
                'event' => 'inventory.spare_part_requested',
                'auditable_type' => ServiceOrder::class,
                'auditable_id' => $order->id,
                'before' => null,
                'after' => json_encode([
                    'spare_part_request_id' => $created->id,
                    'inventory_item_id' => $item->id,
                    'requested_quantity' => $quantity,
                ]),
                'ip_address' => $ipAddress,
                'created_at' => now(),
            ]);

            return $created;
        });

        $recipients = User::query()
            ->whereIn('role', ['warehouse', 'operator', 'admin'])
            ->where('is_active', true)
            ->where('id', '!=', $actor->id)
            ->get();

        $this->notifications->notifyUsers(
            $recipients,
            'inventory.spare_part_requested',
            "Permintaan spare-part {$order->ticket_number}",
            "{$actor->name} meminta {$quantity} {$item->unit} {$item->name} untuk tiket {$order->ticket_number}.",
            route('orders.show', $order),
            ['service_order_id' => $order->id, 'spare_part_request_id' => $request->id],
        );

        return $request;
    }

    /**
     * Approve and issue a spare-part request, deducting stock atomically under lock.
     */
    public function issueSparePart(
        ServiceOrderSparePart $sparePart,
        User $actor,
        int $issuedQuantity,
        ?string $notes = null,
        ?string $ipAddress = null,
    ): ServiceOrderSparePart {
        Gate::forUser($actor)->authorize('issue', $sparePart);

        $updated = DB::transaction(function () use ($sparePart, $actor, $issuedQuantity, $notes, $ipAddress): ServiceOrderSparePart {
            $lockedReq = ServiceOrderSparePart::query()
                ->whereKey($sparePart->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedReq->status !== 'requested') {
                throw ValidationException::withMessages([
                    'issued_quantity' => 'Permintaan spare-part ini sudah diproses sebelumnya.',
                ]);
            }

            if ($issuedQuantity < 1 || $issuedQuantity > $lockedReq->requested_quantity) {
                throw ValidationException::withMessages([
                    'issued_quantity' => "Jumlah pengeluaran harus antara 1 hingga {$lockedReq->requested_quantity}.",
                ]);
            }

            $lockedItem = InventoryItem::query()
                ->whereKey($lockedReq->inventory_item_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedItem->is_active) {
                throw ValidationException::withMessages([
                    'issued_quantity' => 'Barang inventaris sedang nonaktif.',
                ]);
            }

            if ($issuedQuantity > $lockedItem->quantity_on_hand) {
                throw ValidationException::withMessages([
                    'issued_quantity' => 'Stok tidak cukup untuk mengeluarkan spare-part ini.',
                ]);
            }

            $order = ServiceOrder::query()->findOrFail($lockedReq->service_order_id);
            $before = $lockedItem->quantity_on_hand;
            $after = $before - $issuedQuantity;

            $lockedItem->update(['quantity_on_hand' => $after]);

            $unitPrice = $lockedItem->selling_price ?? $lockedItem->unit_cost;

            $lockedReq->update([
                'approved_by' => $actor->id,
                'issued_quantity' => $issuedQuantity,
                'unit_cost' => $lockedItem->unit_cost,
                'unit_price' => $unitPrice,
                'status' => 'issued',
                'issue_notes' => trim((string) $notes) !== '' ? trim((string) $notes) : null,
                'issued_at' => now(),
            ]);

            $lockedItem->movements()->create([
                'service_order_id' => $order->id,
                'spare_part_request_id' => $lockedReq->id,
                'actor_id' => $actor->id,
                'type' => 'spare_part_issue',
                'quantity' => $issuedQuantity,
                'balance_before' => $before,
                'balance_after' => $after,
                'unit_cost' => $lockedItem->unit_cost,
                'reference' => $order->ticket_number,
                'notes' => trim((string) $notes) !== ''
                    ? trim((string) $notes)
                    : "Pengeluaran spare-part untuk tiket {$order->ticket_number}.",
                'occurred_at' => now(),
            ]);

            DB::table('audit_logs')->insert([
                'actor_id' => $actor->id,
                'event' => 'inventory.spare_part_issued',
                'auditable_type' => ServiceOrder::class,
                'auditable_id' => $order->id,
                'before' => json_encode(['quantity_on_hand' => $before, 'status' => 'requested']),
                'after' => json_encode([
                    'quantity_on_hand' => $after,
                    'spare_part_request_id' => $lockedReq->id,
                    'issued_quantity' => $issuedQuantity,
                    'status' => 'issued',
                ]),
                'ip_address' => $ipAddress,
                'created_at' => now(),
            ]);

            return $lockedReq;
        }, attempts: 3);

        $freshItem = InventoryItem::query()->find($updated->inventory_item_id);
        if ($freshItem !== null) {
            $this->syncLowStockAlert($freshItem, $actor, $ipAddress);
        }

        $order = ServiceOrder::query()->with(['assignedTechnician', 'activeTechnicianAssignments.user'])->find($updated->service_order_id);
        if ($order !== null) {
            $recipients = collect([$updated->requester, $order->assignedTechnician])
                ->merge($order->activeTechnicianAssignments->pluck('user'))
                ->filter(fn ($u): bool => $u instanceof User && $u->is_active && $u->id !== $actor->id)
                ->unique('id')
                ->values();

            $this->notifications->notifyUsers(
                $recipients,
                'inventory.spare_part_issued',
                "Spare-part disiapkan ({$order->ticket_number})",
                "{$updated->issued_quantity} {$freshItem?->unit} {$freshItem?->name} telah disetujui dan dikeluarkan oleh gudang.",
                route('orders.show', $order),
                ['service_order_id' => $order->id, 'spare_part_request_id' => $updated->id],
            );
        }

        return $updated->fresh(['item', 'order', 'requester', 'approver', 'returner']) ?? $updated;
    }

    /**
     * Process a controlled return of previously issued spare parts back into warehouse inventory.
     */
    public function returnSparePart(
        ServiceOrderSparePart $sparePart,
        User $actor,
        int $returnQuantity,
        ?string $notes = null,
        ?string $ipAddress = null,
    ): ServiceOrderSparePart {
        Gate::forUser($actor)->authorize('returnPart', $sparePart);

        $updated = DB::transaction(function () use ($sparePart, $actor, $returnQuantity, $notes, $ipAddress): ServiceOrderSparePart {
            $lockedReq = ServiceOrderSparePart::query()
                ->whereKey($sparePart->id)
                ->lockForUpdate()
                ->firstOrFail();

            $returnable = $lockedReq->netIssuedQuantity();
            if ($returnable <= 0 || ! in_array($lockedReq->status, ['issued', 'partially_returned'], true)) {
                throw ValidationException::withMessages([
                    'return_quantity' => 'Tidak ada jumlah spare-part yang dapat diretur.',
                ]);
            }

            if ($returnQuantity < 1 || $returnQuantity > $returnable) {
                throw ValidationException::withMessages([
                    'return_quantity' => "Jumlah retur harus antara 1 hingga {$returnable}.",
                ]);
            }

            $lockedItem = InventoryItem::query()
                ->whereKey($lockedReq->inventory_item_id)
                ->lockForUpdate()
                ->firstOrFail();

            $order = ServiceOrder::query()->findOrFail($lockedReq->service_order_id);
            $before = $lockedItem->quantity_on_hand;
            $after = $before + $returnQuantity;

            $lockedItem->update(['quantity_on_hand' => $after]);

            $newReturnedTotal = $lockedReq->returned_quantity + $returnQuantity;
            $nextStatus = $newReturnedTotal >= $lockedReq->issued_quantity ? 'returned' : 'partially_returned';

            $lockedReq->update([
                'returned_by' => $actor->id,
                'returned_quantity' => $newReturnedTotal,
                'status' => $nextStatus,
                'return_notes' => trim((string) $notes) !== '' ? trim((string) $notes) : $lockedReq->return_notes,
                'returned_at' => now(),
            ]);

            $lockedItem->movements()->create([
                'service_order_id' => $order->id,
                'spare_part_request_id' => $lockedReq->id,
                'actor_id' => $actor->id,
                'type' => 'spare_part_return',
                'quantity' => $returnQuantity,
                'balance_before' => $before,
                'balance_after' => $after,
                'unit_cost' => $lockedReq->unit_cost,
                'reference' => $order->ticket_number,
                'notes' => trim((string) $notes) !== ''
                    ? trim((string) $notes)
                    : "Retur spare-part dari tiket {$order->ticket_number}.",
                'occurred_at' => now(),
            ]);

            DB::table('audit_logs')->insert([
                'actor_id' => $actor->id,
                'event' => 'inventory.spare_part_returned',
                'auditable_type' => ServiceOrder::class,
                'auditable_id' => $order->id,
                'before' => json_encode(['quantity_on_hand' => $before, 'returned_quantity' => $lockedReq->returned_quantity - $returnQuantity]),
                'after' => json_encode([
                    'quantity_on_hand' => $after,
                    'spare_part_request_id' => $lockedReq->id,
                    'returned_quantity' => $newReturnedTotal,
                    'status' => $nextStatus,
                ]),
                'ip_address' => $ipAddress,
                'created_at' => now(),
            ]);

            return $lockedReq;
        }, attempts: 3);

        $freshItem = InventoryItem::query()->find($updated->inventory_item_id);
        if ($freshItem !== null) {
            $this->syncLowStockAlert($freshItem, $actor, $ipAddress);
        }

        $recipients = User::query()
            ->whereIn('role', ['warehouse', 'operator', 'admin'])
            ->where('is_active', true)
            ->where('id', '!=', $actor->id)
            ->get();

        $this->notifications->notifyUsers(
            $recipients,
            'inventory.spare_part_returned',
            'Retur spare-part dicatat',
            "{$returnQuantity} {$freshItem?->unit} {$freshItem?->name} dikembalikan ke stok gudang.",
            route('orders.show', $updated->service_order_id),
            ['service_order_id' => $updated->service_order_id, 'spare_part_request_id' => $updated->id],
        );

        return $updated->fresh(['item', 'order', 'requester', 'approver', 'returner']) ?? $updated;
    }

    /**
     * Cancel an unissued spare-part request without affecting inventory balances.
     */
    public function cancelSparePart(
        ServiceOrderSparePart $sparePart,
        User $actor,
        ?string $reason = null,
        ?string $ipAddress = null,
    ): ServiceOrderSparePart {
        Gate::forUser($actor)->authorize('cancel', $sparePart);

        return DB::transaction(function () use ($sparePart, $actor, $reason, $ipAddress): ServiceOrderSparePart {
            $lockedReq = ServiceOrderSparePart::query()
                ->whereKey($sparePart->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedReq->status !== 'requested') {
                throw ValidationException::withMessages([
                    'status' => 'Hanya permintaan berstatus menunggu persetujuan yang dapat dibatalkan.',
                ]);
            }

            $lockedReq->update([
                'status' => 'cancelled',
                'cancellation_reason' => trim((string) $reason) !== '' ? trim((string) $reason) : null,
                'cancelled_at' => now(),
            ]);

            DB::table('audit_logs')->insert([
                'actor_id' => $actor->id,
                'event' => 'inventory.spare_part_cancelled',
                'auditable_type' => ServiceOrder::class,
                'auditable_id' => $lockedReq->service_order_id,
                'before' => json_encode(['status' => 'requested']),
                'after' => json_encode(['status' => 'cancelled', 'spare_part_request_id' => $lockedReq->id]),
                'ip_address' => $ipAddress,
                'created_at' => now(),
            ]);

            return $lockedReq;
        });
    }

    /**
     * Synchronize low-stock alert state with deduplication and automatic resolution on replenishment.
     */
    public function syncLowStockAlert(InventoryItem $item, ?User $actor = null, ?string $ipAddress = null): void
    {
        $fresh = $item->fresh();
        if ($fresh === null) {
            return;
        }

        $isLow = $fresh->is_active && $fresh->quantity_on_hand <= $fresh->reorder_level;

        if ($isLow) {
            $claimed = InventoryItem::query()
                ->whereKey($fresh->id)
                ->whereNull('low_stock_alerted_at')
                ->update(['low_stock_alerted_at' => now()]);

            if ($claimed === 1) {
                $recipients = User::query()
                    ->whereIn('role', ['warehouse', 'operator', 'admin', 'supervisor'])
                    ->where('is_active', true)
                    ->get();

                $title = $fresh->quantity_on_hand === 0
                    ? "Stok habis: {$fresh->name}"
                    : "Stok kritis: {$fresh->name}";

                $this->notifications->notifyUsers(
                    $recipients,
                    'inventory.low_stock',
                    $title,
                    "Sisa stok {$fresh->quantity_on_hand} {$fresh->unit} (batas minimum {$fresh->reorder_level}).",
                    route('inventory.index'),
                    ['sku' => $fresh->sku, 'quantity_on_hand' => $fresh->quantity_on_hand],
                );
            }

            return;
        }

        if ($fresh->low_stock_alerted_at !== null) {
            $cleared = InventoryItem::query()
                ->whereKey($fresh->id)
                ->whereNotNull('low_stock_alerted_at')
                ->update(['low_stock_alerted_at' => null]);

            if ($cleared === 1) {
                DB::table('audit_logs')->insert([
                    'actor_id' => $actor?->id,
                    'event' => 'inventory.low_stock_resolved',
                    'auditable_type' => InventoryItem::class,
                    'auditable_id' => (string) $fresh->id,
                    'before' => json_encode(['alerted_at' => $fresh->low_stock_alerted_at->toIso8601String()]),
                    'after' => json_encode(['quantity_on_hand' => $fresh->quantity_on_hand, 'resolved' => true]),
                    'ip_address' => $ipAddress,
                    'created_at' => now(),
                ]);
            }
        }
    }

    /**
     * Calculate warehouse inventory valuation and movement summaries from real database records.
     *
     * @return array{
     *     item_types: int,
     *     inactive_types: int,
     *     units: int,
     *     low_stock: int,
     *     low_warning_only: int,
     *     out_of_stock: int,
     *     asset_value: int,
     *     retail_value: int,
     *     valuation_method: string,
     *     valuation_method_label: string,
     *     movement_summary: array{
     *         stock_in_units: int,
     *         stock_out_units: int,
     *         adjustment_net_units: int,
     *         spare_part_issued_units: int,
     *         spare_part_returned_units: int
     *     },
     *     finance_summary: array{
     *         verified_purchase_expense: int,
     *         pending_purchase_expense: int
     *     }
     * }
     */
    public function valuationSummary(): array
    {
        /** @var Collection<int, InventoryItem> $activeItems */
        $activeItems = InventoryItem::query()->where('is_active', true)->get();
        $inactiveCount = InventoryItem::query()->where('is_active', false)->count();

        $outOfStock = $activeItems->filter(fn (InventoryItem $i): bool => $i->quantity_on_hand === 0)->count();
        $lowAll = $activeItems->filter(fn (InventoryItem $i): bool => $i->quantity_on_hand <= $i->reorder_level)->count();
        $lowWarningOnly = $activeItems->filter(fn (InventoryItem $i): bool => $i->quantity_on_hand > 0 && $i->quantity_on_hand <= $i->reorder_level)->count();

        $assetValue = (int) $activeItems->sum(fn (InventoryItem $i): int => $i->quantity_on_hand * $i->unit_cost);
        $retailValue = (int) $activeItems->sum(
            fn (InventoryItem $i): int => $i->quantity_on_hand * ($i->selling_price ?? $i->unit_cost),
        );

        $movementTotals = InventoryMovement::query()
            ->selectRaw('type, COALESCE(SUM(quantity), 0) as total_qty')
            ->groupBy('type')
            ->pluck('total_qty', 'type');

        $verifiedPurchaseExpense = (int) FinancialTransaction::query()
            ->where('type', 'expense')
            ->where('category', 'inventory_purchase')
            ->where('status', 'verified')
            ->sum('amount');

        $pendingPurchaseExpense = (int) FinancialTransaction::query()
            ->where('type', 'expense')
            ->where('category', 'inventory_purchase')
            ->where('status', 'pending')
            ->sum('amount');

        return [
            'item_types' => $activeItems->count(),
            'inactive_types' => $inactiveCount,
            'units' => (int) $activeItems->sum('quantity_on_hand'),
            'low_stock' => $lowAll,
            'low_warning_only' => $lowWarningOnly,
            'out_of_stock' => $outOfStock,
            'asset_value' => $assetValue,
            'retail_value' => $retailValue,
            'valuation_method' => self::VALUATION_METHOD,
            'valuation_method_label' => self::VALUATION_METHOD_LABEL,
            'movement_summary' => [
                'stock_in_units' => (int) ($movementTotals['stock_in'] ?? 0),
                'stock_out_units' => (int) ($movementTotals['stock_out'] ?? 0),
                'adjustment_net_units' => (int) ($movementTotals['adjustment_in'] ?? 0) - (int) ($movementTotals['adjustment_out'] ?? 0),
                'spare_part_issued_units' => (int) ($movementTotals['spare_part_issue'] ?? 0),
                'spare_part_returned_units' => (int) ($movementTotals['spare_part_return'] ?? 0),
            ],
            'finance_summary' => [
                'verified_purchase_expense' => $verifiedPurchaseExpense,
                'pending_purchase_expense' => $pendingPurchaseExpense,
            ],
        ];
    }
}
