<?php

namespace App\Policies;

use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ServiceOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, ServiceOrder $serviceOrder): Response
    {
        $allowed = $user->is_active && (
            in_array($user->role, ['operator', 'admin', 'supervisor'], true)
            || ($user->role === 'technician' && $this->isAssignedTechnician($user, $serviceOrder))
            || ($user->role === 'customer' && $serviceOrder->customer_id === $user->id)
        );

        return $allowed ? Response::allow() : Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return $user->is_active;
    }

    public function update(User $user, ServiceOrder $serviceOrder): bool
    {
        return $user->is_active && (
            in_array($user->role, ['operator', 'admin', 'supervisor'], true)
            || ($user->role === 'technician' && $this->isAssignedTechnician($user, $serviceOrder))
        );
    }

    public function updateTeam(User $user, ServiceOrder $serviceOrder): bool
    {
        return $user->is_active && in_array($user->role, ['operator', 'admin', 'supervisor'], true);
    }

    public function manageSla(User $user, ServiceOrder $serviceOrder): bool
    {
        return $user->is_active && in_array($user->role, ['operator', 'admin', 'supervisor'], true);
    }

    public function review(User $user, ServiceOrder $serviceOrder): bool
    {
        return $user->is_active
            && $user->role === 'customer'
            && $serviceOrder->customer_id === $user->id
            && in_array($serviceOrder->status, ['completed', 'warranty', 'closed'], true);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ServiceOrder $serviceOrder): bool
    {
        return $user->is_active && $user->role === 'operator';
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, ServiceOrder $serviceOrder): bool
    {
        return $user->is_active && $user->role === 'operator';
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, ServiceOrder $serviceOrder): bool
    {
        return false;
    }

    private function isAssignedTechnician(User $user, ServiceOrder $serviceOrder): bool
    {
        return $serviceOrder->assigned_technician_id === $user->id
            || $serviceOrder->activeTechnicianAssignments()
                ->whereBelongsTo($user)
                ->exists();
    }
}
