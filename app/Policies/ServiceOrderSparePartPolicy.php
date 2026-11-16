<?php

namespace App\Policies;

use App\Models\ServiceOrder;
use App\Models\ServiceOrderSparePart;
use App\Models\User;

class ServiceOrderSparePartPolicy
{
    public function requestForOrder(User $user, ServiceOrder $order): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if (in_array($user->role, ['operator', 'admin', 'supervisor', 'warehouse'], true)) {
            return true;
        }

        if ($user->role !== 'technician') {
            return false;
        }

        return $order->assigned_technician_id === $user->id
            || $order->activeTechnicianAssignments()->where('user_id', $user->id)->exists();
    }

    public function issue(User $user, ServiceOrderSparePart $sparePart): bool
    {
        return $user->is_active
            && in_array($user->role, ['operator', 'admin', 'warehouse'], true)
            && $sparePart->status === 'requested';
    }

    public function returnPart(User $user, ServiceOrderSparePart $sparePart): bool
    {
        return $user->is_active
            && in_array($user->role, ['operator', 'admin', 'warehouse'], true)
            && in_array($sparePart->status, ['issued', 'partially_returned'], true)
            && $sparePart->netIssuedQuantity() > 0;
    }

    public function cancel(User $user, ServiceOrderSparePart $sparePart): bool
    {
        if (! $user->is_active || $sparePart->status !== 'requested') {
            return false;
        }

        if (in_array($user->role, ['operator', 'admin', 'warehouse'], true)) {
            return true;
        }

        return $sparePart->requested_by === $user->id
            && $this->requestForOrder($user, $sparePart->order);
    }
}
