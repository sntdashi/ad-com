<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Orders\SaveTechnicianReview;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTechnicianReviewRequest;
use App\Http\Resources\Api\V1\TechnicianReviewResource;
use App\Models\ServiceOrder;

class TechnicianReviewController extends Controller
{
    public function store(StoreTechnicianReviewRequest $request, ServiceOrder $order, SaveTechnicianReview $save): TechnicianReviewResource
    {
        return new TechnicianReviewResource($save->handle($order, $request->user(), $request->validated(), $request->ip()));
    }
}
