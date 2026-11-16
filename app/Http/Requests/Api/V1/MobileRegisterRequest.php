<?php

namespace App\Http\Requests\Api\V1;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class MobileRegisterRequest extends FormRequest
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->profileRules(),
            'phone' => ['required', 'regex:/^62[0-9]{8,13}$/'],
            'password' => $this->passwordRules(),
            'password_confirmation' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:120'],
            'role' => ['prohibited'],
            'is_active' => ['prohibited'],
            'email_verified_at' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }

        if (is_string($this->input('phone'))) {
            $phone = preg_replace('/\D+/', '', $this->input('phone'));
            $this->merge(['phone' => str_starts_with((string) $phone, '0') ? '62'.substr((string) $phone, 1) : $phone]);
        }
    }
}
