<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use App\Services\Otp\OtpConfig;
use App\Services\PasswordOtpService;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    public function __construct(private PasswordOtpService $passwordOtp) {}

    /**
     * Validate and reset the user's forgotten password.
     *
     * @param  array<string, string>  $input
     */
    public function reset(User $user, array $input): void
    {
        $otpEnabled = OtpConfig::isPurposeEnabled('password_reset');
        $otpLength = OtpConfig::get()['length'];

        Validator::make($input, [
            'password' => $this->passwordRules(),
            'otp' => $otpEnabled ? ['required', "digits:{$otpLength}"] : ['nullable', 'string'],
        ])->validate();

        if ($otpEnabled) {
            $this->passwordOtp->verify($user, 'password_reset', (string) ($input['otp'] ?? ''));
        }

        $user->forceFill([
            'password' => $input['password'],
        ])->save();
    }
}
