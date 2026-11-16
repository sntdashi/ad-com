<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AiProvider;
use App\Models\User;
use App\Services\Ai\AiProviderClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AiProviderController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeSuperadmin($request->user());

        $providers = AiProvider::query()
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get()
            ->map(fn (AiProvider $provider): array => [
                'id' => $provider->id,
                'driver' => $provider->driver,
                'name' => $provider->name,
                'base_url' => $provider->base_url,
                'token_configured' => filled($provider->api_token),
                'masked_token' => $this->maskToken((string) $provider->api_token),
                'model_id' => $provider->model_id,
                'model_display_name' => $provider->model_display_name,
                'discovered_models' => $provider->discovered_models ?? [],
                'is_active' => $provider->is_active,
                'is_default' => $provider->is_default,
                'connection_status' => $provider->connection_status,
                'last_error' => $provider->last_error,
                'last_validated_at' => $provider->last_validated_at?->translatedFormat('d M Y, H.i'),
            ]);

        $activeDefault = $providers->firstWhere('is_default', true);

        return Inertia::render('settings/ai-providers', [
            'providers' => $providers->values(),
            'activeDefault' => $activeDefault,
            'drivers' => [
                ['value' => 'openai', 'label' => 'OpenAI'],
                ['value' => 'gemini', 'label' => 'Google Gemini'],
                ['value' => 'anthropic', 'label' => 'Anthropic Claude'],
                ['value' => 'openrouter', 'label' => 'OpenRouter'],
                ['value' => 'custom_openai', 'label' => 'Custom OpenAI-compatible API'],
            ],
        ]);
    }

    public function store(Request $request, AiProviderClient $client): RedirectResponse
    {
        $actor = $this->authorizeSuperadmin($request->user());

        $validated = $request->validate([
            'driver' => ['required', Rule::in(AiProvider::DRIVERS)],
            'name' => ['required', 'string', 'max:120'],
            'base_url' => ['nullable', 'string', 'max:255'],
            'api_token' => ['required', 'string', 'min:8', 'max:300'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        $resolvedUrl = $client->resolveBaseUrl((string) $validated['driver'], $validated['base_url'] ?? null);
        $setDefault = $request->boolean('is_default');

        DB::transaction(function () use ($actor, $validated, $resolvedUrl, $setDefault, $request): void {
            if ($setDefault) {
                AiProvider::query()->update(['is_default' => false]);
            }

            $provider = AiProvider::query()->create([
                'driver' => $validated['driver'],
                'name' => trim((string) $validated['name']),
                'base_url' => $validated['driver'] === 'custom_openai' ? $resolvedUrl : null,
                'api_token' => trim((string) $validated['api_token']),
                'is_active' => true,
                'is_default' => $setDefault,
                'connection_status' => 'untested',
            ]);

            $this->recordAudit($actor, 'ai_provider.created', (string) $provider->id, [
                'driver' => $provider->driver,
                'name' => $provider->name,
                'is_default' => $provider->is_default,
            ], $request->ip());
        });

        return back()->with('success', 'Provider AI berhasil ditambahkan. Jalankan Test & Discover Models untuk memuat daftar model.');
    }

    public function update(Request $request, AiProvider $aiProvider, AiProviderClient $client): RedirectResponse
    {
        $actor = $this->authorizeSuperadmin($request->user());

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'base_url' => ['nullable', 'string', 'max:255'],
            'api_token' => ['nullable', 'string', 'min:8', 'max:300'],
            'model_id' => ['nullable', 'string', 'max:120'],
            'model_display_name' => ['nullable', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if ($aiProvider->driver === 'custom_openai' && array_key_exists('base_url', $validated)) {
            $validated['base_url'] = $client->validateCustomBaseUrl((string) $validated['base_url']);
        }

        $updates = [
            'name' => $validated['name'] ?? $aiProvider->name,
            'model_id' => array_key_exists('model_id', $validated) ? $validated['model_id'] : $aiProvider->model_id,
            'model_display_name' => array_key_exists('model_display_name', $validated)
                ? $validated['model_display_name']
                : $aiProvider->model_display_name,
            'is_active' => $request->has('is_active') ? $request->boolean('is_active') : $aiProvider->is_active,
        ];

        if ($aiProvider->driver === 'custom_openai' && isset($validated['base_url'])) {
            $updates['base_url'] = $validated['base_url'];
        }

        if (isset($validated['api_token']) && trim((string) $validated['api_token']) !== '') {
            $updates['api_token'] = trim((string) $validated['api_token']);
            $updates['connection_status'] = 'untested';
            $updates['last_error'] = null;
        }

        $aiProvider->update($updates);

        $this->recordAudit($actor, 'ai_provider.updated', (string) $aiProvider->id, [
            'name' => $aiProvider->name,
            'model_id' => $aiProvider->model_id,
            'is_active' => $aiProvider->is_active,
            'token_updated' => isset($validated['api_token']) && trim((string) $validated['api_token']) !== '',
        ], $request->ip());

        return back()->with('success', 'Konfigurasi provider AI diperbarui.');
    }

    public function test(Request $request, AiProvider $aiProvider, AiProviderClient $client): RedirectResponse
    {
        $actor = $this->authorizeSuperadmin($request->user());

        $result = $client->testConnection($aiProvider);

        $aiProvider->forceFill([
            'connection_status' => $result['ok'] ? 'connected' : 'failed',
            'last_validated_at' => now(),
            'last_error' => $result['ok'] ? null : $result['message'],
        ])->save();

        $this->recordAudit($actor, 'ai_provider.tested', (string) $aiProvider->id, [
            'ok' => $result['ok'],
            'http_status' => $result['http_status'],
        ], $request->ip());

        if (! $result['ok']) {
            return back()->withErrors(['provider' => $result['message']]);
        }

        return back()->with('success', $result['message']);
    }

    public function discover(Request $request, AiProvider $aiProvider, AiProviderClient $client): RedirectResponse
    {
        $actor = $this->authorizeSuperadmin($request->user());

        $result = $client->discoverModels($aiProvider);

        $aiProvider->forceFill([
            'connection_status' => $result['ok'] ? 'connected' : 'failed',
            'last_validated_at' => now(),
            'last_error' => $result['ok'] ? null : $result['message'],
            'discovered_models' => $result['ok'] && $result['models'] !== [] ? $result['models'] : $aiProvider->discovered_models,
            'model_id' => $aiProvider->model_id ?: ($result['models'][0]['id'] ?? null),
            'model_display_name' => $aiProvider->model_display_name ?: ($result['models'][0]['name'] ?? null),
        ])->save();

        $this->recordAudit($actor, 'ai_provider.discovered', (string) $aiProvider->id, [
            'ok' => $result['ok'],
            'models_count' => count($result['models']),
        ], $request->ip());

        if (! $result['ok']) {
            return back()->withErrors(['provider' => $result['message']]);
        }

        return back()->with('success', $result['message']);
    }

    public function validateManualModel(Request $request, AiProvider $aiProvider, AiProviderClient $client): RedirectResponse
    {
        $actor = $this->authorizeSuperadmin($request->user());

        $validated = $request->validate([
            'model_id' => ['required', 'string', 'max:120'],
            'model_display_name' => ['nullable', 'string', 'max:120'],
        ]);

        $result = $client->validateManualModel($aiProvider, (string) $validated['model_id']);

        $aiProvider->forceFill([
            'connection_status' => $result['ok'] ? 'connected' : 'failed',
            'last_validated_at' => now(),
            'last_error' => $result['ok'] ? null : $result['message'],
            ...(
                $result['ok']
                    ? [
                        'model_id' => $validated['model_id'],
                        'model_display_name' => ($validated['model_display_name'] ?? null) ?: $validated['model_id'],
                    ]
                    : []
            ),
        ])->save();

        $this->recordAudit($actor, 'ai_provider.manual_model_validated', (string) $aiProvider->id, [
            'ok' => $result['ok'],
            'model_id' => $validated['model_id'],
        ], $request->ip());

        if (! $result['ok']) {
            return back()->withErrors(['model_id' => $result['message']]);
        }

        return back()->with('success', $result['message']);
    }

    public function activate(Request $request, AiProvider $aiProvider): RedirectResponse
    {
        $actor = $this->authorizeSuperadmin($request->user());

        DB::transaction(function () use ($aiProvider): void {
            AiProvider::query()->update(['is_default' => false]);
            $aiProvider->forceFill(['is_active' => true, 'is_default' => true])->save();
        });

        $this->recordAudit($actor, 'ai_provider.activated', (string) $aiProvider->id, [
            'driver' => $aiProvider->driver,
            'model_id' => $aiProvider->model_id,
        ], $request->ip());

        return back()->with('success', "{$aiProvider->name} dipilih sebagai provider default ADI.");
    }

    public function destroy(Request $request, AiProvider $aiProvider): RedirectResponse
    {
        $actor = $this->authorizeSuperadmin($request->user());

        if ($aiProvider->is_default && $aiProvider->is_active) {
            return back()->withErrors([
                'provider' => 'Provider default yang sedang aktif tidak dapat dihapus langsung. Nonaktifkan atau pilih provider default lain terlebih dahulu.',
            ]);
        }

        $id = (string) $aiProvider->id;
        $name = $aiProvider->name;

        $aiProvider->delete();

        $this->recordAudit($actor, 'ai_provider.deleted', $id, ['name' => $name], $request->ip());

        return back()->with('success', "Provider {$name} dihapus.");
    }

    private function authorizeSuperadmin(?User $user): User
    {
        abort_unless($user !== null && $user->is_active && $user->role === 'operator', 403);

        return $user;
    }

    private function maskToken(string $token): ?string
    {
        if ($token === '') {
            return null;
        }

        if (strlen($token) <= 8) {
            return '••••••••';
        }

        return substr($token, 0, 4).'••••••'.substr($token, -4);
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function recordAudit(User $actor, string $event, string $targetId, array $after, ?string $ip): void
    {
        DB::table('audit_logs')->insert([
            'actor_id' => $actor->id,
            'event' => $event,
            'auditable_type' => AiProvider::class,
            'auditable_id' => $targetId,
            'before' => null,
            'after' => json_encode($after),
            'ip_address' => $ip,
            'created_at' => now(),
        ]);
    }
}
