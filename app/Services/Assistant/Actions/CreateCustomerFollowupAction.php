<?php

namespace App\Services\Assistant\Actions;

use App\Actions\Orders\SendOrderMessage;
use App\Http\Requests\StoreOrderMessageRequest;
use App\Models\AssistantActionProposal;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CreateCustomerFollowupAction implements AssistantActionHandler
{
    public function __construct(
        private readonly SendOrderMessage $sendOrderMessage,
        private readonly NotificationDispatcher $dispatcher,
    ) {}

    public function name(): string
    {
        return 'create_customer_followup';
    }

    public function label(): string
    {
        return 'Kirim Follow-up Pelanggan';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function authorize(User $actor, array $payload): ?string
    {
        if (! $actor->is_active || ! in_array($actor->role, ['operator', 'admin', 'supervisor', 'technician'], true)) {
            return 'Akses ditolak. Pengiriman follow-up pelanggan hanya diizinkan untuk Operator, Admin, Supervisor, atau Teknisi yang ditugaskan.';
        }

        $order = $this->resolveOrder($payload, []);
        if ($order instanceof ServiceOrder && ! Gate::forUser($actor)->allows('update', $order)) {
            return "Akses ditolak. Anda tidak memiliki izin mengirim follow-up pada tiket {$order->ticket_number}.";
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
                'message' => 'Sebutkan nomor tiket tujuan follow-up pelanggan (contoh: "Kirim follow-up untuk ADC-20261116-0001 pesan: Perangkat sudah selesai diuji dan siap diambil").',
            ];
        }

        if (! Gate::forUser($actor)->allows('update', $order)) {
            return [
                'valid' => false,
                'payload' => ['service_order_id' => $order->id],
                'errors' => ['authorization' => 'Anda tidak ditugaskan pada tiket ini.'],
                'missing_fields' => [],
                'message' => "Akses ditolak. Anda tidak memiliki izin mengirim follow-up pada tiket {$order->ticket_number}.",
            ];
        }

        $body = is_string($rawPayload['body'] ?? null) ? trim($rawPayload['body']) : '';
        $rules = (new StoreOrderMessageRequest)->rules();
        $validator = Validator::make(['body' => $body], $rules);

        if ($validator->fails()) {
            $errors = [];
            foreach ($validator->errors()->messages() as $field => $messages) {
                $errors[$field] = (string) ($messages[0] ?? 'Tidak valid.');
            }

            return [
                'valid' => false,
                'payload' => ['service_order_id' => $order->id],
                'errors' => $errors,
                'missing_fields' => ['body'],
                'message' => "Tulis isi pesan follow-up untuk pelanggan pada tiket {$order->ticket_number} (contoh: \"Kirim follow-up {$order->ticket_number} pesan: Estimasi selesai hari ini pukul 16.00\").",
            ];
        }

        return [
            'valid' => true,
            'payload' => [
                'service_order_id' => $order->id,
                'ticket_number' => $order->ticket_number,
                'customer_name' => $order->customer_name,
                'body' => trim((string) $validator->validated()['body']),
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
        $order = ServiceOrder::query()->with('customer')->findOrFail((string) $normalizedPayload['service_order_id']);
        $body = (string) $normalizedPayload['body'];

        $changes = [
            ['label' => 'Tiket & Pelanggan', 'before' => null, 'after' => "{$order->ticket_number} — {$order->customer_name}"],
            ['label' => 'Saluran', 'before' => null, 'after' => 'Percakapan Tiket & Notifikasi Portal'],
            ['label' => 'Isi Pesan Follow-up', 'before' => null, 'after' => Str::limit($body, 200)],
        ];

        $warnings = [];
        if ($order->customer === null) {
            $warnings[] = 'Pelanggan pada tiket ini tidak memiliki akun portal aktif; pesan akan tersimpan di log tiket tanpa notifikasi portal.';
        } else {
            $warnings[] = "Pelanggan {$order->customer_name} akan menerima notifikasi pesan baru pada tiket {$order->ticket_number}.";
        }
        if ($order->first_responded_at === null) {
            $warnings[] = 'Pengiriman pesan ini juga akan mencatat respons pertama SLA.';
        }

        return [
            'title' => 'Pratinjau Pengiriman Follow-up Pelanggan',
            'target' => "{$order->ticket_number} — {$order->customer_name}",
            'proposed_changes' => $changes,
            'financial_impact' => null,
            'warnings' => $warnings,
            'summary_text' => "Saya telah menyiapkan pesan follow-up untuk {$order->customer_name} pada tiket {$order->ticket_number}. Periksa isi pesan di bawah lalu tekan Konfirmasi untuk mengirim.",
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
        $order = ServiceOrder::query()->with('customer')->findOrFail((string) $normalizedPayload['service_order_id']);
        $body = (string) $normalizedPayload['body'];

        $message = $this->sendOrderMessage->handle($order, $actor, $body);

        $recipients = $order->customer instanceof User ? [$order->customer] : [];
        if ($recipients !== []) {
            $this->dispatcher->notifyUsers(
                $recipients,
                'order.message_received',
                "Pesan baru di tiket {$order->ticket_number}",
                "{$actor->name}: ".mb_strimwidth($body, 0, 120, '...'),
                route('orders.show', $order),
                ['ticket_number' => $order->ticket_number],
                false,
            );
        }

        return [
            'reference' => $order->ticket_number,
            'summary' => [
                'order_id' => $order->id,
                'ticket_number' => $order->ticket_number,
                'order_message_id' => $message->id,
                'recipient_id' => $message->recipient_id,
            ],
            'reply' => "Pesan follow-up untuk pelanggan pada tiket {$order->ticket_number} berhasil dikirim.",
            'actions' => [
                ['label' => "Buka Percakapan {$order->ticket_number}", 'href' => route('orders.show', $order)],
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
