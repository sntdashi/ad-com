<?php

namespace App\Actions\Orders;

use App\Models\ServiceOrder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoadMobileOrder
{
    public function handle(ServiceOrder $order): ServiceOrder
    {
        $technician = fn (BelongsTo $query) => $query
            ->withAvg('receivedTechnicianReviews as rating_average', 'rating')
            ->withCount('receivedTechnicianReviews');

        return $order->load([
            'service',
            'assignedTechnician' => $technician,
            'activeTechnicianAssignments.user' => $technician,
            'assignments.user' => $technician,
            'statusEvents',
            'financialTransactions' => fn ($query) => $query->where('type', 'income'),
            'technicianReviews',
        ]);
    }
}
