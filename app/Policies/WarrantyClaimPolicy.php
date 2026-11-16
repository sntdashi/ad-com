<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Warranty;
use App\Models\WarrantyClaim;

class WarrantyClaimPolicy
{
    public function submit(User $user, Warranty $warranty): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if (in_array($user->role, ['operator', 'admin', 'supervisor'], true)) {
            return true;
        }

        if ($user->role === 'customer') {
            $ownerId = $warranty->customer_id ?? $warranty->serviceOrder->customer_id;

            return $ownerId === $user->id;
        }

        return false;
    }

    public function review(User $user, WarrantyClaim $claim): bool
    {
        return $user->is_active
            && in_array($user->role, ['operator', 'admin', 'supervisor'], true)
            && in_array($claim->status, ['submitted', 'under_review'], true);
    }

    public function resolve(User $user, WarrantyClaim $claim): bool
    {
        return $user->is_active
            && in_array($user->role, ['operator', 'admin', 'supervisor'], true)
            && $claim->status === 'approved';
    }
}
