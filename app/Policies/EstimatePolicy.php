<?php

namespace App\Policies;

use App\Models\Estimate;
use App\Models\User;

class EstimatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && in_array($user->role, ['operator', 'admin', 'supervisor', 'customer'], true);
    }

    public function view(User $user, Estimate $estimate): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if (in_array($user->role, ['operator', 'admin', 'supervisor'], true)) {
            return true;
        }

        if ($user->role === 'customer') {
            return $this->customerOwnsEstimate($user, $estimate);
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->is_active && in_array($user->role, ['operator', 'admin'], true);
    }

    public function update(User $user, Estimate $estimate): bool
    {
        return $this->create($user);
    }

    public function send(User $user, Estimate $estimate): bool
    {
        return $this->create($user);
    }

    public function approve(User $user, Estimate $estimate): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if (in_array($user->role, ['operator', 'admin'], true)) {
            return true;
        }

        return $user->role === 'customer' && $this->customerOwnsEstimate($user, $estimate);
    }

    public function reject(User $user, Estimate $estimate): bool
    {
        return $this->approve($user, $estimate);
    }

    public function convert(User $user, Estimate $estimate): bool
    {
        return $this->create($user);
    }

    private function customerOwnsEstimate(User $user, Estimate $estimate): bool
    {
        if ($estimate->customer_id !== null && (int) $estimate->customer_id === (int) $user->id) {
            return true;
        }

        return (int) $estimate->order->customer_id === (int) $user->id;
    }
}
