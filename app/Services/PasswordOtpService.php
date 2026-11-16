<?php

namespace App\Services;

use App\Models\LoginOtpChallenge;
use App\Models\User;
use App\Services\Otp\OtpConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PasswordOtpService
{
    public function issue(User $user, string $purpose): bool
    {
        if (! in_array($purpose, ['password_reset', 'password_change'], true)
            || ! $user->phone
            || ! OtpConfig::isPurposeEnabled($purpose)) {
            return false;
        }

        try {
            $this->issueChallenge($user, $purpose, (string) $user->phone, request()->ip());

            return true;
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            if (isset($errors['provider'])) {
                return false;
            }

            throw $exception;
        }
    }

    /**
     * Issue a new OTP challenge supporting both authenticated users and pre-registration (user = null).
     *
     * @return array{
     *     challenge_id: string,
     *     purpose: string,
     *     length: int,
     *     expires_at: string,
     *     resend_available_at: string,
     *     attempts_remaining: int,
     *     max_attempts: int,
     *     resends_remaining: int,
     *     max_resends: int
     * }
     */
    public function issueChallenge(?User $user, string $purpose, ?string $targetPhone = null, ?string $ipAddress = null): array
    {
        if (! in_array($purpose, OtpConfig::PURPOSES, true)) {
            throw ValidationException::withMessages([
                'purpose' => 'Tujuan verifikasi OTP tidak valid.',
            ]);
        }

        $config = OtpConfig::get();

        if (! ($config['purposes'][$purpose] ?? false)) {
            throw ValidationException::withMessages([
                'otp' => 'Fitur OTP untuk tindakan ini sedang dinonaktifkan oleh Superadmin.',
            ]);
        }

        $rawPhone = $targetPhone ?? ($user !== null ? (string) $user->phone : '');
        $normalizedPhone = $this->normalizePhone($rawPhone);

        if ($normalizedPhone === null) {
            throw ValidationException::withMessages([
                'phone' => 'Nomor WhatsApp tidak valid (gunakan 10–15 digit angka).',
            ]);
        }

        $this->enforceRateLimit($normalizedPhone, $purpose, $ipAddress, $config['rate_limit_max']);

        $activeLockout = LoginOtpChallenge::query()
            ->where('purpose', $purpose)
            ->where(function ($query) use ($user, $normalizedPhone): void {
                if ($user !== null) {
                    $query->where('user_id', $user->id);
                } else {
                    $query->where('target_phone', $normalizedPhone);
                }
            })
            ->where('locked_until', '>', now())
            ->latest('id')
            ->first();

        if ($activeLockout !== null) {
            $seconds = max(1, (int) now()->diffInSeconds($activeLockout->locked_until));
            throw ValidationException::withMessages([
                'otp' => 'Terlalu banyak percobaan salah. Coba lagi setelah '.$this->formatDuration($seconds).'.',
            ]);
        }

        $recentChallenge = LoginOtpChallenge::query()
            ->where('purpose', $purpose)
            ->where('status', 'pending')
            ->where(function ($query) use ($user, $normalizedPhone): void {
                if ($user !== null) {
                    $query->where('user_id', $user->id);
                } else {
                    $query->where('target_phone', $normalizedPhone);
                }
            })
            ->where('resend_available_at', '>', now())
            ->latest('id')
            ->first();

        if ($recentChallenge !== null) {
            $wait = max(1, (int) now()->diffInSeconds($recentChallenge->resend_available_at));
            throw ValidationException::withMessages([
                'otp' => "Tunggu minimal {$wait} detik sebelum meminta kode OTP baru.",
            ]);
        }

        LoginOtpChallenge::query()
            ->where('purpose', $purpose)
            ->where('status', 'pending')
            ->where(function ($query) use ($user, $normalizedPhone): void {
                if ($user !== null) {
                    $query->where('user_id', $user->id);
                } else {
                    $query->where('target_phone', $normalizedPhone);
                }
            })
            ->update(['status' => 'superseded']);

        $code = $this->generateCode($config['length']);
        $expiresAt = now()->addMinutes($config['expiration_minutes']);
        $resendAvailableAt = now()->addSeconds($config['resend_cooldown_seconds']);
        $challengeId = (string) Str::uuid();

        $challenge = LoginOtpChallenge::query()->create([
            'challenge_id' => $challengeId,
            'user_id' => $user?->id,
            'purpose' => $purpose,
            'target_phone' => $normalizedPhone,
            'code_hash' => Hash::make($code),
            'security_fingerprint' => $user !== null
                ? $user->mobileSecurityFingerprint()
                : hash('sha256', $purpose.'|'.$normalizedPhone),
            'attempts' => 0,
            'resend_count' => 0,
            'expires_at' => $expiresAt,
            'resend_available_at' => $resendAvailableAt,
            'status' => 'pending',
        ]);

        if (! app(FonnteMessageSender::class)->sendPasswordCode($normalizedPhone, $code)) {
            $challenge->delete();

            throw ValidationException::withMessages([
                'provider' => 'Pengiriman OTP via WhatsApp gagal. Periksa konfigurasi Fonnte atau coba beberapa saat lagi.',
            ]);
        }

        return $this->challengePayload($challenge, $config);
    }

    /**
     * Resend an existing OTP challenge: checks cooldown, max resends, generates new code, invalidates old code.
     *
     * @return array{
     *     challenge_id: string,
     *     purpose: string,
     *     length: int,
     *     expires_at: string,
     *     resend_available_at: string,
     *     attempts_remaining: int,
     *     max_attempts: int,
     *     resends_remaining: int,
     *     max_resends: int
     * }
     */
    public function resendChallenge(string $challengeId, ?User $user = null, ?string $ipAddress = null): array
    {
        $config = OtpConfig::get();

        /** @var LoginOtpChallenge $challenge */
        $challenge = DB::transaction(function () use ($challengeId, $user, $ipAddress, $config): LoginOtpChallenge {
            $locked = LoginOtpChallenge::query()
                ->where('challenge_id', $challengeId)
                ->lockForUpdate()
                ->first();

            if ($locked === null || ($user !== null && $locked->user_id !== null && $locked->user_id !== $user->id)) {
                throw ValidationException::withMessages([
                    'otp' => 'Sesi verifikasi OTP tidak ditemukan.',
                ]);
            }

            if ($locked->status === 'verified') {
                throw ValidationException::withMessages([
                    'otp' => 'Kode OTP sudah digunakan. Buat permintaan verifikasi baru.',
                ]);
            }

            if ($locked->locked_until !== null && $locked->locked_until->isFuture()) {
                throw ValidationException::withMessages([
                    'otp' => 'Permintaan verifikasi sedang dikunci sementara karena batas percobaan tercapai.',
                ]);
            }

            if ($locked->resend_available_at !== null && $locked->resend_available_at->isFuture()) {
                $wait = max(1, (int) now()->diffInSeconds($locked->resend_available_at));
                throw ValidationException::withMessages([
                    'otp' => "Tunggu {$wait} detik sebelum mengirim ulang kode OTP.",
                ]);
            }

            if ($locked->resend_count >= $config['max_resends']) {
                throw ValidationException::withMessages([
                    'otp' => 'Batas maksimal kirim ulang OTP telah tercapai. Silakan mulai ulang proses.',
                ]);
            }

            $phone = (string) ($locked->target_phone ?? ($user !== null ? $user->phone : ''));
            $this->enforceRateLimit($phone, $locked->purpose, $ipAddress, $config['rate_limit_max']);

            $code = $this->generateCode($config['length']);

            if (! app(FonnteMessageSender::class)->sendPasswordCode($phone, $code)) {
                throw ValidationException::withMessages([
                    'provider' => 'Pengiriman ulang OTP gagal. Kode sebelumnya tidak diubah.',
                ]);
            }

            $locked->forceFill([
                'code_hash' => Hash::make($code),
                'attempts' => 0,
                'resend_count' => $locked->resend_count + 1,
                'expires_at' => now()->addMinutes($config['expiration_minutes']),
                'resend_available_at' => now()->addSeconds($config['resend_cooldown_seconds']),
                'status' => 'pending',
                'locked_until' => null,
            ])->save();

            return $locked;
        });

        return $this->challengePayload($challenge, $config);
    }

    public function verify(User $user, string $purpose, string $code): void
    {
        $config = OtpConfig::get();

        $outcome = DB::transaction(function () use ($user, $purpose, $code, $config): string {
            $challenge = LoginOtpChallenge::query()
                ->where('user_id', $user->id)
                ->where('purpose', $purpose)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if ($challenge === null) {
                return 'missing';
            }

            return $this->evaluateChallengeAttempt($challenge, $purpose, $code, $user->mobileSecurityFingerprint(), $config);
        });

        $this->throwIfVerificationFailed($outcome);
    }

    public function verifyChallenge(string $challengeId, string $purpose, string $code, ?User $user = null): LoginOtpChallenge
    {
        $config = OtpConfig::get();

        /** @var array{outcome: string, challenge: LoginOtpChallenge|null} $result */
        $result = DB::transaction(function () use ($challengeId, $purpose, $code, $user, $config): array {
            $challenge = LoginOtpChallenge::query()
                ->where('challenge_id', $challengeId)
                ->lockForUpdate()
                ->first();

            if ($challenge === null) {
                return ['outcome' => 'missing', 'challenge' => null];
            }

            if ($user !== null && $challenge->user_id !== null && $challenge->user_id !== $user->id) {
                return ['outcome' => 'missing', 'challenge' => null];
            }

            $expectedFingerprint = $user !== null && $challenge->user_id !== null
                ? $user->mobileSecurityFingerprint()
                : hash('sha256', $purpose.'|'.(string) $challenge->target_phone);

            $outcome = $this->evaluateChallengeAttempt($challenge, $purpose, $code, $expectedFingerprint, $config);

            return ['outcome' => $outcome, 'challenge' => $challenge];
        });

        $this->throwIfVerificationFailed($result['outcome']);

        /** @var LoginOtpChallenge $verified */
        $verified = $result['challenge'];

        return $verified;
    }

    /**
     * @param  array{max_attempts: int, lockout_minutes: int}  $config
     */
    private function evaluateChallengeAttempt(
        LoginOtpChallenge $challenge,
        string $purpose,
        string $code,
        string $expectedFingerprint,
        array $config,
    ): string {
        if ($challenge->purpose !== $purpose) {
            return 'purpose_mismatch';
        }

        if ($challenge->status === 'verified') {
            return 'reused';
        }

        if ($challenge->locked_until !== null && $challenge->locked_until->isFuture()) {
            return 'locked';
        }

        if ($challenge->status !== 'pending') {
            return 'invalid';
        }

        if ($challenge->expires_at->isPast()) {
            $challenge->forceFill(['status' => 'expired'])->save();

            return 'expired';
        }

        if ($challenge->attempts >= $config['max_attempts']) {
            $challenge->forceFill([
                'status' => 'locked',
                'locked_until' => now()->addMinutes($config['lockout_minutes']),
            ])->save();

            return 'locked';
        }

        if (! hash_equals($challenge->security_fingerprint, $expectedFingerprint)) {
            return 'invalid';
        }

        if (! Hash::check($code, $challenge->code_hash)) {
            $nextAttempts = $challenge->attempts + 1;
            $updates = ['attempts' => $nextAttempts];

            if ($nextAttempts >= $config['max_attempts']) {
                $updates['status'] = 'locked';
                $updates['locked_until'] = now()->addMinutes($config['lockout_minutes']);
            }

            $challenge->forceFill($updates)->save();

            return $nextAttempts >= $config['max_attempts'] ? 'locked' : 'wrong_code';
        }

        $challenge->forceFill([
            'status' => 'verified',
            'verified_at' => now(),
        ])->save();

        return 'ok';
    }

    private function throwIfVerificationFailed(string $outcome): void
    {
        if ($outcome === 'ok') {
            return;
        }

        $message = match ($outcome) {
            'expired' => 'Kode OTP sudah kedaluwarsa. Silakan kirim ulang kode baru.',
            'locked' => 'Verifikasi dikunci sementara karena melebihi batas percobaan.',
            'reused' => 'Kode OTP sudah pernah digunakan. Silakan minta kode baru.',
            'purpose_mismatch' => 'Kode OTP tidak sesuai dengan tujuan verifikasi ini.',
            default => 'Kode OTP tidak valid atau sudah kedaluwarsa. Kirim kode baru.',
        };

        throw ValidationException::withMessages(['otp' => $message]);
    }

    private function enforceRateLimit(string $normalizedPhone, string $purpose, ?string $ipAddress, int $maxPer15Min): void
    {
        $decaySeconds = 15 * 60;
        $targetKey = 'otp-target:'.$purpose.':'.hash('sha256', $normalizedPhone);
        $ipKey = 'otp-ip:'.($ipAddress ?? 'unknown');

        if (RateLimiter::tooManyAttempts($targetKey, $maxPer15Min) || RateLimiter::tooManyAttempts($ipKey, $maxPer15Min * 3)) {
            throw ValidationException::withMessages([
                'otp' => 'Terlalu banyak permintaan OTP. Silakan coba lagi dalam 15 menit.',
            ]);
        }

        RateLimiter::hit($targetKey, $decaySeconds);
        RateLimiter::hit($ipKey, $decaySeconds);
    }

    private function normalizePhone(string $rawPhone): ?string
    {
        $digits = preg_replace('/\D+/', '', $rawPhone) ?? '';
        if (str_starts_with($digits, '0')) {
            $digits = '62'.substr($digits, 1);
        }

        if ($digits === '' || strlen($digits) < 10 || strlen($digits) > 15) {
            return null;
        }

        return $digits;
    }

    private function generateCode(int $length): string
    {
        $max = (10 ** $length) - 1;

        return str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds} detik";
        }

        $minutes = (int) ceil($seconds / 60);

        return "{$minutes} menit";
    }

    /**
     * @param  array{length: int, max_attempts: int, max_resends: int}  $config
     * @return array{
     *     challenge_id: string,
     *     purpose: string,
     *     length: int,
     *     expires_at: string,
     *     resend_available_at: string,
     *     attempts_remaining: int,
     *     max_attempts: int,
     *     resends_remaining: int,
     *     max_resends: int
     * }
     */
    private function challengePayload(LoginOtpChallenge $challenge, array $config): array
    {
        return [
            'challenge_id' => (string) $challenge->challenge_id,
            'purpose' => $challenge->purpose,
            'length' => $config['length'],
            'expires_at' => $challenge->expires_at->toIso8601String(),
            'resend_available_at' => ($challenge->resend_available_at ?? now())->toIso8601String(),
            'attempts_remaining' => max(0, $config['max_attempts'] - $challenge->attempts),
            'max_attempts' => $config['max_attempts'],
            'resends_remaining' => max(0, $config['max_resends'] - $challenge->resend_count),
            'max_resends' => $config['max_resends'],
        ];
    }
}
