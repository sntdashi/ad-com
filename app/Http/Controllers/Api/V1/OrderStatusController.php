<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Orders\LoadMobileOrder;
use App\Actions\Orders\UpdateOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\OrderDetailResource;
use App\Models\ServiceOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class OrderStatusController extends Controller
{
    public function update(Request $request, ServiceOrder $order, UpdateOrderStatus $update, LoadMobileOrder $load): OrderDetailResource
    {
        Gate::authorize('update', $order);
        $validated = $request->validate([
            'status' => ['required', Rule::in(UpdateOrderStatus::allowedTransitions($request->user(), $order))],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $updated = $update->handle($order, $request->user(), $validated['status'], $validated['note'] ?? null, $request->ip());

        return new OrderDetailResource($load->handle($updated));
    }
}
