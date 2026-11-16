<?php

namespace App\Http\Resources\Api\V1;

use App\Actions\Orders\UpdateOrderStatus;
use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** @mixin ServiceOrder */
class OrderDetailResource extends OrderResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ServiceOrder $order */
        $order = $this->resource;
        /** @var User $viewer */
        $viewer = $request->user();
        $canReview = Gate::forUser($viewer)->allows('review', $order);
        $team = $order->activeTechnicianAssignments->pluck('user')->filter()
            ->when($order->assignedTechnician !== null, fn ($users) => $users->push($order->assignedTechnician))
            ->unique('id')->values();
        $reviewables = $order->assignments->where('assignment_role', 'technician')->pluck('user')->filter()
            ->when($order->assignedTechnician !== null, fn ($users) => $users->push($order->assignedTechnician))
            ->unique('id')->values();
        $reviews = $order->technicianReviews->keyBy('technician_id');

        return [
            ...parent::toArray($request),
            'phone' => $order->phone,
            'email' => $order->email,
            'address' => $order->address,
            'district' => $order->district,
            'service_mode' => $order->getAttribute('service_mode'),
            'urgency' => $order->urgency,
            'preferred_date' => $order->preferred_date?->toDateString(),
            'preferred_window' => $order->preferred_window,
            'completed_at' => $order->completed_at?->toIso8601String(),
            'technician' => $order->assignedTechnician ? $this->technician($order->assignedTechnician, $viewer) : null,
            'technician_team' => $team->map(fn (User $user) => $this->technician($user, $viewer))->all(),
            'timeline' => $order->statusEvents
                ->filter(fn ($event) => $viewer->role !== 'customer' || $event->visibility === 'customer')
                ->values()->map(fn ($event) => [
                    'id' => $event->id,
                    'status' => $event->status,
                    'title' => $event->title,
                    'description' => $event->description,
                    'occurred_at' => $event->occurred_at->toIso8601String(),
                ])->all(),
            'finance' => [
                'estimated_total' => $order->estimated_total,
                'verified_income' => (int) $order->financialTransactions->where('status', 'verified')->sum('amount'),
                'pending_income' => (int) $order->financialTransactions->where('status', 'pending')->sum('amount'),
            ],
            'allowed_statuses' => array_map(fn (string $status) => ['value' => $status, 'label' => UpdateOrderStatus::statusLabel($status)], UpdateOrderStatus::allowedTransitions($viewer, $order)),
            'can_chat' => $order->assigned_technician_id !== null && $order->customer_id !== null,
            'can_review' => $canReview,
            'reviewable_technicians' => $canReview ? $reviewables->map(fn (User $user) => [
                ...$this->technician($user, $viewer),
                'review' => $reviews->has($user->id) ? new TechnicianReviewResource($reviews->get($user->id)) : null,
            ])->all() : [],
        ];
    }

    /** @return array<string, mixed> */
    private function technician(User $technician, User $viewer): array
    {
        $average = $technician->getAttribute('rating_average');

        return [
            'id' => $technician->id,
            'name' => $technician->name,
            'avatar_url' => $technician->avatar_url,
            'phone' => $technician->show_phone_publicly || $viewer->role !== 'customer' ? $technician->phone : null,
            'specialty' => $technician->specialty,
            'bio' => $technician->bio,
            'rating_average' => $average === null ? null : round((float) $average, 2),
            'reviews_count' => (int) $technician->getAttribute('received_technician_reviews_count'),
        ];
    }
}
