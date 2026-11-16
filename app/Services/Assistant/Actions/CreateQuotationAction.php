<?php

namespace App\Services\Assistant\Actions;

use App\Http\Requests\StoreQuotationRequest;
use App\Models\AssistantActionProposal;
use App\Models\Estimate;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\Finance\QuotationService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class CreateQuotationAction implements AssistantActionHandler
{
    public function __construct(
        private readonly QuotationService $quotationService,
    ) {}

    public function name(): string
    {
        return 'create_quotation';
    }

    public function label(): string
    {
        return 'Buat Penawaran / Estimasi';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function authorize(User $actor, array $payload): ?string
    {
        if (! Gate::forUser($actor)->allows('create', Estimate::class)) {
            return 'Akses ditolak. Pembuatan penawaran biaya (quotation) hanya diizinkan untuk Admin Operasional dan Superadmin.';
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
        $order = $this->resolveOrder($rawPayload, $context);
        $items = is_array($rawPayload['items'] ?? null) ? array_values($rawPayload['items']) : [];

        $candidate = [
            'service_order_id' => $order instanceof ServiceOrder ? $order->id : ($rawPayload['service_order_id'] ?? ''),
            'discount_amount' => isset($rawPayload['discount_amount']) ? max(0, (int) $rawPayload['discount_amount']) : 0,
            'tax_rate' => is_string($rawPayload['tax_rate'] ?? null) || is_numeric($rawPayload['tax_rate'] ?? null)
                ? (string) $rawPayload['tax_rate']
                : null,
            'valid_until' => is_string($rawPayload['valid_until'] ?? null) && trim($rawPayload['valid_until']) !== ''
                ? trim($rawPayload['valid_until'])
                : null,
            'notes' => is_string($rawPayload['notes'] ?? null) ? trim($rawPayload['notes']) : null,
            'send_now' => (bool) ($rawPayload['send_now'] ?? false),
            'items' => $items,
        ];

        $request = new StoreQuotationRequest;
        $validator = Validator::make($candidate, $request->rules());

        if ($validator->fails()) {
            $errors = [];
            $missingFields = [];
            foreach ($validator->errors()->messages() as $field => $messages) {
                $errors[$field] = (string) ($messages[0] ?? 'Tidak valid.');
                $missingFields[] = $field;
            }

            $hints = [];
            if (array_key_exists('service_order_id', $errors)) {
                $hints[] = 'nomor tiket atau order (contoh: ADC-20261116-0001)';
            }
            if (array_key_exists('items', $errors) || collect($missingFields)->contains(fn (string $f): bool => str_starts_with($f, 'items'))) {
                $hints[] = 'minimal satu item biaya beserta nominal (contoh: "jasa diagnosa & perbaikan Rp 250000")';
            }
            if ($hints === []) {
                $hints[] = implode('; ', array_values($errors));
            }

            return [
                'valid' => false,
                'payload' => $candidate,
                'errors' => $errors,
                'missing_fields' => array_values(array_unique($missingFields)),
                'message' => 'Untuk membuat pratinjau penawaran biaya, lengkapi detail berikut: '.implode(', ', $hints).'.',
            ];
        }

        if ($candidate['send_now'] && is_string($candidate['valid_until']) && strtotime($candidate['valid_until']) < strtotime(now()->toDateString())) {
            return [
                'valid' => false,
                'payload' => $candidate,
                'errors' => ['valid_until' => 'Tanggal berlaku tidak boleh di masa lalu saat dikirim langsung.'],
                'missing_fields' => ['valid_until'],
                'message' => 'Tanggal kedaluwarsa penawaran tidak boleh berada di masa lalu jika langsung dikirim.',
            ];
        }

        /** @var ServiceOrder $resolvedOrder */
        $resolvedOrder = $order ?? ServiceOrder::query()->findOrFail($candidate['service_order_id']);
        $validated = $validator->validated();
        $validated['ticket_number'] = $resolvedOrder->ticket_number;
        $validated['customer_name'] = $resolvedOrder->customer_name;

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
        $totals = $this->quotationService->previewTotals($normalizedPayload);
        $order = ServiceOrder::query()->find((string) $normalizedPayload['service_order_id']);
        $ticketNumber = (string) ($normalizedPayload['ticket_number'] ?? ($order instanceof ServiceOrder ? $order->ticket_number : '-'));
        $customerName = (string) ($normalizedPayload['customer_name'] ?? ($order instanceof ServiceOrder ? $order->customer_name : '-'));
        $existingVersions = $order instanceof ServiceOrder
            ? Estimate::query()->where('service_order_id', $order->id)->count()
            : 0;
        $nextVersion = $existingVersions + 1;

        $changes = [
            ['label' => 'Tiket Pesanan', 'before' => null, 'after' => "{$ticketNumber} ({$customerName})"],
            ['label' => 'Versi Penawaran', 'before' => $existingVersions > 0 ? "v{$existingVersions}" : null, 'after' => "v{$nextVersion}"],
            ['label' => 'Status Awal', 'before' => null, 'after' => ! empty($normalizedPayload['send_now']) ? 'Sent (Dikirim)' : 'Draft'],
        ];

        foreach ($totals['lines'] as $line) {
            $itemType = strtoupper((string) ($line['item_type'] ?? 'labor'));
            $label = (string) ($line['label'] ?? 'Item');
            $qty = (string) ($line['quantity'] ?? '1');
            $lineTotal = $this->formatRupiah((int) ($line['total'] ?? 0));
            $changes[] = [
                'label' => "[{$itemType}] {$label}",
                'before' => null,
                'after' => "{$qty}x — {$lineTotal}",
            ];
        }

        if ($totals['discount_amount'] > 0) {
            $changes[] = [
                'label' => 'Diskon',
                'before' => null,
                'after' => '-'.$this->formatRupiah($totals['discount_amount']),
            ];
        }

        $warnings = [];
        if ($existingVersions > 0) {
            $warnings[] = "Pesanan {$ticketNumber} sudah memiliki {$existingVersions} versi penawaran sebelumnya; penawaran baru akan dibuat sebagai v{$nextVersion}.";
        }
        if (! empty($normalizedPayload['send_now'])) {
            $warnings[] = 'Penawaran akan langsung berstatus SENT dan menotifikasi pelanggan.';
        }

        $formattedTotal = $this->formatRupiah($totals['total']);

        return [
            'title' => 'Pratinjau Pembuatan Penawaran Biaya',
            'target' => "{$ticketNumber} — {$customerName}",
            'proposed_changes' => $changes,
            'financial_impact' => [
                'label' => 'Total Penawaran (Setelah Diskon & Pajak)',
                'amount' => $totals['total'],
                'formatted' => $formattedTotal,
                'breakdown' => [
                    ['label' => 'Subtotal', 'value' => $this->formatRupiah($totals['subtotal'])],
                    ['label' => 'Diskon', 'value' => $this->formatRupiah($totals['discount_amount'])],
                    ['label' => "Pajak ({$totals['tax_rate']}%)", 'value' => $this->formatRupiah($totals['tax_amount'])],
                    ['label' => 'Total Akhir', 'value' => $formattedTotal],
                ],
            ],
            'warnings' => $warnings,
            'summary_text' => "Saya telah menghitung pratinjau penawaran v{$nextVersion} untuk tiket {$ticketNumber} dengan total {$formattedTotal}. Periksa rincian biaya lalu konfirmasi untuk menerbitkan penawaran.",
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
        $order = ServiceOrder::query()->findOrFail((string) $normalizedPayload['service_order_id']);
        $estimate = $this->quotationService->createQuotation($actor, $order, $normalizedPayload);

        return [
            'reference' => $estimate->quotation_number,
            'summary' => [
                'estimate_id' => $estimate->id,
                'quotation_number' => $estimate->quotation_number,
                'service_order_id' => $order->id,
                'ticket_number' => $order->ticket_number,
                'version' => $estimate->version,
                'status' => $estimate->status,
                'total' => $estimate->total,
            ],
            'reply' => "Penawaran {$estimate->quotation_number} (v{$estimate->version}, {$this->formatRupiah($estimate->total)}) berhasil dibuat untuk tiket {$order->ticket_number}.",
            'actions' => [
                ['label' => "Lihat Penawaran {$estimate->quotation_number}", 'href' => route('finance.quotations.show', $estimate)],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $rawPayload
     * @param  array{route?: string|null, order_id?: string|null, period?: string|null, section?: string|null, start_date?: string|null, end_date?: string|null}  $context
     */
    private function resolveOrder(array $rawPayload, array $context): ?ServiceOrder
    {
        if (is_string($rawPayload['service_order_id'] ?? null) && trim($rawPayload['service_order_id']) !== '') {
            $order = ServiceOrder::query()->find(trim($rawPayload['service_order_id']));
            if ($order !== null) {
                return $order;
            }
        }

        $ticket = $rawPayload['ticket_number'] ?? null;
        if (! is_string($ticket) || trim($ticket) === '') {
            $rawMessage = is_string($rawPayload['raw_message'] ?? null) ? $rawPayload['raw_message'] : '';
            if (preg_match('/\b(ADC-[A-Z0-9-]+)\b/i', $rawMessage, $matches) === 1) {
                $ticket = strtoupper($matches[1]);
            }
        }

        if (is_string($ticket) && trim($ticket) !== '') {
            $byTicket = ServiceOrder::query()->where('ticket_number', strtoupper(trim($ticket)))->first();
            if ($byTicket !== null) {
                return $byTicket;
            }
        }

        if (is_string($context['order_id'] ?? null) && trim($context['order_id']) !== '') {
            return ServiceOrder::query()->find(trim($context['order_id']));
        }

        return null;
    }

    private function formatRupiah(int $amount): string
    {
        return 'Rp '.number_format($amount, 0, ',', '.');
    }
}
