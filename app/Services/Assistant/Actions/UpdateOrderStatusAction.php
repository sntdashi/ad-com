<?php

namespace App\Services\Assistant\Actions;

use App\Actions\Orders\UpdateOrderStatus;
use App\Models\AssistantActionProposal;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\Sla\SlaService;
use Illuminate\Support\Facades\Gate;

class UpdateOrderStatusAction implements AssistantActionHandler
{
    public function __construct(
        private readonly UpdateOrderStatus $updateOrderStatus,
    ) {}

    public function name(): string
    {
        return 'update_order_status';
    }

    public function label(): string
    {
        return 'Perbarui Status Pesanan';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function authorize(User $actor, array $payload): ?string
    {
        if (! $actor->is_active || ! in_array($actor->role, ['operator', 'admin', 'supervisor', 'technician'], true)) {
            return 'Akses ditolak. Pembaruan status pesanan hanya tersedia untuk Operator, Admin, Supervisor, atau Teknisi yang ditugaskan.';
        }

        $order = $this->resolveOrder($payload, []);
        if ($order instanceof ServiceOrder && ! Gate::forUser($actor)->allows('update', $order)) {
            return "Akses ditolak. Anda tidak memiliki izin untuk memperbarui status tiket {$order->ticket_number}.";
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
        if (! $order instanceof ServiceOrder) {
            return [
                'valid' => false,
                'payload' => [],
                'errors' => ['service_order_id' => 'Nomor tiket tidak ditemukan.'],
                'missing_fields' => ['ticket_number'],
                'message' => 'Sebutkan nomor tiket pesanan yang ingin diperbarui statusnya (contoh: "Ubah status ADC-20261116-0001 ke in_progress").',
            ];
        }

        if (! Gate::forUser($actor)->allows('update', $order)) {
            return [
                'valid' => false,
                'payload' => ['service_order_id' => $order->id],
                'errors' => ['authorization' => 'Anda tidak ditugaskan pada tiket ini.'],
                'missing_fields' => [],
                'message' => "Akses ditolak. Anda tidak memiliki izin untuk memperbarui status tiket {$order->ticket_number}.",
            ];
        }

        $allowed = UpdateOrderStatus::allowedTransitions($actor, $order);
        $targetStatus = is_string($rawPayload['status'] ?? null) ? strtolower(trim($rawPayload['status'])) : '';

        if ($targetStatus === '') {
            $allowedText = $allowed !== [] ? implode(', ', $allowed) : 'tidak ada transisi tersedia dari status saat ini';

            return [
                'valid' => false,
                'payload' => ['service_order_id' => $order->id],
                'errors' => ['status' => 'Status tujuan belum ditentukan.'],
                'missing_fields' => ['status'],
                'message' => "Sebutkan status tujuan untuk {$order->ticket_number} (status saat ini: {$order->status}; transisi tersedia untuk peran Anda: {$allowedText}).",
            ];
        }

        if (! in_array($targetStatus, $allowed, true)) {
            $allowedText = $allowed !== [] ? implode(', ', $allowed) : 'tidak ada transisi tersedia';

            return [
                'valid' => false,
                'payload' => [
                    'service_order_id' => $order->id,
                    'status' => $targetStatus,
                ],
                'errors' => ['status' => "Transisi dari {$order->status} ke {$targetStatus} tidak diizinkan."],
                'missing_fields' => [],
                'message' => "Perubahan status {$order->ticket_number} dari '{$order->status}' ke '{$targetStatus}' tidak diizinkan. Opsi tersedia: {$allowedText}.",
            ];
        }

        $note = is_string($rawPayload['note'] ?? null) && trim($rawPayload['note']) !== ''
            ? trim($rawPayload['note'])
            : null;

        return [
            'valid' => true,
            'payload' => [
                'service_order_id' => $order->id,
                'ticket_number' => $order->ticket_number,
                'previous_status' => $order->status,
                'status' => $targetStatus,
                'note' => $note,
            ],
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
        $order = ServiceOrder::query()->findOrFail((string) $normalizedPayload['service_order_id']);
        $targetStatus = (string) $normalizedPayload['status'];
        $beforeLabel = "{$order->status} (".UpdateOrderStatus::statusLabel($order->status).')';
        $afterLabel = "{$targetStatus} (".UpdateOrderStatus::statusLabel($targetStatus).')';

        $changes = [
            ['label' => 'Status Tiket', 'before' => $beforeLabel, 'after' => $afterLabel],
        ];

        if (is_string($normalizedPayload['note'] ?? null) && $normalizedPayload['note'] !== '') {
            $changes[] = [
                'label' => 'Catatan Progres',
                'before' => null,
                'after' => (string) $normalizedPayload['note'],
            ];
        }

        $warnings = [];
        if (in_array($targetStatus, SlaService::PAUSED_STATUSES, true)) {
            $warnings[] = 'Timer SLA akan dijeda (paused) saat status ini aktif.';
        } elseif ($order->sla_paused_at !== null && ! in_array($targetStatus, SlaService::TERMINAL_STATUSES, true)) {
            $warnings[] = 'Timer SLA yang sedang terjeda akan dilanjutkan kembali.';
        }
        if ($targetStatus === 'completed') {
            $warnings[] = 'Tiket akan ditandai selesai dan waktu selesai (completed_at) dicatat.';
        } elseif ($targetStatus === 'cancelled') {
            $warnings[] = 'Pembatalan tiket bersifat terminal dan menotifikasi pelanggan.';
        }

        return [
            'title' => 'Pratinjau Pembaruan Status Pesanan',
            'target' => "{$order->ticket_number} — {$order->customer_name}",
            'proposed_changes' => $changes,
            'financial_impact' => null,
            'warnings' => $warnings,
            'summary_text' => "Saya telah menyiapkan pratinjau pembaruan status tiket {$order->ticket_number} dari '{$order->status}' menjadi '{$targetStatus}'. Konfirmasi untuk menerapkan perubahan.",
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
        $updated = $this->updateOrderStatus->handle(
            order: $order,
            actor: $actor,
            status: (string) $normalizedPayload['status'],
            note: is_string($normalizedPayload['note'] ?? null) ? $normalizedPayload['note'] : null,
            ipAddress: $ipAddress,
        );

        return [
            'reference' => $updated->ticket_number,
            'summary' => [
                'order_id' => $updated->id,
                'ticket_number' => $updated->ticket_number,
                'status' => $updated->status,
            ],
            'reply' => "Status tiket {$updated->ticket_number} berhasil diperbarui menjadi '{$updated->status}' (".UpdateOrderStatus::statusLabel($updated->status).').',
            'actions' => [
                ['label' => "Lihat Tiket {$updated->ticket_number}", 'href' => route('orders.show', $updated)],
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
}
