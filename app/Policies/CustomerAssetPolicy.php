<?php

namespace App\Policies;

use App\Models\CustomerAsset;
use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;

class CustomerAssetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active
            && in_array($user->role, ['operator', 'admin', 'supervisor', 'technician', 'customer'], true);
    }

    public function view(User $user, CustomerAsset $asset): Response
    {
        if (! $user->is_active) {
            return Response::denyAsNotFound();
        }

        if (in_array($user->role, ['operator', 'admin', 'supervisor'], true)) {
            return Response::allow();
        }

        if ($user->role === 'customer' && $asset->customer_id === $user->id) {
            return Response::allow();
        }

        if ($user->role === 'technician') {
            $assigned = $asset->orders()
                ->where(function (Builder $query) use ($user): void {
                    $query
                        ->where('assigned_technician_id', $user->id)
                        ->orWhereHas('activeTechnicianAssignments', fn (Builder $assignments) => $assignments->where('user_id', $user->id));
                })
                ->exists();

            if ($assigned) {
                return Response::allow();
            }
        }

        return Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return $user->is_active
            && in_array($user->role, ['operator', 'admin', 'supervisor', 'customer'], true);
    }

    public function update(User $user, CustomerAsset $asset): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if (in_array($user->role, ['operator', 'admin', 'supervisor'], true)) {
            return true;
        }

        return $user->role === 'customer' && $asset->customer_id === $user->id;
    }

    public function linkOrder(User $user, ServiceOrder $order): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if (in_array($user->role, ['operator', 'admin', 'supervisor'], true)) {
            return true;
        }

        if ($user->role === 'customer') {
            return $order->customer_id === $user->id;
        }

        if ($user->role === 'technician') {
            return $order->assigned_technician_id === $user->id
                || $order->activeTechnicianAssignments()->where('user_id', $user->id)->exists();
        }

        return false;
    }
}
