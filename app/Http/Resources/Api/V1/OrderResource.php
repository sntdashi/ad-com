<?php

namespace App\Http\Resources\Api\V1;

use App\Actions\Orders\UpdateOrderStatus;
use App\Models\ServiceOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ServiceOrder */
class OrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ticket_number' => $this->ticket_number,
            'status' => $this->status,
            'status_label' => UpdateOrderStatus::statusLabel($this->status),
            'service' => new ServiceResource($this->whenLoaded('service')),
            'customer_name' => $this->customer_name,
            'complaint' => $this->complaint,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'estimated_total' => $this->estimated_total,
        ];
    }
}
