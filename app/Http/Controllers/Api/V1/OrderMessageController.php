<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Orders\SendOrderMessage;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderMessageRequest;
use App\Http\Resources\Api\V1\OrderMessageResource;
use App\Models\ServiceOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class OrderMessageController extends Controller
{
    public function index(Request $request, ServiceOrder $order): AnonymousResourceCollection
    {
        Gate::authorize('view', $order);
        $request->validate(['before_id' => ['sometimes', 'integer', 'min:1']]);
        $messages = $order->messages()->with('sender:id,name,role,avatar_url')
            ->when($request->filled('before_id'), fn ($query) => $query->where('id', '<', $request->integer('before_id')))
            ->reorder('id', 'desc')->limit(50)->get()->reverse()->values();

        return OrderMessageResource::collection($messages)->additional(['meta' => [
            'oldest_id' => $messages->first()?->id,
            'has_more' => $messages->count() === 50,
        ]]);
    }

    public function store(StoreOrderMessageRequest $request, ServiceOrder $order, SendOrderMessage $send): JsonResponse
    {
        $message = $send->handle($order, $request->user(), $request->string('body')->toString());

        return (new OrderMessageResource($message->load('sender:id,name,role,avatar_url')))->response()->setStatusCode(201);
    }
}
