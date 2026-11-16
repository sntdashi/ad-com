<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Warranty;
use Illuminate\Auth\Access\Response;

class WarrantyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active
            && in_array($user->role, ['operator', 'admin', 'supervisor'], true);
    }

    public function view(User $user, Warranty $warranty): Response
    {
        if (! $user->is_active) {
            return Response::denyAsNotFound();
        }

        if (in_array($user->role, ['operator', 'admin', 'supervisor'], true)) {
            return Response::allow();
        }

        if ($user->role === 'customer') {
            $ownerId = $warranty->customer_id ?? $warranty->serviceOrder->customer_id;

            return $ownerId === $user->id
                ? Response::allow()
                : Response::denyAsNotFound();
        }

        if ($user->role === 'technician') {
            $order = $warranty->serviceOrder;
            $assigned = $order->assigned_technician_id === $user->id
                || $order->activeTechnicianAssignments()->where('user_id', $user->id)->exists();

            return $assigned ? Response::allow() : Response::denyAsNotFound();
        }

        return Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return $user->is_active
            && in_array($user->role, ['operator', 'admin', 'supervisor'], true);
    }

    public function void(User $user, Warranty $warranty): bool
    {
        return $user->is_active
            && in_array($user->role, ['operator', 'admin', 'supervisor'], true)
            && $warranty->resolvedStatus() !== 'void';
    }
}
