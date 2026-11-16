<?php

namespace App\Http\Resources\Api\V1;

use App\Models\TechnicianReview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TechnicianReview */
class TechnicianReviewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            ...$this->resource->only(['id', 'technician_id', 'rating', 'quality_rating', 'communication_rating', 'punctuality_rating', 'comment']),
            'tags' => $this->tags ?? [],
        ];
    }
}
