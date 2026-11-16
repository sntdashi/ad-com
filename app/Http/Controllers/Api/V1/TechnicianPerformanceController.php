<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PerformanceReviewResource;
use App\Models\ServiceOrder;
use App\Models\TechnicianReview;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TechnicianPerformanceController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->role === 'technician', 403);
        Gate::authorize('viewAny', TechnicianReview::class);

        $reviews = $user->receivedTechnicianReviews();
        $summary = (clone $reviews)->selectRaw('COUNT(*) AS reviews_count, AVG(rating) AS rating_average, AVG(quality_rating) AS quality_average, AVG(communication_rating) AS communication_average, AVG(punctuality_rating) AS punctuality_average')->first();
        $completedOrders = ServiceOrder::query()
            ->whereIn('status', ['completed', 'warranty', 'closed'])
            ->where(function (Builder $query) use ($user): void {
                $query->where('assigned_technician_id', $user->id)
                    ->orWhereHas('assignments', fn (Builder $assignments) => $assignments->where('user_id', $user->id)->where('assignment_role', 'technician'));
            })->count();
        $averages = [];

        foreach (['rating_average', 'quality_average', 'communication_average', 'punctuality_average'] as $key) {
            $value = $summary?->getAttribute($key);
            $averages[$key] = $value === null ? null : round((float) $value, 2);
        }

        return response()->json(['data' => [
            ...$averages,
            'reviews_count' => (int) ($summary?->getAttribute('reviews_count') ?? 0),
            'completed_orders_count' => $completedOrders,
            'low_rating_reviews_count' => (clone $reviews)->where('rating', '<=', 2)->count(),
            'recent_reviews' => PerformanceReviewResource::collection((clone $reviews)->with('serviceOrder:id,ticket_number')->latest('id')->limit(20)->get()),
        ]]);
    }
}
