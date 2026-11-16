<?php

namespace App\Services\Otp;

use App\Models\SystemSetting;

class OtpConfig
{
    /** @var list<string> */
    public const PURPOSES = [
        'registration_phone',
        'password_reset',
        'password_change',
        'sensitive_action',
        'phone_change',
    ];

    /**
     * @return array{
     *     length: int,
     *     expiration_minutes: int,
     *     resend_cooldown_seconds: int,
     *     max_attempts: int,
     *     max_resends: int,
     *     lockout_minutes: int,
     *     rate_limit_max: int,
     *     message_template: string,
     *     purposes: array<string, bool>
     * }
     */
    public static function get(): array
    {
        $raw = SystemSetting::getMany([
            'otp_length' => '6',
            'otp_expiration_minutes' => '5',
            'otp_resend_cooldown_seconds' => '60',
            'otp_max_attempts' => '5',
            'otp_max_resends' => '3',
            'otp_lockout_minutes' => '15',
            'otp_rate_limit_max' => '5',
            'otp_message_template' => 'Kode verifikasi AD Com kamu: {code}. Berlaku {minutes} menit. Jangan bagikan kode ini ke siapa pun.',
            'registration_otp_enabled' => '0',
            'otp_purpose_registration_phone_enabled' => '0',
            'otp_purpose_password_reset_enabled' => '1',
            'otp_purpose_password_change_enabled' => '1',
            'otp_purpose_sensitive_action_enabled' => '1',
            'otp_purpose_phone_change_enabled' => '1',
        ]);

        return [
            'length' => max(4, min(8, (int) $raw['otp_length'])),
            'expiration_minutes' => max(1, min(30, (int) $raw['otp_expiration_minutes'])),
            'resend_cooldown_seconds' => max(10, min(300, (int) $raw['otp_resend_cooldown_seconds'])),
            'max_attempts' => max(3, min(10, (int) $raw['otp_max_attempts'])),
            'max_resends' => max(1, min(10, (int) $raw['otp_max_resends'])),
            'lockout_minutes' => max(1, min(120, (int) $raw['otp_lockout_minutes'])),
            'rate_limit_max' => max(1, min(30, (int) $raw['otp_rate_limit_max'])),
            'message_template' => $raw['otp_message_template'],
            'purposes' => [
                'registration_phone' => $raw['otp_purpose_registration_phone_enabled'] === '1'
                    || $raw['registration_otp_enabled'] === '1',
                'password_reset' => $raw['otp_purpose_password_reset_enabled'] === '1',
                'password_change' => $raw['otp_purpose_password_change_enabled'] === '1',
                'sensitive_action' => $raw['otp_purpose_sensitive_action_enabled'] === '1',
                'phone_change' => $raw['otp_purpose_phone_change_enabled'] === '1',
            ],
        ];
    }

    public static function isPurposeEnabled(string $purpose): bool
    {
        return self::get()['purposes'][$purpose] ?? false;
    }
}
