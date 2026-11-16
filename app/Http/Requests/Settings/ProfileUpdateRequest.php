<?php

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileValidationRules;
use App\Models\LoginOtpChallenge;
use App\Services\FonnteMessageSender;
use App\Services\Otp\OtpConfig;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Validator;

class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        if ($this->exists('username')) {
            $username = Str::of((string) $this->input('username'))
                ->trim()
                ->ltrim('@')
                ->lower()
                ->toString();
            $normalized['username'] = $username !== '' ? $username : null;
        }

        if ($this->exists('phone')) {
            $phone = preg_replace('/\D+/', '', (string) $this->input('phone'));
            $normalized['phone'] = str_starts_with($phone, '0')
                ? '62'.substr($phone, 1)
                : ($phone !== '' ? $phone : null);
        }

        foreach (['show_phone_publicly', 'remove_avatar'] as $booleanField) {
            if ($this->exists($booleanField)) {
                $normalized[$booleanField] = $this->boolean($booleanField);
            }
        }

        $this->merge($normalized);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->profileRules($this->user()->id),
            'username' => [
                'nullable',
                'string',
                'min:3',
                'max:30',
                'regex:/^[a-z0-9](?:[a-z0-9._]*[a-z0-9])?$/',
                Rule::unique('users', 'username')->ignore($this->user()->id),
            ],
            'phone' => ['nullable', 'regex:/^62[0-9]{8,13}$/'],
            'address' => ['nullable', 'string', 'max:1000'],
            'bio' => ['nullable', 'string', 'max:500'],
            'specialty' => [
                Rule::prohibitedIf($this->user()->role !== 'technician'),
                'nullable',
                'string',
                'max:160',
            ],
            'show_phone_publicly' => ['sometimes', 'boolean'],
            'avatar' => [
                'nullable',
                File::image()->types(['jpg', 'jpeg', 'png', 'webp'])->max(2 * 1024),
                'dimensions:min_width=128,min_height=128,max_width=4000,max_height=4000',
            ],
            'remove_avatar' => ['sometimes', 'boolean'],
            'otp_challenge_id' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('phone')) {
                return;
            }

            $user = $this->user();
            if ($user === null) {
                return;
            }

            $newPhone = $this->input('phone');
            if (! is_string($newPhone) || $newPhone === '' || $newPhone === (string) $user->phone) {
                return;
            }

            if (! OtpConfig::isPurposeEnabled('phone_change') || ! app(FonnteMessageSender::class)->isConfigured()) {
                return;
            }

            $challengeId = trim((string) $this->input('otp_challenge_id', ''));
            $verified = LoginOtpChallenge::query()
                ->where('purpose', 'phone_change')
                ->where('target_phone', $newPhone)
                ->where('status', 'verified')
                ->where('verified_at', '>', now()->subMinutes(30))
                ->where(function ($query) use ($user, $challengeId): void {
                    if ($challengeId !== '') {
                        $query->where('challenge_id', $challengeId);
                    } else {
                        $query->where('user_id', $user->id);
                    }
                })
                ->exists();

            if (! $verified) {
                $validator->errors()->add(
                    'phone',
                    'Verifikasi OTP WhatsApp diperlukan sebelum mengganti nomor HP.',
                );
            }
        });
    }

    public function avatar(): ?UploadedFile
    {
        return $this->file('avatar');
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'username.regex' => 'Username hanya boleh memakai huruf kecil, angka, titik, dan garis bawah.',
            'phone.regex' => 'Gunakan nomor Indonesia aktif, misalnya 081234567890.',
            'avatar.dimensions' => 'Foto profil minimal 128×128 piksel dan maksimal 4000×4000 piksel.',
        ];
    }
}
