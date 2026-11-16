<?php

namespace App\Services\Ai;

use App\Models\AiProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class AiProviderClient
{
    public function resolveBaseUrl(string $driver, ?string $customBaseUrl): string
    {
        return match ($driver) {
            'openai' => 'https://api.openai.com/v1',
            'openrouter' => 'https://openrouter.ai/api/v1',
            'gemini' => 'https://generativelanguage.googleapis.com/v1beta',
            'anthropic' => 'https://api.anthropic.com/v1',
            'custom_openai' => $this->validateCustomBaseUrl((string) $customBaseUrl),
            default => throw ValidationException::withMessages(['driver' => 'Driver AI provider tidak dikenal.']),
        };
    }

    public function validateCustomBaseUrl(string $url): string
    {
        $trimmed = rtrim(trim($url), '/');
        $parts = parse_url($trimmed);

        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
            throw ValidationException::withMessages([
                'base_url' => 'Custom base URL wajib menggunakan HTTPS yang valid.',
            ]);
        }

        $host = strtolower((string) $parts['host']);
        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            throw ValidationException::withMessages([
                'base_url' => 'Host internal atau lokal tidak diizinkan untuk keamanan (SSRF protection).',
            ]);
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw ValidationException::withMessages([
                    'base_url' => 'Alamat IP privat atau reserved tidak diizinkan untuk custom provider.',
                ]);
            }
        } else {
            $resolvedIps = @gethostbynamel($host);
            if (is_array($resolvedIps)) {
                foreach ($resolvedIps as $ip) {
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                        throw ValidationException::withMessages([
                            'base_url' => 'Domain mengarah ke alamat IP internal yang tidak diizinkan.',
                        ]);
                    }
                }
            }
        }

        return $trimmed;
    }

    /**
     * Test connectivity and token validity against the provider's official API.
     *
     * @return array{
     *     ok: bool,
     *     http_status: int|null,
     *     message: string
     * }
     */
    public function testConnection(AiProvider $provider): array
    {
        $token = trim((string) $provider->api_token);
        if ($token === '') {
            return [
                'ok' => false,
                'http_status' => null,
                'message' => 'API token belum dikonfigurasi untuk provider ini.',
            ];
        }

        try {
            $baseUrl = $this->resolveBaseUrl($provider->driver, $provider->base_url);
        } catch (ValidationException $exception) {
            return [
                'ok' => false,
                'http_status' => null,
                'message' => collect($exception->errors())->flatten()->first() ?? 'Base URL provider tidak valid.',
            ];
        }

        try {
            $response = $this->sendModelsProbe($provider->driver, $baseUrl, $token);
        } catch (ConnectionException $exception) {
            return [
                'ok' => false,
                'http_status' => null,
                'message' => $this->diagnoseConnectionException($exception),
            ];
        }

        $status = $response->status();

        if ($response->successful()) {
            return [
                'ok' => true,
                'http_status' => $status,
                'message' => 'Koneksi berhasil. API token valid dan endpoint provider merespons dengan normal.',
            ];
        }

        // Custom OpenAI-compatible endpoints sometimes omit /models; fallback to a minimal chat probe if model_id is known.
        if ($provider->driver === 'custom_openai' && in_array($status, [404, 405], true) && filled($provider->model_id)) {
            $manualResult = $this->validateManualModel($provider, (string) $provider->model_id);

            return [
                'ok' => $manualResult['ok'],
                'http_status' => $manualResult['ok'] ? 200 : $status,
                'message' => $manualResult['ok']
                    ? 'Koneksi berhasil melalui endpoint chat/completions custom provider.'
                    : $manualResult['message'],
            ];
        }

        return [
            'ok' => false,
            'http_status' => $status,
            'message' => $this->diagnoseHttpError($status, $response->json(), $token),
        ];
    }

    /**
     * Validate token & dynamically discover models from the official provider API.
     *
     * @return array{
     *     ok: bool,
     *     supports_listing: bool,
     *     models: list<array{id: string, name: string}>,
     *     message: string
     * }
     */
    public function discoverModels(AiProvider $provider): array
    {
        $token = trim((string) $provider->api_token);
        if ($token === '') {
            return [
                'ok' => false,
                'supports_listing' => true,
                'models' => [],
                'message' => 'API token belum dikonfigurasi untuk provider ini.',
            ];
        }

        try {
            $baseUrl = $this->resolveBaseUrl($provider->driver, $provider->base_url);
        } catch (ValidationException $exception) {
            return [
                'ok' => false,
                'supports_listing' => true,
                'models' => [],
                'message' => collect($exception->errors())->flatten()->first() ?? 'Base URL provider tidak valid.',
            ];
        }

        try {
            $response = $this->sendModelsProbe($provider->driver, $baseUrl, $token);
        } catch (ConnectionException $exception) {
            return [
                'ok' => false,
                'supports_listing' => true,
                'models' => [],
                'message' => $this->diagnoseConnectionException($exception),
            ];
        }

        $status = $response->status();

        if ($provider->driver === 'custom_openai' && in_array($status, [404, 405], true)) {
            return [
                'ok' => true,
                'supports_listing' => false,
                'models' => [],
                'message' => 'Provider custom tidak menyediakan endpoint daftar model (/models). Silakan masukkan Model ID secara manual lalu validasi.',
            ];
        }

        if (! $response->successful()) {
            return [
                'ok' => false,
                'supports_listing' => true,
                'models' => [],
                'message' => $this->diagnoseHttpError($status, $response->json(), $token),
            ];
        }

        $models = $this->normalizeModels($provider->driver, (array) $response->json());

        return [
            'ok' => true,
            'supports_listing' => true,
            'models' => $models,
            'message' => count($models) > 0
                ? 'Koneksi berhasil dan '.count($models).' model berhasil ditemukan.'
                : 'Koneksi berhasil, tetapi daftar model kosong. Anda dapat memasukkan Model ID manual.',
        ];
    }

    /**
     * Validate a manually entered model ID by issuing a minimal completion probe.
     *
     * @return array{ok: bool, message: string}
     */
    public function validateManualModel(AiProvider $provider, string $modelId): array
    {
        $token = trim((string) $provider->api_token);
        if ($token === '') {
            return ['ok' => false, 'message' => 'API token belum dikonfigurasi.'];
        }

        $baseUrl = $this->resolveBaseUrl($provider->driver, $provider->base_url);
        $resolvedModelId = $this->normalizeModelIdForEndpoint($provider->driver, $baseUrl, $modelId);

        try {
            if ($provider->driver === 'gemini') {
                $response = Http::baseUrl($baseUrl)
                    ->withHeaders(['x-goog-api-key' => $token])
                    ->acceptJson()
                    ->connectTimeout(4)
                    ->timeout(10)
                    ->post('/models/'.rawurlencode($resolvedModelId).':generateContent', [
                        'contents' => [['role' => 'user', 'parts' => [['text' => 'ping']]]],
                        'generationConfig' => ['maxOutputTokens' => 8],
                    ]);
            } elseif ($provider->driver === 'anthropic') {
                $response = Http::baseUrl($baseUrl)
                    ->withHeaders([
                        'x-api-key' => $token,
                        'anthropic-version' => '2023-06-01',
                    ])
                    ->acceptJson()
                    ->connectTimeout(4)
                    ->timeout(10)
                    ->post('/messages', [
                        'model' => $resolvedModelId,
                        'max_tokens' => 8,
                        'messages' => [['role' => 'user', 'content' => 'ping']],
                    ]);
            } else {
                $response = Http::baseUrl($baseUrl)
                    ->withToken($token)
                    ->acceptJson()
                    ->connectTimeout(4)
                    ->timeout(10)
                    ->post('/chat/completions', [
                        'model' => $resolvedModelId,
                        'max_tokens' => 8,
                        'messages' => [['role' => 'user', 'content' => 'ping']],
                    ]);
            }
        } catch (ConnectionException $exception) {
            return ['ok' => false, 'message' => $this->diagnoseConnectionException($exception)];
        }

        if (! $response->successful()) {
            return [
                'ok' => false,
                'message' => $this->diagnoseHttpError($response->status(), $response->json(), $token, $modelId),
            ];
        }

        return ['ok' => true, 'message' => "Model {$modelId} valid dan siap digunakan oleh ADI."];
    }

    /**
     * Generate a reply using the active AiProvider without leaking secrets on failure.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    public function generateReply(
        AiProvider $provider,
        string $instructions,
        array $messages,
        string $userMessage,
        int $maxTokens,
    ): ?string {
        $token = trim((string) $provider->api_token);
        $modelId = trim((string) $provider->model_id);

        if ($token === '' || $modelId === '') {
            return null;
        }

        try {
            $baseUrl = $this->resolveBaseUrl($provider->driver, $provider->base_url);
            $resolvedModelId = $this->normalizeModelIdForEndpoint($provider->driver, $baseUrl, $modelId);
            $history = [...array_slice($messages, -8), ['role' => 'user', 'content' => $userMessage]];

            $effectiveMaxTokens = max(1024, $maxTokens);

            if ($provider->driver === 'gemini') {
                $contents = array_map(fn (array $item): array => [
                    'role' => $item['role'] === 'assistant' ? 'model' : 'user',
                    'parts' => [['text' => $item['content']]],
                ], $history);

                $response = Http::baseUrl($baseUrl)
                    ->withHeaders(['x-goog-api-key' => $token])
                    ->acceptJson()
                    ->connectTimeout(3)
                    ->timeout(15)
                    ->post('/models/'.rawurlencode($resolvedModelId).':generateContent', [
                        'systemInstruction' => ['parts' => [['text' => $instructions]]],
                        'contents' => $contents,
                        'generationConfig' => ['maxOutputTokens' => $effectiveMaxTokens],
                    ]);

                if (! $response->successful()) {
                    $this->markFailure($provider, 'HTTP '.$response->status().' dari Gemini.');

                    return null;
                }

                $text = (string) ($response->json('candidates.0.content.parts.0.text') ?? '');

                return trim($text) !== '' ? $text : null;
            }

            if ($provider->driver === 'anthropic') {
                $response = Http::baseUrl($baseUrl)
                    ->withHeaders([
                        'x-api-key' => $token,
                        'anthropic-version' => '2023-06-01',
                    ])
                    ->acceptJson()
                    ->connectTimeout(3)
                    ->timeout(15)
                    ->post('/messages', [
                        'model' => $resolvedModelId,
                        'max_tokens' => $effectiveMaxTokens,
                        'system' => $instructions,
                        'messages' => $history,
                    ]);

                if (! $response->successful()) {
                    $this->markFailure($provider, 'HTTP '.$response->status().' dari Anthropic.');

                    return null;
                }

                $text = (string) ($response->json('content.0.text') ?? '');

                return trim($text) !== '' ? $text : null;
            }

            $response = Http::baseUrl($baseUrl)
                ->withToken($token)
                ->acceptJson()
                ->connectTimeout(3)
                ->timeout(15)
                ->post('/chat/completions', [
                    'model' => $resolvedModelId,
                    'max_tokens' => $effectiveMaxTokens,
                    'messages' => [
                        ['role' => 'system', 'content' => $instructions],
                        ...$history,
                    ],
                ]);

            if (! $response->successful()) {
                $this->markFailure($provider, 'HTTP '.$response->status().' dari provider AI.');

                return null;
            }

            $text = (string) ($response->json('choices.0.message.content') ?? '');

            return trim($text) !== '' ? $text : null;
        } catch (\Throwable) {
            $this->markFailure($provider, 'Koneksi ke provider AI gagal atau timeout.');

            return null;
        }
    }

    private function sendModelsProbe(string $driver, string $baseUrl, string $token): Response
    {
        if ($driver === 'gemini') {
            return Http::baseUrl($baseUrl)
                ->withHeaders(['x-goog-api-key' => $token])
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(12)
                ->get('/models');
        }

        if ($driver === 'anthropic') {
            return Http::baseUrl($baseUrl)
                ->withHeaders([
                    'x-api-key' => $token,
                    'anthropic-version' => '2023-06-01',
                ])
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(12)
                ->get('/models');
        }

        return Http::baseUrl($baseUrl)
            ->withToken($token)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(12)
            ->get('/models');
    }

    private function diagnoseHttpError(int $status, mixed $body, string $token, ?string $modelId = null): string
    {
        $detail = '';
        if (is_array($body)) {
            $rawDetail = $body['error']['message']
                ?? $body['message']
                ?? (is_string($body['error'] ?? null) ? $body['error'] : null);

            if (is_string($rawDetail) && trim($rawDetail) !== '') {
                $sanitized = str_replace($token, '[REDACTED]', trim($rawDetail));
                $detail = ' Detail: '.mb_substr($sanitized, 0, 180);
            }
        }

        return match (true) {
            $status === 401 => 'HTTP 401 — API token tidak valid, salah format, atau sudah dicabut.'.$detail,
            $status === 403 => 'HTTP 403 — Akses ditolak oleh provider (cek izin API key, region, atau kebijakan akun).'.$detail,
            $status === 404 => ($modelId !== null
                ? "HTTP 404 — Model \"{$modelId}\" atau endpoint tidak ditemukan di provider."
                : 'HTTP 404 — Endpoint API tidak ditemukan. Periksa Base URL atau versi API provider.').$detail,
            $status === 429 => 'HTTP 429 — Rate limit terlampaui atau kuota/billing akun provider habis.'.$detail,
            $status >= 500 => "HTTP {$status} — Server provider AI sedang mengalami gangguan. Coba lagi beberapa saat.".$detail,
            default => "HTTP {$status} — Provider menolak permintaan koneksi.".$detail,
        };
    }

    private function diagnoseConnectionException(ConnectionException $exception): string
    {
        $msg = strtolower($exception->getMessage());

        if (str_contains($msg, 'timed out') || str_contains($msg, 'timeout')) {
            return 'Koneksi timeout — server provider tidak merespons dalam batas waktu. Periksa jaringan atau status layanan provider.';
        }

        if (str_contains($msg, 'ssl') || str_contains($msg, 'certificate')) {
            return 'Koneksi gagal karena masalah sertifikat SSL/TLS pada endpoint provider.';
        }

        if (str_contains($msg, 'could not resolve host') || str_contains($msg, 'name or service not known')) {
            return 'Host provider tidak ditemukan (DNS gagal). Periksa kembali Base URL atau koneksi internet server.';
        }

        return 'Koneksi ke provider AI gagal atau tidak dapat dijangkau dari server.';
    }

    private function markFailure(AiProvider $provider, string $safeMessage): void
    {
        $provider->forceFill([
            'connection_status' => 'failed',
            'last_error' => $safeMessage,
            'last_validated_at' => now(),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{id: string, name: string}>
     */
    private function normalizeModels(string $driver, array $payload): array
    {
        $items = [];

        if ($driver === 'gemini') {
            foreach ((array) ($payload['models'] ?? []) as $model) {
                if (! is_array($model) || empty($model['name'])) {
                    continue;
                }
                $id = preg_replace('/^models\//', '', (string) $model['name']) ?: (string) $model['name'];
                $items[] = [
                    'id' => $id,
                    'name' => (string) ($model['displayName'] ?? $id),
                ];
            }
        } else {
            foreach ((array) ($payload['data'] ?? []) as $model) {
                if (! is_array($model) || empty($model['id'])) {
                    continue;
                }
                $rawId = (string) $model['id'];
                $id = preg_replace('/^models\//', '', $rawId) ?: $rawId;
                $items[] = [
                    'id' => $id,
                    'name' => (string) ($model['display_name'] ?? $model['name'] ?? $id),
                ];
            }
        }

        usort($items, fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

        return array_slice($items, 0, 100);
    }

    private function normalizeModelIdForEndpoint(string $driver, string $baseUrl, string $modelId): string
    {
        if ($driver === 'gemini' || str_contains(strtolower($baseUrl), 'generativelanguage.googleapis.com')) {
            return preg_replace('/^models\//', '', trim($modelId)) ?: trim($modelId);
        }

        return trim($modelId);
    }
}
