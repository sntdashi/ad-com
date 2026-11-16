<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication as FortifyDisableTwoFactorAuthentication;

class DisableTwoFactorAuthentication extends FortifyDisableTwoFactorAuthentication
{
    /**
     * @param  mixed  $user
     */
    public function __invoke($user): void
    {
        if ($user instanceof User && $user->role !== 'customer' && $user->hasEnabledTwoFactorAuthentication()) {
            throw ValidationException::withMessages([
                'two_factor' => 'Kode OTP wajib aktif untuk seluruh akun tim AD Com.',
            ]);
        }

        parent::__invoke($user);
    }
}
