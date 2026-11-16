<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class FonnteMessageSender
{
    public function resolvedToken(): string
    {
        $stored = SystemSetting::getEncrypted('secret:fonnte_token');

        if (is_string($stored) && $stored !== '') {
            return $stored;
        }

        $envToken = config('services.fonnte.token');

        return is_string($envToken) ? trim($envToken) : '';
    }

    public function isConfigured(): bool
    {
        return $this->resolvedToken() !== '';
    }

    public function sendPasswordCode(string $phone, string $code): bool
    {
        $settings = SystemSetting::getMany([
            'otp_expiration_minutes' => '5',
            'otp_message_template' => 'Kode verifikasi AD Com kamu: {code}. Berlaku {minutes} menit. Jangan bagikan kode ini ke siapa pun.',
        ]);

        $template = $settings['otp_message_template'] !== ''
            ? $settings['otp_message_template']
            : 'Kode verifikasi AD Com kamu: {code}. Berlaku {minutes} menit. Jangan bagikan kode ini ke siapa pun.';

        $message = str_replace(
            ['{code}', '{minutes}'],
            [$code, $settings['otp_expiration_minutes']],
            $template,
        );

        return $this->sendCode($phone, $message);
    }

    private function sendCode(string $phone, string $message): bool
    {
        $token = $this->resolvedToken();

        if ($token === '') {
            return false;
        }

        try {
            $response = Http::asForm()
                ->withHeaders(['Authorization' => $token])
                ->connectTimeout(3)
                ->timeout(8)
                ->post('https://api.fonnte.com/send', [
                    'target' => $phone,
                    'message' => $message,
                ]);
        } catch (ConnectionException) {
            return false;
        }

        return $response->successful() && $response->json('status') === true;
    }

    /**
     * Send an operational notification message via Fonnte without throwing on missing token.
     *
     * @return array{sent: bool, status: string, error: string|null}
     */
    public function sendOperationalMessage(string $phone, string $message): array
    {
        $token = $this->resolvedToken();

        if ($token === '') {
            return [
                'sent' => false,
                'status' => 'skipped',
                'error' => 'FONNTE_API_TOKEN belum dikonfigurasi.',
            ];
        }

        try {
            $response = Http::asForm()
                ->withHeaders(['Authorization' => $token])
                ->connectTimeout(3)
                ->timeout(8)
                ->post('https://api.fonnte.com/send', [
                    'target' => $phone,
                    'message' => $message,
                ]);
        } catch (ConnectionException $exception) {
            return [
                'sent' => false,
                'status' => 'failed',
                'error' => $this->sanitizeErrorMessage($exception->getMessage(), $token),
            ];
        }

        if (! $response->successful() || $response->json('status') === false) {
            return [
                'sent' => false,
                'status' => 'failed',
                'error' => 'HTTP '.$response->status().' dari API Fonnte.',
            ];
        }

        return [
            'sent' => true,
            'status' => 'sent',
            'error' => null,
        ];
    }

    /**
     * Sanitize upstream transport/HTTP error messages before persistence or UI rendering.
     */
    public function sanitizeErrorMessage(string $message, ?string $secret = null): string
    {
        $sanitized = trim($message);

        if ($secret !== null && $secret !== '') {
            $sanitized = str_replace($secret, '[REDACTED]', $sanitized);
        }

        // Redact Bearer/Authorization tokens and URL query secrets
        $sanitized = preg_replace('/(Authorization[:=]\s*(?:Bearer\s+)?|Bearer\s+)[A-Za-z0-9\-_.~+\/=]{6,}/i', '$1[REDACTED]', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('/([?&](?:token|key|secret|apikey|api_key|authorization)=)[^&\s]+/i', '$1[REDACTED]', $sanitized) ?? $sanitized;

        if ($sanitized === '') {
            return 'Koneksi ke gateway WhatsApp gagal.';
        }

        return mb_strimwidth($sanitized, 0, 240, '...');
    }

    /**
     * Test Fonnte API connectivity using the configured token.
     *
     * @return array{ok: bool, status: string, message: string}
     */
    public function testConnection(): array
    {
        $token = $this->resolvedToken();

        if ($token === '') {
            return [
                'ok' => false,
                'status' => 'not_configured',
                'message' => 'Token Fonnte belum dikonfigurasi.',
            ];
        }

        try {
            $response = Http::withHeaders(['Authorization' => $token])
                ->connectTimeout(4)
                ->timeout(8)
                ->post('https://api.fonnte.com/device');
        } catch (ConnectionException) {
            return [
                'ok' => false,
                'status' => 'failed',
                'message' => 'Gagal terhubung ke server Fonnte. Periksa koneksi internet server.',
            ];
        }

        if (! $response->successful() || $response->json('status') === false) {
            return [
                'ok' => false,
                'status' => 'failed',
                'message' => 'Token Fonnte ditolak atau perangkat tidak aktif (HTTP '.$response->status().').',
            ];
        }

        return [
            'ok' => true,
            'status' => 'connected',
            'message' => 'Koneksi ke Fonnte berhasil dan token aktif.',
        ];
    }
}
