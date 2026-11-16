<?php

namespace App\Http\Requests\Settings;

use App\Concerns\PasswordValidationRules;
use App\Services\Otp\OtpConfig;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PasswordUpdateRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $otpEnabled = OtpConfig::isPurposeEnabled('password_change');
        $otpLength = OtpConfig::get()['length'];

        return [
            'current_password' => $this->currentPasswordRules(),
            'password' => $this->passwordRules(),
            'otp' => $otpEnabled ? ['required', "digits:{$otpLength}"] : ['nullable', 'string'],
        ];
    }
}
