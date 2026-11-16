<?php

namespace App\Http\Resources\Api\V1;

use App\Models\TechnicianReview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TechnicianReview */
class PerformanceReviewResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rating' => $this->rating,
            'quality_rating' => $this->quality_rating,
            'communication_rating' => $this->communication_rating,
            'punctuality_rating' => $this->punctuality_rating,
            'tags' => $this->tags ?? [],
            'comment' => $this->comment,
            'ticket_number' => $this->whenLoaded('serviceOrder', fn () => $this->serviceOrder?->ticket_number),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
