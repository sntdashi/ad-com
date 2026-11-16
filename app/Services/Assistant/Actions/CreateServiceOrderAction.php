<?php

namespace App\Services\Assistant\Actions;

use App\Actions\Orders\CreateServiceOrder;
use App\Http\Requests\StoreServiceOrderRequest;
use App\Models\AssistantActionProposal;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CreateServiceOrderAction implements AssistantActionHandler
{
    public function __construct(
        private readonly CreateServiceOrder $createServiceOrder,
    ) {}

    public function name(): string
    {
        return 'create_service_order';
    }

    public function label(): string
    {
        return 'Buat Pesanan Layanan';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function authorize(User $actor, array $payload): ?string
    {
        if (! Gate::forUser($actor)->allows('create', ServiceOrder::class)) {
            return 'Akun Anda tidak aktif atau tidak memiliki izin untuk membuat pesanan layanan baru.';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $rawPayload
     * @param  array{route?: string|null, order_id?: string|null, period?: string|null, section?: string|null, start_date?: string|null, end_date?: string|null}  $context
     * @return array{
     *     valid: bool,
     *     payload: array<string, mixed>,
     *     errors: array<string, string>,
     *     missing_fields: list<string>,
     *     message: string|null
     * }
     */
    public function validateAndNormalize(User $actor, array $rawPayload, array $context = []): array
    {
        $service = $this->resolveService($rawPayload);

        $defaultName = is_string($rawPayload['customer_name'] ?? null) ? trim($rawPayload['customer_name']) : '';
        $defaultEmail = is_string($rawPayload['email'] ?? null) ? trim($rawPayload['email']) : '';
        $defaultPhone = is_string($rawPayload['phone'] ?? null) ? trim($rawPayload['phone']) : '';
        $defaultAddress = is_string($rawPayload['address'] ?? null) ? trim($rawPayload['address']) : '';

        // Customers ordering for themselves may reuse their own verified profile fields,
        // while staff must supply explicit customer identity instead of borrowing their own staff identity.
        if ($actor->role === 'customer') {
            $defaultName = $defaultName !== '' ? $defaultName : $actor->name;
            $defaultEmail = $defaultEmail !== '' ? $defaultEmail : $actor->email;
            $defaultPhone = $defaultPhone !== '' ? $defaultPhone : ($actor->phone ?? '');
            $defaultAddress = $defaultAddress !== '' ? $defaultAddress : ($actor->address ?? '');
        }

        $normalizedPhone = $this->normalizePhone($defaultPhone);
        $normalizedEmail = strtolower(trim($defaultEmail));
        $serviceMode = is_string($rawPayload['service_mode'] ?? null) && trim($rawPayload['service_mode']) !== ''
            ? strtolower(trim((string) $rawPayload['service_mode']))
            : ($service instanceof Service ? $service->service_mode : 'dropoff');

        $candidate = [
            'service_id' => $service instanceof Service ? $service->id : null,
            'customer_name' => trim($defaultName),
            'phone' => $normalizedPhone,
            'email' => $normalizedEmail,
            'address' => trim($defaultAddress),
            'district' => is_string($rawPayload['district'] ?? null) && trim((string) $rawPayload['district']) !== ''
                ? trim((string) $rawPayload['district'])
                : null,
            'service_mode' => $serviceMode,
            'complaint' => is_string($rawPayload['complaint'] ?? null) ? trim((string) $rawPayload['complaint']) : '',
            'preferred_date' => is_string($rawPayload['preferred_date'] ?? null) && trim((string) $rawPayload['preferred_date']) !== ''
                ? trim((string) $rawPayload['preferred_date'])
                : now()->toDateString(),
            'preferred_window' => is_string($rawPayload['preferred_window'] ?? null) && trim((string) $rawPayload['preferred_window']) !== ''
                ? strtolower(trim((string) $rawPayload['preferred_window']))
                : 'morning',
            'urgency' => is_string($rawPayload['urgency'] ?? null) && trim((string) $rawPayload['urgency']) !== ''
                ? strtolower(trim((string) $rawPayload['urgency']))
                : 'normal',
            'privacy_accepted' => true,
        ];

        if ($actor->role === 'customer') {
            $candidate['customer_user_id'] = $actor->id;
        } elseif ($normalizedEmail !== '') {
            $matchedCustomer = User::query()
                ->where('email', $normalizedEmail)
                ->where('role', 'customer')
                ->where('is_active', true)
                ->first();
            $candidate['customer_user_id'] = $matchedCustomer?->id;
        } else {
            $candidate['customer_user_id'] = null;
        }

        $rules = (new StoreServiceOrderRequest)->rules();
        $validator = Validator::make($candidate, $rules);

        if ($validator->fails()) {
            $errors = [];
            $missingFields = [];
            foreach ($validator->errors()->messages() as $field => $messages) {
                $errors[$field] = (string) ($messages[0] ?? 'Tidak valid.');
                $missingFields[] = $field;
            }

            $fieldLabels = [
                'service_id' => 'jenis layanan (mis. Servis Komputer, Jaringan/Wi-Fi, Instalasi CCTV, atau Konsultasi IT)',
                'complaint' => 'keluhan/kebutuhan minimal 20 karakter',
                'address' => 'alamat lengkap minimal 8 karakter',
                'phone' => 'nomor telepon/WhatsApp aktif (8-16 digit)',
                'customer_name' => 'nama pelanggan',
                'email' => 'alamat email valid',
                'service_mode' => 'mode layanan (onsite/dropoff/remote)',
            ];

            $missingHints = array_map(
                fn (string $field): string => $fieldLabels[$field] ?? $field,
                array_slice(array_values(array_unique($missingFields)), 0, 6),
            );

            return [
                'valid' => false,
                'payload' => $candidate,
                'errors' => $errors,
                'missing_fields' => array_values(array_unique($missingFields)),
                'message' => 'Siap. Saya perlu mengetahui detail berikut untuk menyiapkan pratinjau pesanan layanan (lengkapi detail: '.implode('; ', $missingHints).').',
            ];
        }

        $validated = $validator->validated();
        $validated['customer_user_id'] = $candidate['customer_user_id'];
        $validated['service_name'] = $service instanceof Service ? $service->name : 'Layanan AD Com';
        $validated['service_pricing_label'] = $service instanceof Service ? $service->pricing_label : null;

        return [
            'valid' => true,
            'payload' => $validated,
            'errors' => [],
            'missing_fields' => [],
            'message' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $normalizedPayload
     * @return array{
     *     title: string,
     *     target: string,
     *     proposed_changes: list<array{label: string, before?: string|null, after: string}>,
     *     financial_impact: array{label: string, amount: int|null, formatted: string|null, breakdown?: list<array{label: string, value: string}>}|null,
     *     warnings: list<string>,
     *     summary_text: string
     * }
     */
    public function buildPreview(User $actor, array $normalizedPayload): array
    {
        $serviceName = (string) ($normalizedPayload['service_name'] ?? 'Layanan AD Com');
        $pricingLabel = is_string($normalizedPayload['service_pricing_label'] ?? null)
            ? $normalizedPayload['service_pricing_label']
            : 'Estimasi mengikuti pemeriksaan teknisi';

        $maskedPhone = $this->maskPhone((string) $normalizedPayload['phone']);
        $maskedEmail = $this->maskEmail((string) $normalizedPayload['email']);

        $changes = [
            ['label' => 'Layanan', 'before' => null, 'after' => $serviceName],
            ['label' => 'Pelanggan', 'before' => null, 'after' => (string) $normalizedPayload['customer_name']],
            ['label' => 'Kontak', 'before' => null, 'after' => "{$maskedPhone} • {$maskedEmail}"],
            ['label' => 'Alamat', 'before' => null, 'after' => Str::limit((string) $normalizedPayload['address'], 120)],
            ['label' => 'Mode & Jadwal', 'before' => null, 'after' => strtoupper((string) $normalizedPayload['service_mode']).' • '.(string) $normalizedPayload['preferred_date'].' ('.(string) $normalizedPayload['preferred_window'].')'],
            ['label' => 'Urgensi', 'before' => null, 'after' => (string) $normalizedPayload['urgency']],
            ['label' => 'Keluhan', 'before' => null, 'after' => Str::limit((string) $normalizedPayload['complaint'], 160)],
        ];

        $warnings = [
            'Pesanan baru akan tercatat dengan status awal NEW dan menotifikasi tim operator/admin.',
        ];
        if ((string) $normalizedPayload['urgency'] === 'soon') {
            $warnings[] = 'Ditandai prioritas SEGERA (soon) sesuai permintaan.';
        }

        return [
            'title' => 'Pratinjau Pembuatan Pesanan Layanan',
            'target' => $serviceName.' — '.(string) $normalizedPayload['customer_name'],
            'proposed_changes' => $changes,
            'financial_impact' => [
                'label' => 'Acuan Biaya Layanan',
                'amount' => null,
                'formatted' => $pricingLabel,
                'breakdown' => [],
            ],
            'warnings' => $warnings,
            'summary_text' => "Saya telah menyiapkan pratinjau pembuatan pesanan {$serviceName} atas nama {$normalizedPayload['customer_name']}. Silakan periksa detail di bawah lalu tekan Konfirmasi untuk menyimpan tiket ke sistem.",
        ];
    }

    /**
     * @param  array<string, mixed>  $normalizedPayload
     * @return array{
     *     reference: string,
     *     summary: array<string, mixed>,
     *     reply: string,
     *     actions: list<array{label: string, href: string}>
     * }
     */
    public function execute(
        User $actor,
        array $normalizedPayload,
        AssistantActionProposal $proposal,
        ?string $ipAddress = null,
    ): array {
        $customerId = $normalizedPayload['customer_user_id'] ?? null;
        $targetCustomer = is_int($customerId) ? User::query()->find($customerId) : null;

        $result = $this->createServiceOrder->handle($normalizedPayload, $targetCustomer);
        $order = $result['order'];

        return [
            'reference' => $order->ticket_number,
            'summary' => [
                'order_id' => $order->id,
                'ticket_number' => $order->ticket_number,
                'status' => $order->status,
                'service_id' => $order->service_id,
                'customer_name' => $order->customer_name,
            ],
            'reply' => "Pesanan layanan berhasil dibuat dengan nomor tiket {$order->ticket_number} (status: NEW).",
            'actions' => [
                ['label' => "Buka Tiket {$order->ticket_number}", 'href' => route('orders.show', $order)],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $rawPayload
     */
    private function resolveService(array $rawPayload): ?Service
    {
        if (isset($rawPayload['service_id']) && is_numeric($rawPayload['service_id'])) {
            return Service::query()->where('is_active', true)->whereKey((int) $rawPayload['service_id'])->first();
        }

        $hintText = strtolower(trim(implode(' ', array_filter([
            is_string($rawPayload['service'] ?? null) ? $rawPayload['service'] : null,
            is_string($rawPayload['service_code'] ?? null) ? $rawPayload['service_code'] : null,
            is_string($rawPayload['raw_message'] ?? null) ? $rawPayload['raw_message'] : null,
            is_string($rawPayload['complaint'] ?? null) ? $rawPayload['complaint'] : null,
        ]))));

        $services = Service::query()->where('is_active', true)->orderBy('sort_order')->get();
        foreach ($services as $service) {
            if (str_contains($hintText, strtolower($service->code))
                || str_contains($hintText, strtolower($service->slug))
                || str_contains($hintText, strtolower($service->name))) {
                return $service;
            }
        }

        $keywordMap = [
            'instalasi-cctv' => ['cctv', 'kamera', 'dvr', 'nvr'],
            'jaringan-wifi' => ['wifi', 'wi-fi', 'jaringan', 'router', 'mikrotik', 'lan', 'internet'],
            'servis-komputer' => ['komputer', 'laptop', 'pc', 'windows', 'overheat', 'ssd', 'ram'],
            'konsultasi-it' => ['konsultasi', 'audit it', 'rekomendasi perangkat'],
        ];

        foreach ($keywordMap as $slug => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($hintText, $keyword)) {
                    $matched = $services->firstWhere('slug', $slug);
                    if ($matched !== null) {
                        return $matched;
                    }
                }
            }
        }

        return null;
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($digits, '0')) {
            return '62'.substr($digits, 1);
        }

        return $digits;
    }

    private function maskPhone(string $phone): string
    {
        if (strlen($phone) <= 5) {
            return '***';
        }

        return substr($phone, 0, 4).str_repeat('*', max(3, strlen($phone) - 7)).substr($phone, -3);
    }

    private function maskEmail(string $email): string
    {
        if (! str_contains($email, '@')) {
            return '***';
        }

        [$local, $domain] = explode('@', $email, 2);
        $prefix = substr($local, 0, min(2, strlen($local)));

        return $prefix.'***@'.$domain;
    }
}
