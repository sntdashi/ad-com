<?php

namespace App\Policies;

use App\Models\TechnicianReview;
use App\Models\User;

class TechnicianReviewPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->is_active
            && in_array($user->role, ['operator', 'admin', 'supervisor', 'technician'], true);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, TechnicianReview $technicianReview): bool
    {
        return $user->is_active && (
            in_array($user->role, ['operator', 'admin', 'supervisor'], true)
            || ($user->role === 'technician' && $technicianReview->technician_id === $user->id)
        );
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->is_active && $user->role === 'customer';
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, TechnicianReview $technicianReview): bool
    {
        return $user->is_active
            && $user->role === 'customer'
            && $technicianReview->customer_id === $user->id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, TechnicianReview $technicianReview): bool
    {
        return $user->is_active && $user->role === 'operator';
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, TechnicianReview $technicianReview): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, TechnicianReview $technicianReview): bool
    {
        return false;
    }
}
