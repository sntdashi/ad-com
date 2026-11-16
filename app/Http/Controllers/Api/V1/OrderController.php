<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Orders\CreateServiceOrder;
use App\Actions\Orders\LoadMobileOrder;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreMobileOrderRequest;
use App\Http\Resources\Api\V1\OrderDetailResource;
use App\Http\Resources\Api\V1\OrderResource;
use App\Models\ServiceOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class OrderController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', ServiceOrder::class);
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $user = $request->user();
        $orders = ServiceOrder::query()->with('service');

        if ($user->role === 'customer') {
            $orders->whereBelongsTo($user, 'customer');
        } else {
            $orders->where(fn (Builder $query) => $query->where('assigned_technician_id', $user->id)
                ->orWhereHas('activeTechnicianAssignments', fn (Builder $assignments) => $assignments->where('user_id', $user->id)));
        }

        return OrderResource::collection($orders->latest('created_at')->latest('id')->paginate(20));
    }

    public function store(StoreMobileOrderRequest $request, CreateServiceOrder $create, LoadMobileOrder $load): JsonResponse
    {
        $result = $create->handle($request->validated(), $request->user());

        return (new OrderDetailResource($load->handle($result['order'])))->response()->setStatusCode(201);
    }

    public function show(Request $request, ServiceOrder $order, LoadMobileOrder $load): OrderDetailResource
    {
        Gate::authorize('view', $order);

        return new OrderDetailResource($load->handle($order));
    }
}
