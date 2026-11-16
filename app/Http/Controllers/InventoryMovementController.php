<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInventoryMovementRequest;
use App\Services\Inventory\InventoryService;
use Illuminate\Http\RedirectResponse;

class InventoryMovementController extends Controller
{
    public function store(StoreInventoryMovementRequest $request, InventoryService $inventory): RedirectResponse
    {
        $validated = $request->validated();

        $inventory->recordMovement($validated, $request->user(), $request->ip());

        return back()->with('success', 'Mutasi stok berhasil dicatat.');
    }
}
