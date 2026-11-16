<?php

namespace App\Policies;

use App\Models\FinancialTransaction;
use App\Models\User;

class FinancialTransactionPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->is_active && in_array($user->role, ['operator', 'admin'], true);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, FinancialTransaction $financialTransaction): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if (in_array($user->role, ['operator', 'admin'], true)) {
            return true;
        }

        if ($user->role === 'customer' && $financialTransaction->type === 'income') {
            if ($financialTransaction->invoice !== null && (int) $financialTransaction->invoice->customer_id === (int) $user->id) {
                return true;
            }

            if ($financialTransaction->order !== null && (int) $financialTransaction->order->customer_id === (int) $user->id) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->is_active && in_array($user->role, ['operator', 'admin'], true);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, FinancialTransaction $financialTransaction): bool
    {
        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, FinancialTransaction $financialTransaction): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, FinancialTransaction $financialTransaction): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, FinancialTransaction $financialTransaction): bool
    {
        return false;
    }

    public function verify(User $user, FinancialTransaction $financialTransaction): bool
    {
        return $user->is_active
            && $user->role === 'operator'
            && $financialTransaction->status === 'pending';
    }

    public function reject(User $user, FinancialTransaction $financialTransaction): bool
    {
        return $this->verify($user, $financialTransaction);
    }
}
