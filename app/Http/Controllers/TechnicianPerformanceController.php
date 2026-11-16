<?php

namespace App\Http\Controllers;

use App\Models\TechnicianReview;
use App\Models\User;
use App\Services\Workforce\WorkforceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TechnicianPerformanceController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): Response
    {
        Gate::authorize('viewAny', TechnicianReview::class);

        $user = $request->user();
        $technicianQuery = User::query()
            ->where('role', 'technician')
            ->when($user->role === 'technician', fn ($query) => $query->whereKey($user->id))
            ->withAvg('receivedTechnicianReviews as rating_average', 'rating')
            ->withAvg('receivedTechnicianReviews as quality_average', 'quality_rating')
            ->withAvg('receivedTechnicianReviews as communication_average', 'communication_rating')
            ->withAvg('receivedTechnicianReviews as punctuality_average', 'punctuality_rating')
            ->withCount('receivedTechnicianReviews')
            ->withCount([
                'receivedTechnicianReviews as low_rating_reviews_count' => fn ($query) => $query->where('rating', '<=', 2),
                'orderAssignments as completed_assignments_count' => fn ($query) => $query
                    ->whereHas('serviceOrder', fn ($orders) => $orders->whereIn('status', ['completed', 'warranty', 'closed'])),
            ]);

        $technicianModels = $technicianQuery
            ->orderByDesc('rating_average')
            ->orderBy('name')
            ->get();
        $technicians = [];
        $workforce = app(WorkforceService::class);
        $now = now();

        foreach ($technicianModels as $technician) {
            $ratingAverage = $this->nullableAverage($technician->getAttribute('rating_average'));
            $reviewCount = (int) $technician->getAttribute('received_technician_reviews_count');
            $workMetrics = $workforce->metricsForTechnician($technician, $now);

            $technicians[] = [
                'id' => $technician->id,
                'name' => $technician->name,
                'username' => $technician->username,
                'avatar_url' => $technician->avatar_url,
                'specialty' => $technician->specialty,
                'is_active' => $technician->is_active,
                'rating_average' => $ratingAverage,
                'quality_average' => $this->nullableAverage($technician->getAttribute('quality_average')),
                'communication_average' => $this->nullableAverage($technician->getAttribute('communication_average')),
                'punctuality_average' => $this->nullableAverage($technician->getAttribute('punctuality_average')),
                'reviews_count' => $reviewCount,
                'low_rating_reviews_count' => (int) $technician->getAttribute('low_rating_reviews_count'),
                'completed_assignments_count' => (int) $technician->getAttribute('completed_assignments_count'),
                'performance_level' => $this->performanceLevel($ratingAverage, $reviewCount),
                'work' => [
                    'completed_jobs_count' => $workMetrics['completed_jobs_count'],
                    'avg_resolution_minutes' => $workMetrics['avg_resolution_minutes'],
                    'avg_work_session_minutes' => $workMetrics['avg_work_session_minutes'],
                    'sla_compliance_rate' => $workMetrics['sla_compliance_rate'],
                    'reopened_jobs_count' => $workMetrics['reopened_jobs_count'],
                ],
            ];
        }

        $reviewsQuery = TechnicianReview::query()
            ->when($user->role === 'technician', fn ($query) => $query->where('technician_id', $user->id));

        return Inertia::render('operations/technician-performance', [
            'role' => $user->role,
            'technicians' => $technicians,
            'summary' => [
                'technicians' => count($technicians),
                'reviews' => (clone $reviewsQuery)->count(),
                'average' => $this->nullableAverage((clone $reviewsQuery)->avg('rating')),
                'needs_attention' => (clone $reviewsQuery)->where('rating', '<=', 2)->count(),
            ],
            'distribution' => (clone $reviewsQuery)
                ->selectRaw('rating, count(*) as total')
                ->groupBy('rating')
                ->pluck('total', 'rating'),
            'recentReviews' => (clone $reviewsQuery)
                ->with([
                    'technician:id,name',
                    'serviceOrder:id,ticket_number,service_id',
                    'serviceOrder.service:id,name',
                ])
                ->latest()
                ->limit(20)
                ->get()
                ->map(fn (TechnicianReview $review): array => [
                    'id' => $review->id,
                    'ticket_number' => $review->serviceOrder->ticket_number,
                    'service' => $review->serviceOrder->service->name,
                    'technician' => $review->technician->name,
                    'rating' => $review->rating,
                    'quality_rating' => $review->quality_rating,
                    'communication_rating' => $review->communication_rating,
                    'punctuality_rating' => $review->punctuality_rating,
                    'tags' => $review->tags ?? [],
                    'comment' => $review->comment,
                    'created_at' => $review->created_at->translatedFormat('d M Y, H.i'),
                ]),
        ]);
    }

    private function nullableAverage(mixed $average): ?float
    {
        return $average === null ? null : round((float) $average, 2);
    }

    private function performanceLevel(?float $average, int $reviewCount): string
    {
        if ($reviewCount < 3) {
            return 'data_awal';
        }

        if ($average !== null && $average < 3) {
            return 'perlu_pendampingan';
        }

        if ($average !== null && $average < 4) {
            return 'perlu_dipantau';
        }

        return 'baik';
    }
}
