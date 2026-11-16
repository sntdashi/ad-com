<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderSparePart;
use App\Services\Inventory\InventoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ServiceOrderSparePartController extends Controller
{
    public function store(Request $request, ServiceOrder $order, InventoryService $inventory): RedirectResponse
    {
        $validated = $request->validate([
            'inventory_item_id' => ['required', 'integer', Rule::exists('inventory_items', 'id')->where('is_active', true)],
            'requested_quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'request_notes' => ['nullable', 'string', 'max:500'],
        ], [
            'inventory_item_id.exists' => 'Barang spare-part tidak tersedia atau nonaktif.',
            'requested_quantity.min' => 'Jumlah permintaan minimal 1.',
        ]);

        $item = InventoryItem::query()->findOrFail((int) $validated['inventory_item_id']);

        $inventory->requestSparePart(
            $order,
            $item,
            $request->user(),
            (int) $validated['requested_quantity'],
            isset($validated['request_notes']) ? (string) $validated['request_notes'] : null,
            $request->ip(),
        );

        return back()->with('success', 'Permintaan spare-part berhasil diajukan dan menunggu persetujuan gudang.');
    }

    public function issue(
        Request $request,
        ServiceOrder $order,
        ServiceOrderSparePart $sparePart,
        InventoryService $inventory,
    ): RedirectResponse {
        abort_unless($sparePart->service_order_id === $order->id, 404);

        $validated = $request->validate([
            'issued_quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'issue_notes' => ['nullable', 'string', 'max:500'],
        ]);

        $inventory->issueSparePart(
            $sparePart,
            $request->user(),
            (int) $validated['issued_quantity'],
            isset($validated['issue_notes']) ? (string) $validated['issue_notes'] : null,
            $request->ip(),
        );

        return back()->with('success', 'Spare-part disetujui dan stok berhasil dikeluarkan.');
    }

    public function returnPart(
        Request $request,
        ServiceOrder $order,
        ServiceOrderSparePart $sparePart,
        InventoryService $inventory,
    ): RedirectResponse {
        abort_unless($sparePart->service_order_id === $order->id, 404);

        $validated = $request->validate([
            'return_quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'return_notes' => ['nullable', 'string', 'max:500'],
        ]);

        $inventory->returnSparePart(
            $sparePart,
            $request->user(),
            (int) $validated['return_quantity'],
            isset($validated['return_notes']) ? (string) $validated['return_notes'] : null,
            $request->ip(),
        );

        return back()->with('success', 'Retur spare-part berhasil dicatat dan stok dikembalikan.');
    }

    public function cancel(
        Request $request,
        ServiceOrder $order,
        ServiceOrderSparePart $sparePart,
        InventoryService $inventory,
    ): RedirectResponse {
        abort_unless($sparePart->service_order_id === $order->id, 404);

        $validated = $request->validate([
            'cancellation_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $inventory->cancelSparePart(
            $sparePart,
            $request->user(),
            isset($validated['cancellation_reason']) ? (string) $validated['cancellation_reason'] : null,
            $request->ip(),
        );

        return back()->with('success', 'Permintaan spare-part berhasil dibatalkan.');
    }
}
