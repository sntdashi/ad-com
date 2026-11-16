<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInventoryItemRequest;
use App\Http\Requests\UpdateInventoryItemRequest;
use App\Models\FinancialTransaction;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderSparePart;
use App\Services\Inventory\InventoryService;
use App\Services\Sla\SlaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class InventoryController extends Controller
{
    public function index(Request $request, InventoryService $inventory): Response
    {
        Gate::authorize('viewAny', InventoryItem::class);

        $user = $request->user();
        $canManage = Gate::allows('create', InventoryItem::class);
        $canRecordFinance = Gate::allows('create', FinancialTransaction::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:100'],
            'stock_status' => ['nullable', Rule::in(['all', 'low_stock', 'out_of_stock', 'normal', 'inactive'])],
            'sort' => ['nullable', Rule::in(['category_name', 'stock_asc', 'stock_desc', 'value_desc', 'name_asc'])],
            'movement_type' => ['nullable', Rule::in(['stock_in', 'stock_out', 'adjustment_in', 'adjustment_out', 'spare_part_issue', 'spare_part_return'])],
        ]);

        $stockStatus = $filters['stock_status'] ?? 'all';
        $sort = $filters['sort'] ?? 'category_name';

        $itemsQuery = InventoryItem::query()
            ->when($stockStatus === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when($stockStatus !== 'inactive', fn ($q) => $q->where('is_active', true))
            ->when($filters['category'] ?? null, fn ($q, string $category) => $q->where('category', $category))
            ->when($filters['search'] ?? null, function ($q, string $search): void {
                $q->where(function ($sub) use ($search): void {
                    $sub->where('sku', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhere('category', 'like', "%{$search}%")
                        ->orWhere('supplier_name', 'like', "%{$search}%")
                        ->orWhere('storage_location', 'like', "%{$search}%");
                });
            })
            ->when($stockStatus === 'low_stock', fn ($q) => $q->where('quantity_on_hand', '>', 0)->whereColumn('quantity_on_hand', '<=', 'reorder_level'))
            ->when($stockStatus === 'out_of_stock', fn ($q) => $q->where('quantity_on_hand', '=', 0))
            ->when($stockStatus === 'normal', fn ($q) => $q->whereColumn('quantity_on_hand', '>', 'reorder_level'));

        match ($sort) {
            'stock_asc' => $itemsQuery->orderBy('quantity_on_hand')->orderBy('name'),
            'stock_desc' => $itemsQuery->orderByDesc('quantity_on_hand')->orderBy('name'),
            'value_desc' => $itemsQuery->orderByRaw('(quantity_on_hand * unit_cost) DESC')->orderBy('name'),
            'name_asc' => $itemsQuery->orderBy('name'),
            default => $itemsQuery->orderBy('category')->orderBy('name'),
        };

        $items = $itemsQuery->get();

        $lowStockAlerts = InventoryItem::query()
            ->where('is_active', true)
            ->whereColumn('quantity_on_hand', '<=', 'reorder_level')
            ->orderBy('quantity_on_hand')
            ->orderBy('name')
            ->get()
            ->map(fn (InventoryItem $item): array => [
                'id' => $item->id,
                'sku' => $item->sku,
                'name' => $item->name,
                'unit' => $item->unit,
                'quantity_on_hand' => $item->quantity_on_hand,
                'reorder_level' => $item->reorder_level,
                'is_out_of_stock' => $item->quantity_on_hand === 0,
            ])
            ->values();

        $movements = InventoryMovement::query()
            ->with(['item:id,sku,name,unit', 'order:id,ticket_number', 'actor:id,name'])
            ->when($filters['movement_type'] ?? null, fn ($q, string $type) => $q->where('type', $type))
            ->when($filters['search'] ?? null, function ($q, string $search): void {
                $q->where(function ($sub) use ($search): void {
                    $sub->whereHas('item', fn ($iq) => $iq->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%"))
                        ->orWhere('reference', 'like', "%{$search}%")
                        ->orWhere('supplier_name', 'like', "%{$search}%");
                });
            })
            ->latest('occurred_at')
            ->latest('id')
            ->paginate(15, ['*'], 'movements_page')
            ->withQueryString()
            ->through(fn (InventoryMovement $movement): array => [
                'id' => $movement->id,
                'item' => $movement->item->name,
                'sku' => $movement->item->sku,
                'unit' => $movement->item->unit,
                'type' => $movement->type,
                'quantity' => $movement->quantity,
                'balance_before' => $movement->balance_before,
                'balance_after' => $movement->balance_after,
                'unit_cost' => $movement->unit_cost,
                'reference' => $movement->reference,
                'supplier_name' => $movement->supplier_name,
                'adjustment_reason' => $movement->adjustment_reason,
                'order' => $movement->order?->ticket_number,
                'actor' => $movement->actor_id === null ? 'Sistem' : ($movement->actor->name ?? 'Sistem'),
                'notes' => $movement->notes,
                'financial_transaction_id' => $movement->financial_transaction_id,
                'occurred_at' => $movement->occurred_at->translatedFormat('d M Y, H.i'),
            ]);

        $sparePartRequests = ServiceOrderSparePart::query()
            ->with([
                'item:id,sku,name,unit,quantity_on_hand',
                'order:id,ticket_number,customer_name,status',
                'requester:id,name',
                'approver:id,name',
                'returner:id,name',
            ])
            ->orderByRaw("CASE WHEN status = 'requested' THEN 0 WHEN status IN ('issued', 'partially_returned') THEN 1 ELSE 2 END")
            ->latest('requested_at')
            ->latest('id')
            ->paginate(12, ['*'], 'requests_page')
            ->withQueryString()
            ->through(fn (ServiceOrderSparePart $sp): array => [
                'id' => $sp->id,
                'service_order_id' => $sp->service_order_id,
                'ticket_number' => $sp->order->ticket_number,
                'customer_name' => $sp->order->customer_name,
                'inventory_item_id' => $sp->inventory_item_id,
                'sku' => $sp->item->sku,
                'item_name' => $sp->item->name,
                'unit' => $sp->item->unit,
                'available_stock' => $sp->item->quantity_on_hand,
                'requested_quantity' => $sp->requested_quantity,
                'issued_quantity' => $sp->issued_quantity,
                'returned_quantity' => $sp->returned_quantity,
                'net_issued_quantity' => $sp->netIssuedQuantity(),
                'unit_cost' => $sp->unit_cost,
                'unit_price' => $sp->unit_price,
                'status' => $sp->status,
                'requester' => $sp->requester->name ?? 'Tim',
                'approver' => $sp->approver?->name,
                'returner' => $sp->returner?->name,
                'request_notes' => $sp->request_notes,
                'issue_notes' => $sp->issue_notes,
                'return_notes' => $sp->return_notes,
                'cancellation_reason' => $sp->cancellation_reason,
                'requested_at' => $sp->requested_at->translatedFormat('d M Y, H.i'),
                'issued_at' => $sp->issued_at?->translatedFormat('d M Y, H.i'),
                'returned_at' => $sp->returned_at?->translatedFormat('d M Y, H.i'),
                'can_issue' => Gate::allows('issue', $sp),
                'can_return' => Gate::allows('returnPart', $sp),
                'can_cancel' => Gate::allows('cancel', $sp),
            ]);

        $orderUsage = ServiceOrderSparePart::query()
            ->with('order:id,ticket_number,customer_name,status')
            ->whereIn('status', ['issued', 'partially_returned'])
            ->get()
            ->groupBy('service_order_id')
            ->map(function ($rows): array {
                $first = $rows->first();
                $netUnits = $rows->sum(fn (ServiceOrderSparePart $r): int => $r->netIssuedQuantity());
                $totalCost = $rows->sum(fn (ServiceOrderSparePart $r): int => $r->netIssuedQuantity() * $r->unit_cost);
                $totalPrice = $rows->sum(fn (ServiceOrderSparePart $r): int => $r->netIssuedQuantity() * $r->unit_price);

                return [
                    'service_order_id' => $first->service_order_id,
                    'ticket_number' => $first->order->ticket_number,
                    'customer_name' => $first->order->customer_name,
                    'items_count' => $rows->count(),
                    'net_units' => (int) $netUnits,
                    'total_cost' => (int) $totalCost,
                    'total_chargeable' => (int) $totalPrice,
                ];
            })
            ->sortByDesc('total_cost')
            ->values();

        $allActiveItems = InventoryItem::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'sku', 'name', 'unit', 'quantity_on_hand', 'unit_cost', 'supplier_name'])
            ->map(fn (InventoryItem $i): array => [
                'id' => $i->id,
                'sku' => $i->sku,
                'name' => $i->name,
                'unit' => $i->unit,
                'quantity_on_hand' => $i->quantity_on_hand,
                'unit_cost' => $i->unit_cost,
                'supplier_name' => $i->supplier_name,
            ]);

        $categories = InventoryItem::query()
            ->select('category')
            ->distinct()
            ->orderBy('category')
            ->pluck('category');

        return Inertia::render('operations/inventory', [
            'role' => $user->role,
            'canManage' => $canManage,
            'canRecordFinance' => $canRecordFinance,
            'stats' => $inventory->valuationSummary(),
            'filters' => [
                'search' => $filters['search'] ?? '',
                'category' => $filters['category'] ?? '',
                'stock_status' => $stockStatus,
                'sort' => $sort,
                'movement_type' => $filters['movement_type'] ?? '',
            ],
            'categories' => $categories,
            'lowStockAlerts' => $lowStockAlerts,
            'allActiveItems' => $allActiveItems,
            'items' => $items->map(fn (InventoryItem $item): array => [
                'id' => $item->id,
                'sku' => $item->sku,
                'name' => $item->name,
                'category' => $item->category,
                'description' => $item->description,
                'unit' => $item->unit,
                'quantity_on_hand' => $item->quantity_on_hand,
                'reorder_level' => $item->reorder_level,
                'warranty_days' => $item->warranty_days,
                'warranty_terms' => $item->warranty_terms,
                'unit_cost' => $item->unit_cost,
                'selling_price' => $item->selling_price,
                'storage_location' => $item->storage_location,
                'supplier_name' => $item->supplier_name,
                'supplier_contact' => $item->supplier_contact,
                'is_active' => $item->is_active,
                'is_low_stock' => $item->quantity_on_hand <= $item->reorder_level,
                'is_out_of_stock' => $item->quantity_on_hand === 0,
                'stock_status' => $item->quantity_on_hand === 0
                    ? 'out_of_stock'
                    : ($item->quantity_on_hand <= $item->reorder_level ? 'low_stock' : 'normal'),
                'total_value' => $item->quantity_on_hand * $item->unit_cost,
            ]),
            'movements' => $movements,
            'sparePartRequests' => $sparePartRequests,
            'orderUsage' => $orderUsage,
            'orders' => $canManage
                ? ServiceOrder::query()
                    ->whereNotIn('status', SlaService::TERMINAL_STATUSES)
                    ->latest()
                    ->limit(50)
                    ->get(['id', 'ticket_number', 'customer_name'])
                : [],
        ]);
    }

    public function store(StoreInventoryItemRequest $request, InventoryService $inventory): RedirectResponse
    {
        $inventory->createItem($request->validated(), $request->user(), $request->ip());

        return back()->with('success', 'Barang inventaris berhasil ditambahkan.');
    }

    public function update(
        UpdateInventoryItemRequest $request,
        InventoryItem $inventoryItem,
        InventoryService $inventory,
    ): RedirectResponse {
        $inventory->updateItem($inventoryItem, $request->validated(), $request->user(), $request->ip());

        return back()->with('success', 'Data barang inventaris berhasil diperbarui.');
    }
}
