<?php

namespace App\Http\Resources\Api\V1;

use App\Models\OrderMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OrderMessage */
class OrderMessageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            'created_at' => $this->created_at->toIso8601String(),
            'sender' => $this->sender?->only(['id', 'name', 'role', 'avatar_url']),
            'is_mine' => $this->sender_id === $request->user()?->id,
        ];
    }
}
