<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\InventoryItemResource;
use App\Models\InventoryItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class InventoryController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()?->role === 'technician', 403);
        Gate::authorize('viewAny', InventoryItem::class);
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        return InventoryItemResource::collection(InventoryItem::query()
            ->where('is_active', true)
            ->orderBy('name')->orderBy('id')
            ->paginate(30));
    }
}
