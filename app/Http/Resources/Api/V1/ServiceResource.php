<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Service */
class ServiceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return $this->resource->only(['id', 'code', 'slug', 'name', 'summary', 'description', 'pricing_label', 'starting_price', 'service_mode', 'icon', 'warranty_days']);
    }
}
