<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AiProvider;
use App\Models\AssistantSetting;
use App\Models\NotificationDelivery;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\FonnteMessageSender;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class IntegrationSettingsController extends Controller
{
    /** @var array<string, array{label: string, description: string, default_template: string}> */
    private const NOTIFICATION_EVENTS = [
        'order.created' => [
            'label' => 'Order Created',
            'description' => 'Notifikasi ke tim internal saat pelanggan membuat tiket baru.',
            'default_template' => '{body}',
        ],
        'order.status_changed' => [
            'label' => 'Order Status Changed',
            'description' => 'Notifikasi ke pelanggan ketika status tiket diperbarui.',
            'default_template' => '{body}',
        ],
        'order.assigned' => [
            'label' => 'Technician Assigned',
            'description' => 'Notifikasi ke teknisi saat ditugaskan pada suatu tiket.',
            'default_template' => '{body}',
        ],
        'order.message_received' => [
            'label' => 'New Ticket Message',
            'description' => 'Notifikasi in-app saat ada pesan baru di percakapan tiket.',
            'default_template' => '{body}',
        ],
        'inventory.low_stock' => [
            'label' => 'Low Stock Alert',
            'description' => 'Notifikasi ke gudang & Superadmin saat stok menyentuh batas minimum.',
            'default_template' => '{body}',
        ],
        'inventory.spare_part_requested' => [
            'label' => 'Spare-Part Requested',
            'description' => 'Notifikasi ke gudang & manajemen saat teknisi mengajukan permintaan spare-part.',
            'default_template' => '{body}',
        ],
        'inventory.spare_part_issued' => [
            'label' => 'Spare-Part Issued',
            'description' => 'Notifikasi ke teknisi saat permintaan spare-part disetujui dan dikeluarkan oleh gudang.',
            'default_template' => '{body}',
        ],
        'inventory.spare_part_returned' => [
            'label' => 'Spare-Part Returned',
            'description' => 'Notifikasi ke gudang & manajemen saat spare-part dikembalikan ke stok.',
            'default_template' => '{body}',
        ],
        'finance.transaction_verified' => [
            'label' => 'Finance Transaction Verified',
            'description' => 'Notifikasi ke pencatat transaksi & pelanggan saat transaksi diverifikasi.',
            'default_template' => '{body}',
        ],
        'finance.quotation_sent' => [
            'label' => 'Quotation Sent to Customer',
            'description' => 'Notifikasi ke pelanggan saat penawaran biaya siap ditinjau.',
            'default_template' => '{body}',
        ],
        'finance.quotation_decided' => [
            'label' => 'Quotation Approved / Rejected',
            'description' => 'Notifikasi ke tim keuangan saat penawaran disetujui atau ditolak pelanggan.',
            'default_template' => '{body}',
        ],
        'finance.invoice_issued' => [
            'label' => 'Invoice Issued',
            'description' => 'Notifikasi ke pelanggan saat tagihan resmi diterbitkan.',
            'default_template' => '{body}',
        ],
        'finance.payment_recorded' => [
            'label' => 'Invoice Payment Recorded',
            'description' => 'Notifikasi ke operator saat pembayaran tagihan baru menunggu verifikasi.',
            'default_template' => '{body}',
        ],
        'finance.invoice_cancelled' => [
            'label' => 'Invoice Cancelled',
            'description' => 'Notifikasi ke pelanggan saat tagihan dibatalkan oleh pihak berwenang.',
            'default_template' => '{body}',
        ],
        'sla.approaching_response' => [
            'label' => 'SLA Approaching First Response',
            'description' => 'Notifikasi ke tim saat tiket mendekati batas waktu respon awal.',
            'default_template' => '{body}',
        ],
        'sla.approaching_resolution' => [
            'label' => 'SLA Approaching Resolution',
            'description' => 'Notifikasi ke tim saat tiket mendekati batas waktu penyelesaian.',
            'default_template' => '{body}',
        ],
        'sla.breached' => [
            'label' => 'SLA Breached',
            'description' => 'Notifikasi eskalasi saat tiket melewati batas waktu SLA.',
            'default_template' => '{body}',
        ],
        'sla.resumed' => [
            'label' => 'SLA Timer Resumed',
            'description' => 'Notifikasi saat timer SLA dilanjutkan kembali setelah respon pelanggan.',
            'default_template' => '{body}',
        ],
        'order.assignment_changed' => [
            'label' => 'Assignment Changed',
            'description' => 'Notifikasi ke teknisi yang dilepas atau dialihkan dari suatu tiket.',
            'default_template' => '{body}',
        ],
        'work.started' => [
            'label' => 'Work Started',
            'description' => 'Notifikasi ke pelanggan dan tim saat teknisi memulai sesi pengerjaan.',
            'default_template' => '{body}',
        ],
        'work.completed' => [
            'label' => 'Work Session Completed',
            'description' => 'Notifikasi saat sesi pengerjaan teknisi diselesaikan.',
            'default_template' => '{body}',
        ],
        'work.evidence_submitted' => [
            'label' => 'Work Evidence Submitted',
            'description' => 'Notifikasi saat dokumentasi sebelum/sesudah atau catatan penyelesaian diunggah.',
            'default_template' => '{body}',
        ],
        'warranty.activated' => [
            'label' => 'Warranty Activated',
            'description' => 'Notifikasi ke pelanggan saat garansi layanan atau spare-part diaktifkan.',
            'default_template' => '{body}',
        ],
        'warranty.approaching_expiration' => [
            'label' => 'Warranty Approaching Expiration',
            'description' => 'Peringatan ketika masa garansi mendekati tanggal berakhir.',
            'default_template' => '{body}',
        ],
        'warranty.expired' => [
            'label' => 'Warranty Expired',
            'description' => 'Notifikasi ketika masa garansi telah berakhir.',
            'default_template' => '{body}',
        ],
        'warranty.claim_submitted' => [
            'label' => 'Warranty Claim Submitted',
            'description' => 'Notifikasi ke tim operasional saat klaim garansi baru diajukan.',
            'default_template' => '{body}',
        ],
        'warranty.claim_reviewed' => [
            'label' => 'Warranty Claim Reviewed',
            'description' => 'Notifikasi ke pelanggan saat klaim garansi disetujui atau ditolak.',
            'default_template' => '{body}',
        ],
        'warranty.claim_resolved' => [
            'label' => 'Warranty Claim Resolved',
            'description' => 'Notifikasi ke pelanggan saat penanganan klaim garansi dinyatakan selesai.',
            'default_template' => '{body}',
        ],
    ];

    public function edit(Request $request, FonnteMessageSender $fonnte): Response
    {
        $this->authorizeSuperadmin($request->user());

        $assistant = AssistantSetting::query()->first();
        $fonnteToken = $fonnte->resolvedToken();
        $openAiKey = trim((string) ($assistant?->api_key ?: config('services.openai.key')));

        $statusMeta = SystemSetting::getMany([
            'fonnte_last_test_status' => 'Belum diuji',
            'fonnte_last_test_at' => '',
            'fonnte_last_test_error' => '',
            'openai_last_test_status' => 'Belum diuji',
            'openai_last_test_at' => '',
            'openai_last_test_error' => '',
        ]);

        $eventDefaults = [];
        foreach (self::NOTIFICATION_EVENTS as $eventKey => $meta) {
            $slug = str_replace('.', '_', $eventKey);
            $eventDefaults["notif_event_{$slug}_enabled"] = '1';
            $eventDefaults["notif_event_{$slug}_template"] = $meta['default_template'];
        }
        $storedEvents = SystemSetting::getMany($eventDefaults);

        $notificationEvents = [];
        foreach (self::NOTIFICATION_EVENTS as $eventKey => $meta) {
            $slug = str_replace('.', '_', $eventKey);
            $notificationEvents[] = [
                'key' => $eventKey,
                'label' => $meta['label'],
                'description' => $meta['description'],
                'enabled' => $storedEvents["notif_event_{$slug}_enabled"] === '1',
                'template' => $storedEvents["notif_event_{$slug}_template"],
            ];
        }

        $pendingJobs = Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0;
        $failedJobs = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;

        $deliveries = NotificationDelivery::query()
            ->latest('id')
            ->limit(15)
            ->get()
            ->map(fn (NotificationDelivery $delivery): array => [
                'id' => $delivery->id,
                'event_key' => $delivery->event_key,
                'channel' => $delivery->channel,
                'target' => (string) ($delivery->payload_summary['target'] ?? '—'),
                'status' => $delivery->status,
                'attempts' => $delivery->attempts,
                'last_error' => is_string($delivery->last_error) ? $fonnte->sanitizeErrorMessage($delivery->last_error) : null,
                'created_at' => $delivery->created_at?->translatedFormat('d M Y, H.i'),
            ]);

        $activeAiProvider = AiProvider::query()
            ->where('is_active', true)
            ->where('is_default', true)
            ->first();

        return Inertia::render('settings/integrations', [
            'fonnte' => [
                'configured' => $fonnteToken !== '',
                'masked_hint' => $this->maskSecret($fonnteToken),
                'last_status' => $statusMeta['fonnte_last_test_status'],
                'last_tested_at' => $statusMeta['fonnte_last_test_at'] ?: null,
                'last_error' => $statusMeta['fonnte_last_test_error'] ?: null,
            ],
            'aiProviderSummary' => $activeAiProvider ? [
                'configured' => filled($activeAiProvider->api_token),
                'name' => $activeAiProvider->name,
                'driver_label' => AiProvider::driverLabel($activeAiProvider->driver),
                'model_id' => $activeAiProvider->model_id,
                'connection_status' => $activeAiProvider->connection_status,
                'last_validated_at' => $activeAiProvider->last_validated_at?->translatedFormat('d M Y, H.i'),
                'last_error' => $activeAiProvider->last_error,
            ] : null,
            'openai' => [
                'configured' => $openAiKey !== '',
                'masked_hint' => $this->maskSecret($openAiKey),
                'model' => (string) ($assistant?->model ?: config('services.openai.model')),
                'is_enabled' => $assistant ? (bool) $assistant->is_enabled : true,
                'last_status' => $statusMeta['openai_last_test_status'],
                'last_tested_at' => $statusMeta['openai_last_test_at'] ?: null,
                'last_error' => $statusMeta['openai_last_test_error'] ?: null,
            ],
            'worker' => [
                'queue_connection' => (string) config('queue.default'),
                'pending_jobs' => $pendingJobs,
                'failed_jobs' => $failedJobs,
                'recent_deliveries' => $deliveries,
            ],
            'notificationEvents' => $notificationEvents,
        ]);
    }

    public function updateFonnte(Request $request): RedirectResponse
    {
        $actor = $this->authorizeSuperadmin($request->user());

        $validated = $request->validate([
            'token' => ['required', 'string', 'min:6', 'max:255'],
        ]);

        SystemSetting::putEncrypted('secret:fonnte_token', $validated['token']);
        SystemSetting::putMany([
            'fonnte_last_test_status' => 'Token diperbarui (belum diuji)',
            'fonnte_last_test_error' => '',
        ]);

        $this->recordAudit($actor, 'integration.fonnte_updated', ['configured' => true], $request->ip());

        return back()->with('success', 'Token Fonnte berhasil disimpan secara terenkripsi.');
    }

    public function testFonnte(Request $request, FonnteMessageSender $fonnte): RedirectResponse
    {
        $actor = $this->authorizeSuperadmin($request->user());

        $result = $fonnte->testConnection();

        SystemSetting::putMany([
            'fonnte_last_test_status' => $result['ok'] ? 'Terhubung' : 'Gagal',
            'fonnte_last_test_at' => now()->translatedFormat('d M Y, H.i'),
            'fonnte_last_test_error' => $result['ok'] ? '' : $result['message'],
        ]);

        $this->recordAudit($actor, 'integration.fonnte_tested', ['status' => $result['status']], $request->ip());

        if (! $result['ok']) {
            return back()->withErrors(['fonnte' => $result['message']]);
        }

        return back()->with('success', $result['message']);
    }

    public function sendFonnteTestMessage(Request $request, FonnteMessageSender $fonnte): RedirectResponse
    {
        $actor = $this->authorizeSuperadmin($request->user());

        $validated = $request->validate([
            'phone' => ['required', 'regex:/^[0-9]{10,15}$/'],
            'message' => ['nullable', 'string', 'max:300'],
        ]);

        $body = trim((string) ($validated['message'] ?? ''));
        if ($body === '') {
            $body = '[AD Com] Pesan uji koneksi WhatsApp Fonnte berhasil dikirim.';
        }

        $result = $fonnte->sendOperationalMessage($validated['phone'], $body);

        SystemSetting::putMany([
            'fonnte_last_test_status' => $result['sent'] ? 'Pesan uji terkirim' : 'Gagal kirim uji',
            'fonnte_last_test_at' => now()->translatedFormat('d M Y, H.i'),
            'fonnte_last_test_error' => $result['error'] ?? '',
        ]);

        $this->recordAudit($actor, 'integration.fonnte_test_message', ['sent' => $result['sent']], $request->ip());

        if (! $result['sent']) {
            return back()->withErrors(['fonnte_test' => $result['error'] ?? 'Pengiriman pesan uji gagal.']);
        }

        return back()->with('success', 'Pesan uji WhatsApp berhasil dikirim ke nomor tujuan.');
    }

    public function updateOpenAi(Request $request): RedirectResponse
    {
        $actor = $this->authorizeSuperadmin($request->user());

        $validated = $request->validate([
            'api_key' => ['nullable', 'string', 'min:10', 'max:255'],
            'model' => ['required', 'string', 'max:64'],
            'is_enabled' => ['sometimes', 'boolean'],
        ]);

        $setting = AssistantSetting::query()->firstOrNew([]);
        $setting->provider = 'openai';
        $setting->model = (string) $validated['model'];
        $setting->is_enabled = $request->boolean('is_enabled', true);

        if (isset($validated['api_key']) && trim((string) $validated['api_key']) !== '') {
            $setting->api_key = trim((string) $validated['api_key']);
        }

        $setting->save();

        SystemSetting::putMany([
            'openai_last_test_status' => 'Konfigurasi diperbarui',
            'openai_last_test_error' => '',
        ]);

        $this->recordAudit($actor, 'integration.openai_updated', [
            'model' => $setting->model,
            'is_enabled' => $setting->is_enabled,
            'key_updated' => isset($validated['api_key']) && trim((string) $validated['api_key']) !== '',
        ], $request->ip());

        return back()->with('success', 'Konfigurasi OpenAI ADI berhasil disimpan.');
    }

    public function testOpenAi(Request $request): RedirectResponse
    {
        $actor = $this->authorizeSuperadmin($request->user());

        $setting = AssistantSetting::query()->first();
        $apiKey = trim((string) ($setting?->api_key ?: config('services.openai.key')));
        $baseUrl = rtrim((string) ($setting?->base_url ?: config('services.openai.base_url', 'https://api.openai.com/v1')), '/');

        if ($apiKey === '') {
            SystemSetting::putMany([
                'openai_last_test_status' => 'Belum diatur',
                'openai_last_test_at' => now()->translatedFormat('d M Y, H.i'),
                'openai_last_test_error' => 'API key OpenAI belum dikonfigurasi.',
            ]);

            return back()->withErrors(['openai' => 'API key OpenAI belum dikonfigurasi. ADI tetap berjalan dalam mode fallback lokal.']);
        }

        try {
            $response = Http::baseUrl($baseUrl)
                ->withToken($apiKey)
                ->acceptJson()
                ->connectTimeout(4)
                ->timeout(10)
                ->get('/models');
        } catch (ConnectionException) {
            SystemSetting::putMany([
                'openai_last_test_status' => 'Gagal',
                'openai_last_test_at' => now()->translatedFormat('d M Y, H.i'),
                'openai_last_test_error' => 'Gagal menghubungi server OpenAI.',
            ]);

            return back()->withErrors(['openai' => 'Gagal menghubungi server OpenAI. Periksa koneksi server.']);
        }

        if (! $response->successful()) {
            $message = 'API key OpenAI ditolak atau tidak valid (HTTP '.$response->status().').';
            SystemSetting::putMany([
                'openai_last_test_status' => 'Gagal',
                'openai_last_test_at' => now()->translatedFormat('d M Y, H.i'),
                'openai_last_test_error' => $message,
            ]);

            $this->recordAudit($actor, 'integration.openai_tested', ['ok' => false, 'http_status' => $response->status()], $request->ip());

            return back()->withErrors(['openai' => $message]);
        }

        SystemSetting::putMany([
            'openai_last_test_status' => 'Terhubung',
            'openai_last_test_at' => now()->translatedFormat('d M Y, H.i'),
            'openai_last_test_error' => '',
        ]);

        $this->recordAudit($actor, 'integration.openai_tested', ['ok' => true], $request->ip());

        return back()->with('success', 'Koneksi ke OpenAI berhasil diverifikasi.');
    }

    public function updateNotificationConfig(Request $request): RedirectResponse
    {
        $actor = $this->authorizeSuperadmin($request->user());

        $validated = $request->validate([
            'events' => ['required', 'array'],
            'events.*.key' => ['required', 'string'],
            'events.*.enabled' => ['required', 'boolean'],
            'events.*.template' => ['nullable', 'string', 'max:300'],
        ]);

        $updates = [];
        $summary = [];

        foreach ($validated['events'] as $event) {
            $eventKey = (string) $event['key'];
            if (! array_key_exists($eventKey, self::NOTIFICATION_EVENTS)) {
                continue;
            }

            $slug = str_replace('.', '_', $eventKey);
            $enabled = (bool) $event['enabled'];
            $template = trim((string) ($event['template'] ?? ''));

            $updates["notif_event_{$slug}_enabled"] = $enabled ? '1' : '0';
            $updates["notif_event_{$slug}_template"] = $template;
            $summary[$eventKey] = $enabled;
        }

        if ($updates !== []) {
            SystemSetting::putMany($updates);
            $this->recordAudit($actor, 'integration.notifications_updated', $summary, $request->ip());
        }

        return back()->with('success', 'Konfigurasi template & event notifikasi berhasil disimpan.');
    }

    private function authorizeSuperadmin(?User $user): User
    {
        abort_unless($user !== null && $user->is_active && $user->role === 'operator', 403);

        return $user;
    }

    private function maskSecret(string $secret): ?string
    {
        if ($secret === '') {
            return null;
        }

        $len = strlen($secret);
        if ($len <= 6) {
            return '••••••';
        }

        return substr($secret, 0, 3).'••••••'.substr($secret, -4);
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function recordAudit(User $actor, string $event, array $after, ?string $ip): void
    {
        DB::table('audit_logs')->insert([
            'actor_id' => $actor->id,
            'event' => $event,
            'auditable_type' => SystemSetting::class,
            'auditable_id' => 'integrations',
            'before' => null,
            'after' => json_encode($after),
            'ip_address' => $ip,
            'created_at' => now(),
        ]);
    }
}
