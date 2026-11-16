<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Services\FonnteMessageSender;
use App\Services\Sla\SlaConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SystemSettingsController extends Controller
{
    /** @var array<string, string> */
    private const DEFAULTS = [
        'business_name' => 'AD Com · TEFA TKJ',
        'business_tagline' => 'Portal layanan teknologi TKJ untuk sekolah dan masyarakat.',
        'business_phone' => '628123450000',
        'business_address' => 'Laboratorium TKJ / Workshop AD Com',
        'operating_hours' => 'Senin–Sabtu 08.00–17.00',
        'warranty_default_days' => '14',
        'registration_otp_enabled' => '0',
        'otp_length' => '6',
        'otp_expiration_minutes' => '5',
        'otp_resend_cooldown_seconds' => '60',
        'otp_max_attempts' => '5',
        'otp_max_resends' => '3',
        'otp_lockout_minutes' => '15',
        'otp_rate_limit_max' => '5',
        'otp_purpose_registration_phone_enabled' => '0',
        'otp_purpose_password_reset_enabled' => '1',
        'otp_purpose_password_change_enabled' => '1',
        'otp_purpose_sensitive_action_enabled' => '1',
        'otp_purpose_phone_change_enabled' => '1',
        'otp_message_template' => 'Kode verifikasi AD Com kamu: {code}. Berlaku {minutes} menit. Jangan bagikan kode ini ke siapa pun.',
        ...SlaConfig::DEFAULTS,
    ];

    public function edit(Request $request, FonnteMessageSender $fonnte): Response
    {
        $actor = $request->user();
        abort_unless($actor !== null && $actor->is_active && $actor->role === 'operator', 403);

        return Inertia::render('settings/system', [
            'settings' => SystemSetting::getMany(self::DEFAULTS),
            'integrations' => [
                'whatsapp_configured' => $fonnte->isConfigured(),
                'google_oauth_configured' => filled(config('services.google.client_id')) && filled(config('services.google.client_secret')),
                'app_env' => (string) config('app.env'),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor !== null && $actor->is_active && $actor->role === 'operator', 403);

        $validated = $request->validate([
            'business_name' => ['required', 'string', 'max:120'],
            'business_tagline' => ['required', 'string', 'max:255'],
            'business_phone' => ['nullable', 'string', 'max:24'],
            'business_address' => ['nullable', 'string', 'max:255'],
            'operating_hours' => ['nullable', 'string', 'max:120'],
            'warranty_default_days' => ['required', 'integer', 'min:0', 'max:365'],
            'registration_otp_enabled' => ['sometimes', 'boolean'],
            'otp_length' => ['sometimes', 'integer', 'min:4', 'max:8'],
            'otp_expiration_minutes' => ['sometimes', 'integer', 'min:1', 'max:30'],
            'otp_resend_cooldown_seconds' => ['sometimes', 'integer', 'min:10', 'max:300'],
            'otp_max_attempts' => ['sometimes', 'integer', 'min:3', 'max:10'],
            'otp_max_resends' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'otp_lockout_minutes' => ['sometimes', 'integer', 'min:1', 'max:120'],
            'otp_rate_limit_max' => ['sometimes', 'integer', 'min:1', 'max:30'],
            'otp_purpose_registration_phone_enabled' => ['sometimes', 'boolean'],
            'otp_purpose_password_reset_enabled' => ['sometimes', 'boolean'],
            'otp_purpose_password_change_enabled' => ['sometimes', 'boolean'],
            'otp_purpose_sensitive_action_enabled' => ['sometimes', 'boolean'],
            'otp_purpose_phone_change_enabled' => ['sometimes', 'boolean'],
            'otp_message_template' => ['sometimes', 'nullable', 'string', 'max:300'],
            'sla_normal_response_minutes' => ['sometimes', 'integer', 'min:5', 'max:10080'],
            'sla_normal_resolution_minutes' => ['sometimes', 'integer', 'min:15', 'max:43200'],
            'sla_soon_response_minutes' => ['sometimes', 'integer', 'min:5', 'max:10080'],
            'sla_soon_resolution_minutes' => ['sometimes', 'integer', 'min:15', 'max:43200'],
            'sla_high_risk_response_minutes' => ['sometimes', 'integer', 'min:5', 'max:10080'],
            'sla_high_risk_resolution_minutes' => ['sometimes', 'integer', 'min:15', 'max:43200'],
            'sla_approaching_threshold_percent' => ['sometimes', 'integer', 'min:50', 'max:95'],
            'sla_business_hours_enabled' => ['sometimes', 'boolean'],
            'sla_work_start_time' => ['sometimes', 'regex:/^\d{2}:\d{2}$/'],
            'sla_work_end_time' => ['sometimes', 'regex:/^\d{2}:\d{2}$/'],
            'sla_working_days' => ['sometimes', 'string', 'max:32'],
            'sla_holidays' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($request, $actor, $validated): void {
            $before = SystemSetting::getMany(self::DEFAULTS);
            $regOtpEnabled = $request->has('otp_purpose_registration_phone_enabled')
                ? $request->boolean('otp_purpose_registration_phone_enabled')
                : $request->boolean('registration_otp_enabled');

            $after = [
                'business_name' => (string) $validated['business_name'],
                'business_tagline' => (string) $validated['business_tagline'],
                'business_phone' => (string) ($validated['business_phone'] ?? ''),
                'business_address' => (string) ($validated['business_address'] ?? ''),
                'operating_hours' => (string) ($validated['operating_hours'] ?? ''),
                'warranty_default_days' => (string) $validated['warranty_default_days'],
                'registration_otp_enabled' => $regOtpEnabled ? '1' : '0',
                'otp_length' => (string) ($validated['otp_length'] ?? $before['otp_length']),
                'otp_expiration_minutes' => (string) ($validated['otp_expiration_minutes'] ?? $before['otp_expiration_minutes']),
                'otp_resend_cooldown_seconds' => (string) ($validated['otp_resend_cooldown_seconds'] ?? $before['otp_resend_cooldown_seconds']),
                'otp_max_attempts' => (string) ($validated['otp_max_attempts'] ?? $before['otp_max_attempts']),
                'otp_max_resends' => (string) ($validated['otp_max_resends'] ?? $before['otp_max_resends']),
                'otp_lockout_minutes' => (string) ($validated['otp_lockout_minutes'] ?? $before['otp_lockout_minutes']),
                'otp_rate_limit_max' => (string) ($validated['otp_rate_limit_max'] ?? $before['otp_rate_limit_max']),
                'otp_purpose_registration_phone_enabled' => $regOtpEnabled ? '1' : '0',
                'otp_purpose_password_reset_enabled' => ($request->has('otp_purpose_password_reset_enabled') ? $request->boolean('otp_purpose_password_reset_enabled') : $before['otp_purpose_password_reset_enabled'] === '1') ? '1' : '0',
                'otp_purpose_password_change_enabled' => ($request->has('otp_purpose_password_change_enabled') ? $request->boolean('otp_purpose_password_change_enabled') : $before['otp_purpose_password_change_enabled'] === '1') ? '1' : '0',
                'otp_purpose_sensitive_action_enabled' => ($request->has('otp_purpose_sensitive_action_enabled') ? $request->boolean('otp_purpose_sensitive_action_enabled') : $before['otp_purpose_sensitive_action_enabled'] === '1') ? '1' : '0',
                'otp_purpose_phone_change_enabled' => ($request->has('otp_purpose_phone_change_enabled') ? $request->boolean('otp_purpose_phone_change_enabled') : $before['otp_purpose_phone_change_enabled'] === '1') ? '1' : '0',
                'otp_message_template' => (string) ($validated['otp_message_template'] ?? $before['otp_message_template']),
                'sla_normal_response_minutes' => (string) ($validated['sla_normal_response_minutes'] ?? $before['sla_normal_response_minutes']),
                'sla_normal_resolution_minutes' => (string) ($validated['sla_normal_resolution_minutes'] ?? $before['sla_normal_resolution_minutes']),
                'sla_soon_response_minutes' => (string) ($validated['sla_soon_response_minutes'] ?? $before['sla_soon_response_minutes']),
                'sla_soon_resolution_minutes' => (string) ($validated['sla_soon_resolution_minutes'] ?? $before['sla_soon_resolution_minutes']),
                'sla_high_risk_response_minutes' => (string) ($validated['sla_high_risk_response_minutes'] ?? $before['sla_high_risk_response_minutes']),
                'sla_high_risk_resolution_minutes' => (string) ($validated['sla_high_risk_resolution_minutes'] ?? $before['sla_high_risk_resolution_minutes']),
                'sla_approaching_threshold_percent' => (string) ($validated['sla_approaching_threshold_percent'] ?? $before['sla_approaching_threshold_percent']),
                'sla_business_hours_enabled' => ($request->has('sla_business_hours_enabled') ? $request->boolean('sla_business_hours_enabled') : $before['sla_business_hours_enabled'] === '1') ? '1' : '0',
                'sla_work_start_time' => (string) ($validated['sla_work_start_time'] ?? $before['sla_work_start_time']),
                'sla_work_end_time' => (string) ($validated['sla_work_end_time'] ?? $before['sla_work_end_time']),
                'sla_working_days' => (string) ($validated['sla_working_days'] ?? $before['sla_working_days']),
                'sla_holidays' => (string) ($validated['sla_holidays'] ?? $before['sla_holidays']),
            ];

            SystemSetting::putMany($after);

            DB::table('audit_logs')->insert([
                'actor_id' => $actor->id,
                'event' => 'system.settings_updated',
                'auditable_type' => SystemSetting::class,
                'auditable_id' => 'global',
                'before' => json_encode($before),
                'after' => json_encode($after),
                'ip_address' => $request->ip(),
                'created_at' => now(),
            ]);
        });

        return back()->with('status', 'Konfigurasi platform berhasil disimpan.');
    }
}
