<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && in_array($user->role, ['operator', 'admin', 'supervisor', 'customer'], true);
    }

    public function view(User $user, Invoice $invoice): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if (in_array($user->role, ['operator', 'admin', 'supervisor'], true)) {
            return true;
        }

        if ($user->role === 'customer') {
            return $invoice->status !== 'draft' && $this->customerOwnsInvoice($user, $invoice);
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->is_active && in_array($user->role, ['operator', 'admin'], true);
    }

    public function issue(User $user, Invoice $invoice): bool
    {
        return $this->create($user);
    }

    public function recordPayment(User $user, Invoice $invoice): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($this->create($user)) {
            return true;
        }

        return $user->role === 'customer'
            && ! in_array($invoice->status, ['draft', 'cancelled'], true)
            && $this->customerOwnsInvoice($user, $invoice);
    }

    public function cancel(User $user, Invoice $invoice): bool
    {
        return $this->create($user);
    }

    private function customerOwnsInvoice(User $user, Invoice $invoice): bool
    {
        if ($invoice->customer_id !== null && (int) $invoice->customer_id === (int) $user->id) {
            return true;
        }

        return $invoice->order !== null && (int) $invoice->order->customer_id === (int) $user->id;
    }
}
