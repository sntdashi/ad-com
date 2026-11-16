<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateAssistantSettingsRequest;
use App\Models\AiProvider;
use App\Models\AssistantSetting;
use App\Models\Service;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;

class AssistantSettingsController extends Controller
{
    public function edit(): Response
    {
        abort_unless(in_array(request()->user()->role, ['admin', 'operator'], true), 403);

        $settings = AssistantSetting::query()->first();
        $activeProvider = AiProvider::query()
            ->where('is_active', true)
            ->where('is_default', true)
            ->first();
        $hasApiKey = $activeProvider
            ? filled($activeProvider->api_token)
            : ($settings ? filled($settings->api_key) : filled(config('services.openai.key')));
        $isEnabled = $settings ? (bool) $settings->is_enabled : true;
        $maxOutputTokens = $settings->max_output_tokens ?? 450;
        $defaultBaseUrl = (string) config('services.openai.base_url');

        return Inertia::render('settings/assistant', [
            'model' => $activeProvider?->model_id ?: ($settings->model ?? config('services.openai.model')),
            'hasApiKey' => $hasApiKey,
            'isEnabled' => $isEnabled,
            'aiActive' => $isEnabled && $hasApiKey && ($activeProvider ? filled($activeProvider->model_id) : true),
            'activeProvider' => $activeProvider ? [
                'name' => $activeProvider->name,
                'driver_label' => AiProvider::driverLabel($activeProvider->driver),
                'model_id' => $activeProvider->model_id,
                'connection_status' => $activeProvider->connection_status,
                'last_validated_at' => $activeProvider->last_validated_at?->translatedFormat('d M Y, H.i'),
            ] : null,
            'baseUrl' => $settings?->base_url ?: $defaultBaseUrl,
            'defaultBaseUrl' => $defaultBaseUrl,
            'maxOutputTokens' => $maxOutputTokens,
            'customInstructions' => $settings->custom_instructions ?? '',
            'activeServicesCount' => Service::query()->where('is_active', true)->count(),
            'limits' => [
                ['label' => 'Per pengguna login', 'value' => '12 pesan/menit · 120 pesan/hari'],
                ['label' => 'Per sesi tamu', 'value' => '12 pesan/menit'],
                ['label' => 'Per alamat IP', 'value' => '30 pesan/menit · 240 pesan/hari'],
                ['label' => 'Batas jawaban', 'value' => "{$maxOutputTokens} token per balasan"],
            ],
        ]);
    }

    public function update(UpdateAssistantSettingsRequest $request): RedirectResponse
    {
        $settings = AssistantSetting::query()->firstOrNew();
        $settings->provider = 'openai';
        $settings->model = filled($request->validated('model'))
            ? (string) $request->validated('model')
            : ($settings->model ?: (string) config('services.openai.model'));
        $settings->is_enabled = $request->boolean('is_enabled', true);
        $settings->base_url = filled($request->validated('base_url'))
            ? rtrim((string) $request->validated('base_url'), '/')
            : null;
        $settings->max_output_tokens = max(150, min(2000, (int) ($request->validated('max_output_tokens') ?? 450)));
        $settings->custom_instructions = filled($request->validated('custom_instructions'))
            ? trim((string) $request->validated('custom_instructions'))
            : null;

        if ($request->boolean('clear_api_key')) {
            $settings->api_key = null;
        } elseif (filled($request->validated('api_key'))) {
            $settings->api_key = $request->validated('api_key');
        }

        $settings->save();

        DB::table('audit_logs')->insert([
            'actor_id' => $request->user()?->id,
            'event' => 'assistant.settings_updated',
            'auditable_type' => AssistantSetting::class,
            'auditable_id' => (string) $settings->id,
            'before' => null,
            'after' => json_encode([
                'model' => $settings->model,
                'is_enabled' => $settings->is_enabled,
                'base_url' => $settings->base_url,
                'max_output_tokens' => $settings->max_output_tokens,
                'key_updated' => filled($request->validated('api_key')),
                'key_cleared' => $request->boolean('clear_api_key'),
            ]),
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);

        return back()->with('status', 'Pengaturan AI tersimpan.');
    }

    /**
     * Probe the provider with the submitted (or stored) credentials without saving anything.
     */
    public function test(UpdateAssistantSettingsRequest $request): JsonResponse
    {
        $settings = AssistantSetting::query()->first();
        $apiKey = trim((string) ($request->validated('api_key') ?: ($settings->api_key ?? config('services.openai.key'))));
        $model = (string) $request->validated('model');
        $baseUrl = rtrim((string) ($request->validated('base_url') ?: ($settings?->base_url ?: config('services.openai.base_url'))), '/');

        if ($apiKey === '') {
            return response()->json([
                'ok' => false,
                'status' => null,
                'message' => 'API key belum diisi. Tempel API key lalu coba lagi.',
                'latency_ms' => 0,
                'rate_limit' => [],
            ]);
        }

        $startedAt = microtime(true);

        try {
            $response = Http::baseUrl($baseUrl)
                ->withToken($apiKey)
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(10)
                ->get('/models/'.rawurlencode($model));
        } catch (ConnectionException) {
            return response()->json([
                'ok' => false,
                'status' => null,
                'message' => 'Tidak bisa terhubung ke provider. Periksa koneksi internet server.',
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'rate_limit' => [],
            ]);
        }

        $status = $response->status();
        $message = match (true) {
            $response->successful() => 'Koneksi berhasil. API key valid dan model tersedia.',
            $status === 401 => 'API key ditolak provider. Periksa kembali key-nya.',
            $status === 403 => 'Key tidak punya akses ke model ini.',
            $status === 404 => 'Model tidak ditemukan atau tidak tersedia untuk key ini.',
            $status === 429 => 'Kuota habis atau terkena rate limit di sisi provider. Cek saldo/billing akun.',
            default => "Provider membalas dengan status {$status}.",
        };

        $rateLimit = array_filter([
            'limit_requests' => $response->header('x-ratelimit-limit-requests'),
            'remaining_requests' => $response->header('x-ratelimit-remaining-requests'),
            'limit_tokens' => $response->header('x-ratelimit-limit-tokens'),
            'remaining_tokens' => $response->header('x-ratelimit-remaining-tokens'),
            'reset_requests' => $response->header('x-ratelimit-reset-requests'),
        ], fn (string $value): bool => $value !== '');

        return response()->json([
            'ok' => $response->successful(),
            'status' => $status,
            'message' => $message,
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'rate_limit' => $rateLimit,
        ]);
    }
}
