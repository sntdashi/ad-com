<?php

namespace App\Actions\Orders;

use App\Models\ServiceOrder;
use App\Models\TechnicianReview;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SaveTechnicianReview
{
    /** @param array<string, mixed> $data */
    public function handle(ServiceOrder $order, User $customer, array $data, ?string $ipAddress): TechnicianReview
    {
        return DB::transaction(function () use ($order, $customer, $data, $ipAddress): TechnicianReview {
            $lockedOrder = ServiceOrder::query()->lockForUpdate()->findOrFail($order->id);
            Gate::forUser($customer)->authorize('review', $lockedOrder);
            $technicianId = (int) $data['technician_id'];
            $workedOnOrder = $lockedOrder->assigned_technician_id === $technicianId
                || $lockedOrder->assignments()->where('assignment_role', 'technician')->where('user_id', $technicianId)->exists();
            if (! $workedOnOrder) {
                throw ValidationException::withMessages(['technician_id' => 'Teknisi tidak terdaftar pada pesanan ini.']);
            }
            $review = $lockedOrder->technicianReviews()->where('technician_id', $technicianId)->lockForUpdate()->first();
            $fields = ['rating', 'quality_rating', 'communication_rating', 'punctuality_rating', 'tags', 'comment'];
            $before = $review?->only($fields);
            $reviewData = [...Arr::only($data, $fields), 'customer_id' => $customer->id];
            if ($review === null) {
                $review = $lockedOrder->technicianReviews()->create([...$reviewData, 'technician_id' => $technicianId]);
            } else {
                $review->update($reviewData);
            }
            DB::table('audit_logs')->insert([
                'actor_id' => $customer->id,
                'event' => $before === null ? 'technician_review.created' : 'technician_review.updated',
                'auditable_type' => TechnicianReview::class,
                'auditable_id' => (string) $review->id,
                'before' => $before === null ? null : json_encode($before),
                'after' => json_encode($review->only(['service_order_id', 'technician_id', ...$fields])),
                'ip_address' => $ipAddress,
                'created_at' => now(),
            ]);

            return $review;
        });
    }
}
