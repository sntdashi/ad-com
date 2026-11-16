<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\LoginOtpChallenge;
use App\Models\User;
use App\Services\Otp\OtpConfig;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        $phone = preg_replace('/\D+/', '', (string) ($input['phone'] ?? ''));

        if (str_starts_with((string) $phone, '0')) {
            $phone = '62'.substr((string) $phone, 1);
        }

        $input['phone'] = $phone;
        Validator::make($input, [
            ...$this->profileRules(),
            'phone' => ['required', 'regex:/^62[0-9]{8,13}$/'],
            'password' => $this->passwordRules(),
        ])->validate();

        if (OtpConfig::isPurposeEnabled('registration_phone')) {
            $challengeId = (string) ($input['otp_challenge_id'] ?? '');
            $verified = $challengeId !== '' && LoginOtpChallenge::query()
                ->where('challenge_id', $challengeId)
                ->where('purpose', 'registration_phone')
                ->where('target_phone', $phone)
                ->where('status', 'verified')
                ->where('verified_at', '>', now()->subMinutes(30))
                ->exists();

            if (! $verified) {
                throw ValidationException::withMessages([
                    'otp' => 'Verifikasi OTP WhatsApp diperlukan sebelum pendaftaran diselesaikan.',
                ]);
            }
        }

        return User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'phone' => $phone,
            'password' => $input['password'],
        ]);
    }
}
